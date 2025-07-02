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
        foreach ($this->payload['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] !== 'messages') continue;
                
                $value = $change['value'];
                $account = WhatsAppAccount::where('phone_number_id', $value['metadata']['phone_number_id'])->first();
                if (!$account) {
                    Log::warning('WhatsApp Account not found in webhook.', ['phone_id' => $value['metadata']['phone_number_id']]);
                    continue;
                }

                if (isset($value['messages'])) {
                    $this->processIncomingMessage($account, $value['messages'][0], $value['contacts'][0] ?? []);
                }
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

        [$conversation, $is_new] = $this->getOrCreateConversation($account, $contact);
        
        $message = $conversation->messages()->create([
            'contact_id' => $contact->id,
            'message_id' => Str::uuid(),
            'whatsapp_message_id' => $messageData['id'],
            'direction' => 'inbound',
            'type' => $messageData['type'],
            'status' => 'delivered',
            'content' => $this->extractMessageContent($messageData, $messageData['type']),
            'media' => $this->extractMediaData($messageData, $messageData['type']),
            'metadata' => $messageData,
            'created_at' => now()->createFromTimestamp($messageData['timestamp']),
        ]);

        $conversation->update(['last_message_at' => $message->created_at, 'unread_count' => $conversation->unread_count + 1]);
        
        event(new WhatsAppMessageReceived($message, $is_new));
    }

    private function extractMessageContent(array $data, string $type): ?string
    {
        return match ($type) {
            'text' => $data['text']['body'] ?? null,
            'image', 'video', 'document' => $data[$type]['caption'] ?? null,
            'location' => "{$data['location']['latitude']},{$data['location']['longitude']}",
            default => null,
        };
    }

    private function extractMediaData(array $data, string $type): ?array
    {
        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];
        if (!in_array($type, $mediaTypes)) return null;
        $mediaData = $data[$type];
        return ['id' => $mediaData['id'] ?? null, 'mime_type' => $mediaData['mime_type'] ?? null];
    }
    
    /**
     * MÉTODO ATUALIZADO: Lógica mais clara para identificar uma nova conversa.
     */
    protected function getOrCreateConversation(WhatsAppAccount $account, WhatsAppContact $contact): array
    {
        $activeConversation = WhatsAppConversation::where('contact_id', $contact->id)
            ->where('whatsapp_account_id', $account->id)
            ->whereIn('status', ['open', 'pending']) // Apenas conversas ativas
            ->where('updated_at', '>=', now()->subHours(6)) // Dentro da janela de 6 horas
            ->first();

        if ($activeConversation) {
            return [$activeConversation, false]; // Encontrou uma conversa ativa, não é nova.
        }

        // Se não encontrou conversa ativa, cria uma nova.
        $newConversation = WhatsAppConversation::create([
            'conversation_id' => Str::uuid(),
            'whatsapp_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'is_ai_handled' => true,
            'chatbot_state' => null,
        ]);
        
        return [$newConversation, true]; // É uma conversa nova, precisa de boas-vindas.
    }

    protected function processMessageStatus(array $statusData): void
    {
        $message = WhatsAppMessage::where('whatsapp_message_id', $statusData['id'])->first();
        if (!$message) return;
        $message->update(['status' => $statusData['status']]);
        event(new WhatsAppMessageStatusUpdated($message, $statusData['status']));
    }
}