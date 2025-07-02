<?php

namespace App\Services\Chatbot;

use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Log;

class ConversationManagerService
{
    protected StatefulChatbotService $chatbotService;

    public function __construct(StatefulChatbotService $chatbotService)
    {
        $this->chatbotService = $chatbotService;
    }

    /**
     * Encontra e fecha conversas abertas que estão inativas há mais de 5 minutos.
     */
    public function closeInactiveConversations(): void
    {
        Log::info('[ConversationManager] Running job to close inactive conversations.');

        // Encontra conversas abertas, geridas pela IA, e que não foram atualizadas nos últimos 5 minutos.
        $inactiveConversations = WhatsAppConversation::where('status', 'open')
            ->where('is_ai_handled', true)
            ->where('updated_at', '<', now()->subMinutes(5))
            ->with('messages') // Carrega as mensagens para evitar múltiplas queries
            ->get();

        if ($inactiveConversations->isEmpty()) {
            Log::info('[ConversationManager] No inactive conversations found.');
            return;
        }

        Log::info('[ConversationManager] Found ' . $inactiveConversations->count() . ' inactive conversations to close.');

        foreach ($inactiveConversations as $conversation) {
            // Verifica se a última mensagem foi do bot para evitar fechar logo após o utilizador responder.
            $lastMessage = $conversation->messages->last();
            if ($lastMessage && $lastMessage->direction === 'inbound') {
                continue; // Pula esta conversa se a última mensagem foi do utilizador.
            }

            // Determina se a resposta deve ser em áudio
            $respondWithAudio = $this->shouldRespondWithAudio($conversation);
            
            $closingMessage = "Olá! Parece que ficámos sem interagir por um tempo. Para manter tudo organizado, estou a encerrar esta conversa por agora. Se precisar de mais alguma coisa, é só chamar! 👋";
            
            // Envia a mensagem de encerramento
            $this->chatbotService->sendResponse($conversation, $closingMessage, $respondWithAudio);

            // Fecha a conversa
            $conversation->update(['status' => 'closed']);
            Log::info('[ConversationManager] Closed conversation.', ['conversation_id' => $conversation->id]);
        }
    }

    /**
     * Verifica a última mensagem do utilizador para decidir se a resposta deve ser em áudio.
     */
    private function shouldRespondWithAudio(WhatsAppConversation $conversation): bool
    {
        $lastUserMessage = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest()
            ->first();

        return $lastUserMessage && $lastUserMessage->type === 'audio';
    }
}
