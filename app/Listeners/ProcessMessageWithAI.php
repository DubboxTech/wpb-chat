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
        $isNewConversation = $event->isNewConversation; // <-- OBTÉM O NOVO DADO

        if ($message->type === 'audio' || $message->direction !== 'inbound' || !$conversation->is_ai_handled) {
            return;
        }

        try {
            // **LÓGICA CORRIGIDA**
            // Garante que temos um whatsapp_message_id para usar no contexto.
            if ($message->whatsapp_message_id) {
                // Define a conta e envia o indicador "typing_on" com o contexto correto.
                $this->whatsappService->setAccount($conversation->whatsappAccount);
                $this->whatsappService->sendTypingIndicator(
                    $conversation->contact->phone_number,
                    $message->whatsapp_message_id // Passa o ID da mensagem recebida
                );
            }

            Log::info('Processing message with Stateful Chatbot Service', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            
            // Pausa opcional para garantir que o indicador seja visível antes da resposta.
            sleep(rand(1, 2));

            $this->chatbotService->handle($conversation, $message, $isNewConversation);

        } catch (\Exception $e) {
            Log::error('Stateful Chatbot processing failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Lida com falhas no job.
     */
    public function failed(WhatsAppMessageReceived $event, \Throwable $exception): void
    {
        Log::critical('ProcessMessageWithAI job failed permanently', [
            'message_id' => $event->message->id,
            'exception' => $exception->getMessage(),
        ]);

        // Como último recurso, escala para um humano
        $conversation = $event->message->conversation;
        $conversation->update([
            'status' => 'pending',
            'is_ai_handled' => false,
        ]);
    }
}