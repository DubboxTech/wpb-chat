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
        $value = $change['value'];
        $account = WhatsAppAccount::where('phone_number_id', $value['metadata']['phone_number_id'])->first();
        if (!$account) {
            Log::warning('WhatsApp Account not found in webhook.', ['phone_id' => $value['metadata']['phone_number_id']]);
            return;
        }

        if (isset($value['messages'])) {
            foreach ($value['messages'] as $messageData) {
                // **CORREÇÃO AQUI**: Removida a verificação `if ($messageData['type'] === 'text')`.
                // Agora, todas as mensagens são processadas.
                $this->processIncomingMessage($account, $messageData, $value['contacts'][0] ?? []);
            }
        }
        
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $statusData) {
                $this->processMessageStatus($statusData);
            }
        }
    }

    protected function processIncomingMessage(WhatsAppAccount $account, array $messageData, array $contactData): void
    {
        if (WhatsAppMessage::where('whatsapp_message_id', $messageData['id'])->exists()) {
            return;
        }

        $contact = WhatsAppContact::updateOrCreate(
            ['phone_number' => $messageData['from']],
            ['name' => $contactData['profile']['name'] ?? $messageData['from']]
        );

        $conversation = $this->getOrCreateConversation($account, $contact);
        
        $messageType = $messageData['type'];
        $content = $this->extractMessageContent($messageData, $messageType);
        $media = $this->extractMediaData($messageData, $messageType);

        $message = $conversation->messages()->create([
            'contact_id' => $contact->id,
            'message_id' => Str::uuid(),
            'whatsapp_message_id' => $messageData['id'],
            'direction' => 'inbound',
            'type' => $messageType,
            'status' => 'delivered',
            'content' => $content,
            'media' => $media,
            'metadata' => $messageData,
            'created_at' => now()->createFromTimestamp($messageData['timestamp']),
        ]);

        if ($media && isset($media['id'])) {
            // A fila 'downloads' pode ser usada para priorizar esses jobs
            DownloadMedia::dispatch($message->id, $media['id'], $account->id)->onQueue('downloads');
        }

        $conversation->update(['last_message_at' => $message->created_at, 'unread_count' => $conversation->unread_count + 1]);
        
        event(new WhatsAppMessageReceived($message));
    }

    private function extractMessageContent(array $data, string $type): ?string
    {
        return match ($type) {
            'text' => $data['text']['body'] ?? null,
            'image', 'video', 'document' => $data[$type]['caption'] ?? null,
            'location' => "{$data['location']['latitude']},{$data['location']['longitude']}",
            'sticker', 'audio' => null, // O conteúdo é a própria mídia
            'unsupported' => 'O usuário enviou um tipo de mensagem não suportado.',
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
            if($conversation->chatbot_state !== 'general_conversation') {
                 $conversation->update(['chatbot_state' => 'general_conversation']);
            }
            return $conversation;
        }

        return WhatsAppConversation::create([
            'conversation_id' => Str::uuid(),
            'whatsapp_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'is_ai_handled' => true,
            'chatbot_state' => 'new_conversation',
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