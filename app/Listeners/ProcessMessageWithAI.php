<?php

namespace App\Listeners;

use App\Events\WhatsAppMessageReceived;
use App\Services\Chatbot\StatefulChatbotService; // Importa o novo serviço de chatbot
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Este listener é acionado para cada nova mensagem recebida.
 * Ele agora delega o processamento para o StatefulChatbotService.
 */
class ProcessMessageWithAI implements ShouldQueue
{
    use InteractsWithQueue;

    protected StatefulChatbotService $chatbotService;

    /**
     * Create the event listener.
     *
     * @param StatefulChatbotService $chatbotService
     */
    public function __construct(StatefulChatbotService $chatbotService)
    {
        $this->chatbotService = $chatbotService;
    }

    /**
     * Handle the event.
     *
     * @param WhatsAppMessageReceived $event
     * @return void
     */
    public function handle(WhatsAppMessageReceived $event): void
    {
        Log::info('Handling WhatsApp message with AI', [
            'message_id' => $event->message->id,
            'conversation_id' => $event->message->conversation_id,
        ]);
        
        $message = $event->message;
        $conversation = $message->conversation;

        // Processa apenas mensagens de texto recebidas (inbound)
        if ($message->direction !== 'inbound' || $message->type !== 'text') {
            return;
        }

        // Não processa se a conversa já estiver atribuída a um atendente humano
        if ($conversation->assigned_user_id) {
            return;
        }
        
        // Embora o nome do listener seja "WithAI", podemos adicionar uma verificação
        // para permitir um controle global de "ligar/desligar" o chatbot
        if (!$conversation->is_ai_handled) {
             Log::info('Chatbot processing skipped because is_ai_handled is false.', ['conversation_id' => $conversation->id]);
             return;
        }

        try {
            Log::info('Processing message with Stateful Chatbot', [
                'conversation_id' => $conversation->id,
                'current_state' => $conversation->chatbot_state,
                'input' => $message->content
            ]);

            // Chama o serviço principal que gerencia o fluxo da conversa
            $this->chatbotService->handle($conversation, $message->content);

        } catch (\Exception $e) {
            Log::error('Stateful Chatbot processing failed: ' . $e->getMessage(), [
                'conversation_id' => $conversation->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Aqui você pode adicionar uma lógica de fallback em caso de erro fatal,
            // como enviar uma mensagem padrão e escalar para um humano.
        }
    }

    /**
     * Handle a job failure.
     *
     * @param WhatsAppMessageReceived $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(WhatsAppMessageReceived $event, \Throwable $exception): void
    {
        Log::critical('ProcessMessageWithAI job failed permanently', [
            'message_id' => $event->message->id,
            'exception' => $exception->getMessage(),
        ]);

        // Como último recurso, escala para um humano se o job falhar repetidamente.
        $conversation = $event->message->conversation;
        $conversation->update([
            'status' => 'pending',
            'is_ai_handled' => false,
        ]);
    }
}
