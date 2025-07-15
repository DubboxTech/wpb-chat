<?php

namespace App\Listeners;

use App\Events\WhatsAppMessageReceived;
use App\Services\Chatbot\StatefulChatbotService;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessMessageWithAI implements ShouldQueue
{
    use InteractsWithQueue;

    protected StatefulChatbotService $chatbotService;
    protected WhatsAppBusinessService $whatsappService;

    public function __construct(
        StatefulChatbotService $chatbotService,
        WhatsAppBusinessService $whatsappService
    ) {
        $this->chatbotService = $chatbotService;
        $this->whatsappService = $whatsappService;
    }

    /**
     * Lida com o evento de nova mensagem.
     */
    public function handle(WhatsAppMessageReceived $event): void
    {
        $message = $event->message;
        $conversation = $message->conversation;

        // --- CORREÇÃO #1: CLÁUSULA DE GUARDA NO TOPO ---
        // Esta é a verificação MAIS IMPORTANTE. Ela precisa ser a primeira coisa no método.
        // Se a mensagem é uma resposta de Flow, este listener para imediatamente.
        if (
            $message->type === 'interactive'
        ) {
            Log::info('ProcessMessageWithAI listener is ignoring Flow reply as expected.');
            return; // Aborta a execução para respostas de Flow.
        }
        
        // As verificações existentes continuam a proteger os outros fluxos.
        if ($message->direction !== 'inbound' || !$conversation->is_ai_handled) {
            return;
        }

        try {
            if ($message->whatsapp_message_id) {
                $this->whatsappService->setAccount($conversation->whatsappAccount);
                $this->whatsappService->markAsRead($message->whatsapp_message_id);
                $this->whatsappService->sendTypingIndicator(
                    $conversation->contact->phone_number,
                    $message->whatsapp_message_id // Passa o ID da mensagem recebida
                );
            }
        } catch (\Exception $e) {
            Log::warning('Could not mark message as read. Continuing...', [
                'message_id' => $message->whatsapp_message_id,
                'error' => $e->getMessage()
            ]);
        }

        try {
            Log::info('Processing message with Stateful Chatbot Service', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            
            sleep(rand(1, 2));

            $this->chatbotService->handle($conversation, $message, $event->isNewConversation);

        } catch (\Exception $e) {
            Log::error('Stateful Chatbot processing failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Lida com falhas permanentes no job.
     */
    public function failed(WhatsAppMessageReceived $event, \Throwable $exception): void
    {
        Log::critical('ProcessMessageWithAI job failed permanently', [
            'message_id' => $event->message->id,
            'exception' => $exception->getMessage(),
        ]);

        $conversation = $event->message->conversation;
        if ($conversation) {
            $conversation->update([
                'status' => 'pending',
                'is_ai_handled' => false,
            ]);
        }
    }
}