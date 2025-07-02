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
use Illuminate\Support\Str;

class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        Log::info('[Webhook] Starting job processing.');
        try {
            if (empty($this->payload['entry'])) {
                Log::warning('[Webhook] Payload has no entries. Aborting.');
                return;
            }

            foreach ($this->payload['entry'] as $entryIndex => $entry) {
                Log::info("[Webhook] Processing entry #{$entryIndex}.");
                if (empty($entry['changes'])) {
                    continue;
                }
                foreach ($entry['changes'] as $changeIndex => $change) {
                    Log::info("[Webhook] Processing change #{$changeIndex} in entry #{$entryIndex}.");
                    if (($change['field'] ?? null) === 'messages') {
                        $this->processChange($change);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::critical('[Webhook] CRITICAL ERROR during job execution.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        Log::info('[Webhook] Finished job processing.');
    }

    protected function processChange(array $change): void
    {
        Log::info('[Webhook] processChange: Starting.');
        $value = $change['value'];
        $account = WhatsAppAccount::where('phone_number_id', $value['metadata']['phone_number_id'])->first();
        
        if (!$account) {
            Log::warning('[Webhook] processChange: WhatsApp Account not found.', ['phone_id' => $value['metadata']['phone_number_id']]);
            return;
        }
        Log::info('[Webhook] processChange: Account found.', ['account_id' => $account->id]);

        if (isset($value['messages'])) {
            Log::info('[Webhook] processChange: Found messages to process.', ['count' => count($value['messages'])]);
            foreach ($value['messages'] as $messageData) {
                $this->processIncomingMessage($account, $messageData, $value['contacts'][0] ?? []);
            }
        }
        
        if (isset($value['statuses'])) {
            Log::info('[Webhook] processChange: Found statuses to process.', ['count' => count($value['statuses'])]);
            foreach ($value['statuses'] as $statusData) {
                $this->processMessageStatus($statusData);
            }
        }
        Log::info('[Webhook] processChange: Finished.');
    }

    protected function processIncomingMessage(WhatsAppAccount $account, array $messageData, array $contactData): void
    {
        $waMessageId = $messageData['id'] ?? null;
        Log::info('[Webhook] processIncomingMessage: Starting.', ['wa_message_id' => $waMessageId]);

        if (!$waMessageId) {
            Log::warning('[Webhook] processIncomingMessage: Message has no ID. Skipping.');
            return;
        }

        if (WhatsAppMessage::where('whatsapp_message_id', $waMessageId)->exists()) {
            Log::info('[Webhook] processIncomingMessage: Duplicate message detected. Skipping.', ['wa_message_id' => $waMessageId]);
            return;
        }

        $contact = WhatsAppContact::updateOrCreate(
            ['phone_number' => $messageData['from']],
            ['name' => $contactData['profile']['name'] ?? $messageData['from']]
        );
        Log::info('[Webhook] processIncomingMessage: Contact found or created.', ['contact_id' => $contact->id]);

        $conversation = $this->getOrCreateConversation($account, $contact);
        Log::info('[Webhook] processIncomingMessage: Conversation found or created.', ['conversation_id' => $conversation->id]);
        
        $messageType = $messageData['type'];
        $content = $this->extractMessageContent($messageData, $messageType);
        $media = $this->extractMediaData($messageData, $messageType);

        $message = $conversation->messages()->create([
            'contact_id' => $contact->id,
            'message_id' => Str::uuid(),
            'whatsapp_message_id' => $waMessageId,
            'direction' => 'inbound',
            'type' => $messageType,
            'status' => 'delivered',
            'content' => $content,
            'media' => $media,
            'metadata' => $messageData,
            'created_at' => now()->createFromTimestamp($messageData['timestamp']),
        ]);
        Log::info('[Webhook] processIncomingMessage: Message saved to database.', ['message_id' => $message->id]);

        $conversation->update(['last_message_at' => $message->created_at, 'unread_count' => $conversation->unread_count + 1]);
        
        Log::info('[Webhook] processIncomingMessage: Dispatching WhatsAppMessageReceived event.');
        event(new WhatsAppMessageReceived($message));
        Log::info('[Webhook] processIncomingMessage: Finished.');
    }

    private function extractMessageContent(array $data, string $type): ?string
    {
        return match ($type) {
            'text' => $data['text']['body'] ?? null,
            'image', 'video', 'document' => $data[$type]['caption'] ?? null,
            'location' => "{$data['location']['latitude']},{$data['location']['longitude']}",
            'sticker', 'audio' => null,
            'unsupported' => 'O utilizador enviou um tipo de mensagem não suportado.',
            default => "Mensagem do tipo '{$type}' recebida.",
        };
    }

    private function extractMediaData(array $data, string $type): ?array
    {
        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];
        if (!in_array($type, $mediaTypes)) return null;

        $mediaData = $data[$type];
        return [
            'id' => $mediaData['id'] ?? null,
            'mime_type' => $mediaData['mime_type'] ?? null,
            'sha256' => $mediaData['sha256'] ?? null,
            'filename' => $mediaData['filename'] ?? null,
            'caption' => $mediaData['caption'] ?? null,
        ];
    }
    
    protected function getOrCreateConversation(WhatsAppAccount $account, WhatsAppContact $contact): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::where('whatsapp_account_id', $account->id)
            ->where('contact_id', $contact->id)
            ->where('status', '!=', 'closed')
            ->where('updated_at', '>=', now()->subHours(6))
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return WhatsAppConversation::create([
            'conversation_id' => Str::uuid(),
            'whatsapp_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'is_ai_handled' => true,
            'chatbot_state' => null,
        ]);
    }

    protected function processMessageStatus(array $statusData): void
    {
        $message = WhatsAppMessage::where('whatsapp_message_id', $statusData['id'])->first();
        if (!$message) return;
        $message->update(['status' => $statusData['status']]);
        event(new WhatsAppMessageStatusUpdated($message, $statusData['status']));
    }
}
