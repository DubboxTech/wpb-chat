<?php

namespace App\Jobs;

use App\Events\WhatsAppMessageReceived;
use App\Events\WhatsAppMessageStatusUpdated;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /* -----------------------------------------------------------------
       MANAGER
    ----------------------------------------------------------------- */

    public function handle(): void
    {
        Log::info('Processing webhook job.', ['payload' => $this->payload]);

        foreach ($this->payload['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'messages') {
                    $this->processChange($change);
                }
            }
        }
    }

    protected function processChange(array $change): void
    {
        $value   = $change['value'];
        $account = WhatsAppAccount::where('phone_number_id', $value['metadata']['phone_number_id'])->first();

        if (!$account) {
            Log::warning('WhatsApp Account not found.', [
                'phone_id' => $value['metadata']['phone_number_id'],
            ]);
            return;
        }

        // Mensagens
        if (isset($value['messages'])) {
            foreach ($value['messages'] as $messageData) {
                $this->processIncomingMessage($account, $messageData, $value['contacts'][0] ?? []);
            }
        }

        // Status
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $statusData) {
                $this->processMessageStatus($account, $statusData);
            }
        }
    }

    /* -----------------------------------------------------------------
       MENSAGENS ENTRANTES
    ----------------------------------------------------------------- */

    protected function processIncomingMessage(
        WhatsAppAccount $account,
        array           $messageData,
        array           $contactData
    ): void {
        // Evita duplicados nos servidores de produção
        if (app()->isProduction() &&
            WhatsAppMessage::where('whatsapp_message_id', $messageData['id'])->exists()) {
            Log::info('Skipping duplicated message.', ['whatsapp_message_id' => $messageData['id']]);
            return;
        }

        $contact = WhatsAppContact::updateOrCreate(
            ['phone_number' => $messageData['from']],
            ['name' => $contactData['profile']['name'] ?? $messageData['from']]
        );

        [$conversation, $isNew] = $this->getOrCreateConversation($account, $contact);

        $type    = $messageData['type'];
        $content = $this->extractMessageContent($messageData, $type);
        $media   = $this->extractMediaData($messageData, $type);

        $message = $conversation->messages()->create([
            'contact_id'          => $contact->id,
            'message_id'          => Str::uuid(),
            'whatsapp_message_id' => $messageData['id'],
            'direction'           => 'inbound',
            'type'                => $type,
            'status'              => 'delivered',
            'content'             => $content,
            'media'               => $media,
            'metadata'            => $messageData,
            'created_at'          => now()->createFromTimestamp($messageData['timestamp']),
        ]);

        // Salva o JSON bruto
        $this->saveRawPayload(
            $account,
            $contact->phone_number,
            $messageData['id'],
            $messageData
        );

        if ($media && isset($media['id'])) {
            Log::info('Dispatching media download.', [
                'message_id' => $message->id,
                'media_id'   => $media['id'],
            ]);
            DownloadMedia::dispatch($message->id, $media['id'], $account->id)->onQueue('default');
        }

        $conversation->update([
            'last_message_at' => $message->created_at,
            'unread_count'    => $conversation->unread_count + 1,
        ]);

        event(new WhatsAppMessageReceived($message, $isNew));
    }

    /* -----------------------------------------------------------------
       STATUS (delivered/read/etc.)
    ----------------------------------------------------------------- */

    protected function processMessageStatus(
        WhatsAppAccount $account,
        array           $statusData
    ): void {
        $message = WhatsAppMessage::where('whatsapp_message_id', $statusData['id'])->first();
        if (!$message) {
            return;
        }

        $message->update(['status' => $statusData['status']]);
        event(new WhatsAppMessageStatusUpdated($message, $statusData['status']));

        // Salva o status bruto
        $this->saveRawPayload(
            $account,
            $message->contact->phone_number ?? 'unknown_contact',
            "{$statusData['id']}_status",
            $statusData
        );
    }

    /* -----------------------------------------------------------------
       HELPERS
    ----------------------------------------------------------------- */

    private function extractMessageContent(array $data, string $type): ?string
    {
        return match ($type) {
            'text'                     => $data['text']['body']          ?? null,
            'image', 'video', 'document' => $data[$type]['caption']       ?? null,
            'location'                 => "{$data['location']['latitude']},{$data['location']['longitude']}",
            default                    => null,
        };
    }

    private function extractMediaData(array $data, string $type): ?array
    {
        if (!in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])) {
            return null;
        }

        $media = $data[$type];
        return [
            'id'        => $media['id']        ?? null,
            'mime_type' => $media['mime_type'] ?? null,
        ];
    }

    protected function getOrCreateConversation(
        WhatsAppAccount $account,
        WhatsAppContact $contact
    ): array {
        $latest = WhatsAppConversation::where('contact_id', $contact->id)
            ->where('whatsapp_account_id', $account->id)
            ->latest('updated_at')
            ->first();

        // Reabrir conversa fechada
        if ($latest) {
            if ($latest->status === 'closed') {
                $latest->update([
                    'status'           => 'open',
                    'is_ai_handled'    => true,
                    'chatbot_state'    => null,
                    'assigned_user_id' => null,
                ]);
                Log::info('Reopened conversation.', ['conversation_id' => $latest->id]);
                return [$latest, true];
            }

            return [$latest, false];
        }

        // Nova conversa
        $new = WhatsAppConversation::create([
            'conversation_id'     => Str::uuid(),
            'whatsapp_account_id' => $account->id,
            'contact_id'          => $contact->id,
            'status'              => 'open',
            'is_ai_handled'       => true,
            'chatbot_state'       => null,
        ]);

        Log::info('Created new conversation.', ['conversation_id' => $new->id]);
        return [$new, true];
    }

    /* -----------------------------------------------------------------
       GRAVAÇÃO DO PAYLOAD
    ----------------------------------------------------------------- */

    protected function saveRawPayload(
        WhatsAppAccount $account,
        string          $contactPhone,
        string          $messageId,
        array           $data
    ): void {
        try {
            $path = sprintf(
                'webhooks/whatsapp/%d/%s/%s/%s.json',
                $account->business_account_id,                 // account_id
                $account->phone_number_id,    // account_numerid
                $contactPhone,                // contactswa_id
                $messageId                    // messageid
            );

            Storage::disk('local')->makeDirectory(dirname($path));

            Storage::disk('local')->put(
                $path,
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            );

            Log::info('Raw payload stored.', ['file' => $path]);
        } catch (\Throwable $e) {
            Log::warning('Failed to store raw payload.', [
                'error'      => $e->getMessage(),
                'message_id' => $messageId,
            ]);
        }
    }
}
