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
        // **INÍCIO DA CORREÇÃO**
        // Apenas executa a verificação de duplicados em ambiente de produção.
        if (app()->isProduction()) {
            if (WhatsAppMessage::where('whatsapp_message_id', $messageData['id'])->exists()) {
                Log::info('Skipping already processed message in production.', ['whatsapp_message_id' => $messageData['id']]);
                return;
            }
        }
        // **FIM DA CORREÇÃO**

        $contact = WhatsAppContact::updateOrCreate(
            ['phone_number' => $messageData['from']],
            ['name' => $contactData['profile']['name'] ?? $messageData['from'], 'tags' => ['producao']]
        );

        [$conversation, $is_new] = $this->getOrCreateConversation($account, $contact);
        
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
            Log::info('Dispatching job to download media.', ['message_id' => $message->id, 'media_id' => $media['id']]);
            DownloadMedia::dispatch($message->id, $media['id'], $account->id)->onQueue('default');
        }

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
        return [
            'id' => $mediaData['id'] ?? null,
            'mime_type' => $mediaData['mime_type'] ?? null,
        ];
    }
    
    protected function getOrCreateConversation(WhatsAppAccount $account, WhatsAppContact $contact): array
    {
        // 1. Tenta encontrar a conversa mais recente para o contato, independentemente do status.
        $latestConversation = WhatsAppConversation::where('contact_id', $contact->id)
            ->where('whatsapp_account_id', $account->id)
            ->latest('updated_at') // Ordena pela mais recente
            ->first();

        // 2. Se uma conversa existir
        if ($latestConversation) {
            // Se estiver fechada, reabre e reseta o estado.
            if ($latestConversation->status === 'closed') {
                $latestConversation->update([
                    'status' => 'open',
                    'is_ai_handled' => true, // Devolve para a IA
                    'chatbot_state' => null, // Limpa o estado do chatbot
                    'assigned_user_id' => null, // Remove o agente atribuído
                ]);
                Log::info('Reopening closed conversation.', ['conversation_id' => $latestConversation->id]);
                return [$latestConversation, true]; // Retorna como se fosse "nova" para o fluxo
            }
            
            // Se estiver aberta ou pendente, apenas a retorna.
            return [$latestConversation, false];
        }

        // 3. Se nenhuma conversa existir, cria uma nova.
        $newConversation = WhatsAppConversation::create([
            'conversation_id' => Str::uuid(),
            'whatsapp_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'is_ai_handled' => true,
            'chatbot_state' => null,
        ]);
        
        Log::info('Creating new conversation.', ['conversation_id' => $newConversation->id]);
        return [$newConversation, true];
    }

    protected function processMessageStatus(array $statusData): void
    {
        $message = WhatsAppMessage::where('whatsapp_message_id', $statusData['id'])->first();
        if (!$message) return;
        $message->update(['status' => $statusData['status']]);
        event(new WhatsAppMessageStatusUpdated($message, $statusData['status']));
    }
}