<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Events\WhatsAppMessageReceived;
use App\Events\WhatsAppMessageStatusUpdated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\ProcessWebhook;

class WhatsAppWebhookService
{
    /**
     * Process incoming webhook data.
     */
    public function processWebhook(array $data): array
    {
        try {
            if (!isset($data['entry'])) {
                Log::warning('Webhook received with invalid data format.', $data);
                return ['success' => false, 'message' => 'Invalid webhook data'];
            }

            // Dispatch job to process the webhook in the background
            ProcessWebhook::dispatch($data);

            return ['success' => true, 'message' => 'Webhook queued for processing.'];

        } catch (\Exception $e) {
            Log::error('Failed to dispatch webhook job: ' . $e->getMessage(), [
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);

            return ['success' => false, 'message' => 'Failed to queue webhook.'];
        }
    }

    /**
     * Process individual change from webhook.
     */
    protected function processChange(array $change): void
    {
        if ($change['field'] !== 'messages') {
            return;
        }

        $value = $change['value'];
        $phoneNumberId = $value['metadata']['phone_number_id'];

        // Find the WhatsApp account
        $account = WhatsAppAccount::where('phone_number_id', $phoneNumberId)->first();
        if (!$account) {
            Log::warning('WhatsApp account not found for phone number ID: ' . $phoneNumberId);
            return;
        }

        // Process messages
        if (isset($value['messages'])) {
            foreach ($value['messages'] as $messageData) {
                $this->processIncomingMessage($account, $messageData);
            }
        }

        // Process message statuses
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $statusData) {
                $this->processMessageStatus($account, $statusData);
            }
        }

