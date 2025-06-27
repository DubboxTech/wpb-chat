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
// use App\Jobs\FetchProfilePicture; // Importa o Job de busca de foto

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
            if (isset($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    $this->processChange($change);
                }
            }
        }
    }

    protected function processChange(array $change): void
    {
        if ($change['field'] !== 'messages') {
            return;
        }

        $value = $change['value'];
        $phoneNumberId = $value['metadata']['phone_number_id'];
        $account = WhatsAppAccount::where('phone_number_id', $phoneNumberId)->first();
        
        if (!$account) {
            Log::warning('WhatsApp account not found for phone number ID: ' . $phoneNumberId);
            return;
        }

        $contactsData = $value['contacts'] ?? [];

        if (isset($value['messages'])) {
            foreach ($value['messages'] as $messageData) {
                $this->processIncomingMessage($account, $messageData, $contactsData);
            }
        }

        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $statusData) {
                $this->processMessageStatus($statusData);
            }
        }
    }

    protected function processIncomingMessage(WhatsAppAccount $account, array $messageData, array $contactsData): void
    {
        $whatsappMessageId = $messageData['id'];
        
        if (WhatsAppMessage::where('whatsapp_message_id', $whatsappMessageId)->exists()) {
            return;
        }

        $contactFromNumber = $messageData['from'];
        $contactProfile = null;
        foreach ($contactsData as $c) {
            if (isset($c['wa_id']) && $c['wa_id'] === $contactFromNumber) {
                $contactProfile = $c;
                break;
            }
        }
        
        $contact = $this->getOrCreateContact($contactFromNumber, $contactProfile);
        $conversation = $this->getOrCreateConversation($account, $contact);
        
        $messageType = $messageData['type'];
        $content = $this->extractMessageContent($messageData, $messageType);
        $mediaData = $this->extractMediaData($messageData, $messageType);
        $contextData = $messageData['context'] ?? null;

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'message_id' => Str::uuid(),
            'whatsapp_message_id' => $whatsappMessageId,
            'direction' => 'inbound',
            'type' => $messageType,
            'status' => 'delivered',
            'content' => $content,
            'media' => $mediaData,
            'metadata' => ['context' => $contextData],
            'created_at' => now()->createFromTimestamp($messageData['timestamp']),
        ]);
        
        if ($mediaData && isset($mediaData['id'])) {
            DownloadMedia::dispatch($message->id, $mediaData['id'], $account->id);
        }

        $conversation->update(['last_message_at' => $message->created_at, 'unread_count' => $conversation->unread_count + 1, 'status' => 'open']);
        $contact->update(['last_seen_at' => $message->created_at]);

        event(new WhatsAppMessageReceived($message));
    }

    protected function processMessageStatus(array $statusData): void
    {
        $message = WhatsAppMessage::where('whatsapp_message_id', $statusData['id'])->first();
        if (!$message) return;

        $updateData = ['status' => $statusData['status']];
        $timestamp = now()->createFromTimestamp($statusData['timestamp']);

        switch ($statusData['status']) {
            case 'sent': $updateData['sent_at'] = $timestamp; break;
            case 'delivered': $updateData['delivered_at'] = $timestamp; break;
            case 'read': $updateData['read_at'] = $timestamp; break;
            case 'failed': $updateData['error_message'] = $statusData['errors'][0]['title'] ?? 'Message failed'; break;
        }

        $message->update($updateData);
        event(new WhatsAppMessageStatusUpdated($message, $statusData['status']));
    }

    /**
     * Get or create a contact, ensuring the name is always updated.
     */
    protected function getOrCreateContact(string $phoneNumber, ?array $contactData): WhatsAppContact
    {
        $wa_id = $contactData['wa_id'] ?? $phoneNumber;
        $profileName = $contactData['profile']['name'] ?? $phoneNumber;

        // *** A CORREÇÃO ESTÁ AQUI: Usando updateOrCreate ***
        $contact = WhatsAppContact::updateOrCreate(
            ['phone_number' => $phoneNumber], // Atributo para buscar o contato
            [
                'whatsapp_id' => $wa_id,
                'name' => $profileName, // Sempre atualiza o nome com o dado mais recente
                'status' => 'active',
                'last_seen_at' => now(),
            ]
        );

        // Dispara a busca pela foto apenas se for um contato totalmente novo
        if ($contact->wasRecentlyCreated) {
            // Log::info('New contact. Dispatching job to fetch profile info.', ['contact_id' => $contact->id]);
            // FetchProfilePicture::dispatch($contact->id)->onQueue('default');
        }

        return $contact;
    }

    protected function getOrCreateConversation(WhatsAppAccount $account, WhatsAppContact $contact): WhatsAppConversation
    {
        return WhatsAppConversation::firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open'],
            [
                'conversation_id' => Str::uuid(),
                'priority' => 'normal',
                'is_ai_handled' => true,
            ]
        );
    }
    
    protected function extractMessageContent(array $data, string $type): ?string
    {
        return match ($type) {
            'text' => $data['text']['body'] ?? null,
            'image', 'video', 'document' => $data[$type]['caption'] ?? null,
            'location' => "Localização: {$data['location']['latitude']}, {$data['location']['longitude']}",
            'sticker' => "Sticker",
            'audio' => "Áudio",
            default => "Mensagem do tipo '$type' recebida",
        };
    }
    
    protected function extractMediaData(array $data, string $type): ?array
    {
        $mediaTypes = ['image', 'video', 'audio', 'document', 'sticker'];
        if (!in_array($type, $mediaTypes)) return null;

        $mediaData = $data[$type];
        return [
            'id' => $mediaData['id'],
            'mime_type' => $mediaData['mime_type'],
            'sha256' => $mediaData['sha256'] ?? null,
            'filename' => $mediaData['filename'] ?? null,
            'url' => null,
        ];
    }
}