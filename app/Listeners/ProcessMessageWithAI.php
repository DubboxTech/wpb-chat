<?php

namespace App\Listeners;

use App\Events\WhatsAppMessageReceived;
use App\Services\Chatbot\StatefulChatbotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessMessageWithAI implements ShouldQueue
{
    use InteractsWithQueue;

    protected StatefulChatbotService $chatbotService;

    public function __construct(StatefulChatbotService $chatbotService)
    {
        $this->chatbotService = $chatbotService;
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
            Log::info('Processing message with Stateful Chatbot Service', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'is_new_conversation' => $isNewConversation, // Log para debugging
            ]);

            // **MUDANÇA AQUI**: Passa o terceiro argumento para o handle.
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