        // Process contacts
        if (isset($value['contacts'])) {
            foreach ($value['contacts'] as $contactData) {
                $this->processContact($contactData);
            }
        }
    }

    /**
     * Process incoming message.
     */
    protected function processIncomingMessage(WhatsAppAccount $account, array $messageData): void
    {
        $whatsappMessageId = $messageData['id'];
        $from = $messageData['from'];
        $timestamp = $messageData['timestamp'];
        $type = $messageData['type'];

        // Check if message already exists
        if (WhatsAppMessage::where('whatsapp_message_id', $whatsappMessageId)->exists()) {
            return;
        }

        // Get or create contact
        $contact = $this->getOrCreateContact($from, $messageData);

        // Get or create conversation
        $conversation = $this->getOrCreateConversation($account, $contact);

        // Extract message content based on type
        $content = $this->extractMessageContent($messageData, $type);
        $media = $this->extractMediaData($messageData, $type);

        // Create message record
        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'message_id' => Str::uuid(),
            'whatsapp_message_id' => $whatsappMessageId,
            'direction' => 'inbound',
            'type' => $type,
            'status' => 'delivered',
            'content' => $content,
            'media' => $media,
            'metadata' => $messageData,
            'created_at' => now()->createFromTimestamp($timestamp),
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => $message->created_at,
            'unread_count' => $conversation->unread_count + 1,
            'status' => 'open',
        ]);

        // Update contact last seen
        $contact->update(['last_seen_at' => $message->created_at]);

        // Fire event
        event(new WhatsAppMessageReceived($message));

        Log::info('WhatsApp message received and processed', [
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'message_id' => $message->id,
            'type' => $type
        ]);
    }

    /**
     * Process message status update.
     */
    protected function processMessageStatus(WhatsAppAccount $account, array $statusData): void
    {
        $whatsappMessageId = $statusData['id'];
        $status = $statusData['status'];
        $timestamp = $statusData['timestamp'];

        $message = WhatsAppMessage::where('whatsapp_message_id', $whatsappMessageId)->first();
        if (!$message) {
            return;
        }

        $updateData = ['status' => $status];

        switch ($status) {
            case 'sent':
                $updateData['sent_at'] = now()->createFromTimestamp($timestamp);
                break;
            case 'delivered':
                $updateData['delivered_at'] = now()->createFromTimestamp($timestamp);
                break;
            case 'read':
                $updateData['read_at'] = now()->createFromTimestamp($timestamp);
                break;
            case 'failed':
                $updateData['error_message'] = $statusData['errors'][0]['title'] ?? 'Message failed';
                break;
        }

        $message->update($updateData);

        // Fire event
        event(new WhatsAppMessageStatusUpdated($message, $status));

        Log::info('WhatsApp message status updated', [
            'message_id' => $message->id,
            'status' => $status
        ]);
    }

    /**
     * Process contact information.
     */
    protected function processContact(array $contactData): void
    {
        $phoneNumber = $contactData['wa_id'];
        $profileName = $contactData['profile']['name'] ?? null;

        $contact = WhatsAppContact::where('phone_number', $phoneNumber)->first();
        if ($contact && $profileName) {
            $contact->update(['profile_name' => $profileName]);
        }
    }

    /**
     * Get or create contact.
     */
    protected function getOrCreateContact(string $phoneNumber, array $messageData): WhatsAppContact
    {
        $contact = WhatsAppContact::where('phone_number', $phoneNumber)->first();

        if (!$contact) {
            $contact = WhatsAppContact::create([
                'phone_number' => $phoneNumber,
                'whatsapp_id' => $phoneNumber,
                'profile_name' => $messageData['profile']['name'] ?? null,
                'status' => 'active',
                'last_seen_at' => now(),
            ]);
        }

        return $contact;
    }

    /**
     * Get or create conversation.
     */
    protected function getOrCreateConversation(WhatsAppAccount $account, WhatsAppContact $contact): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::where('whatsapp_account_id', $account->id)
            ->where('contact_id', $contact->id)
            ->where('status', '!=', 'closed')
            ->first();

        if (!$conversation) {
            $conversation = WhatsAppConversation::create([
                'whatsapp_account_id' => $account->id,
                'contact_id' => $contact->id,
                'conversation_id' => Str::uuid(),
                'status' => 'open',
                'priority' => 'normal',
                'unread_count' => 0,
            ]);
        }

        return $conversation;
    }

    /**
     * Extract message content based on type.
     */
    protected function extractMessageContent(array $messageData, string $type): ?string
    {
        switch ($type) {
            case 'text':
                return $messageData['text']['body'] ?? null;
            case 'image':
                return $messageData['image']['caption'] ?? null;
            case 'video':
                return $messageData['video']['caption'] ?? null;
            case 'document':
                return $messageData['document']['caption'] ?? $messageData['document']['filename'] ?? null;
            case 'audio':
                return 'Áudio recebido';
            case 'location':
                $location = $messageData['location'];
                return "Localização: {$location['latitude']}, {$location['longitude']}";
            case 'contact':
                $contact = $messageData['contacts'][0] ?? [];
                return "Contato: " . ($contact['name']['formatted_name'] ?? 'Sem nome');
            case 'interactive':
                if (isset($messageData['interactive']['button_reply'])) {
                    return $messageData['interactive']['button_reply']['title'];
                } elseif (isset($messageData['interactive']['list_reply'])) {
                    return $messageData['interactive']['list_reply']['title'];
                }
                return 'Mensagem interativa';
            default:
                return null;
        }
    }

    /**
     * Extract media data from message.
     */
    protected function extractMediaData(array $messageData, string $type): ?array
    {
        $mediaTypes = ['image', 'video', 'audio', 'document'];
        
        if (!in_array($type, $mediaTypes)) {
            return null;
        }

        $mediaData = $messageData[$type] ?? null;
        if (!$mediaData) {
            return null;
        }

        return [
            'id' => $mediaData['id'] ?? null,
            'mime_type' => $mediaData['mime_type'] ?? null,
            'sha256' => $mediaData['sha256'] ?? null,
            'filename' => $mediaData['filename'] ?? null,
            'caption' => $mediaData['caption'] ?? null,
        ];
    }

    /**
     * Verify webhook signature.
     */
    public function verifySignature(string $payload, string $signature, string $appSecret): bool
    {
        $calculatedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

        Log::info('Verifying WhatsApp Webhook Signature:', [
            'received_signature' => $signature,
            'calculated_signature' => $calculatedSignature,
            'app_secret_used' => substr($appSecret, 0, 5) . '...' . substr($appSecret, -5), // Mostra apenas partes do segredo por segurança
            'payload_length' => strlen($payload)
        ]);

        // Compara de forma segura as duas assinaturas
        return hash_equals($signature, $calculatedSignature);
    }
}

