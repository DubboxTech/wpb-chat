<?php

namespace App\Listeners;

use App\Events\WhatsAppMessageReceived;
use App\Models\WhatsAppMessage;
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

    public function handle(WhatsAppMessageReceived $event): void
    {
        Log::info('[AI_Listener] Job started for message.', ['message_id' => $event->message->id]);

        $message = $event->message;
        
        // Condições para ignorar o processamento
        if ($message->direction !== 'inbound') {
            Log::info('[AI_Listener] Skipping: Message is not inbound.', ['message_id' => $message->id]);
            return;
        }
        if (in_array($message->type, ['audio', 'document'])) {
            Log::info('[AI_Listener] Skipping: Message is audio or document, handled by other jobs.', ['message_id' => $message->id]);
            return;
        }
        // if (!$message->conversation->is_ai_handled) {
        //     Log::info('[AI_Listener] Skipping: AI is disabled for this conversation.', ['conversation_id' => $message->conversation->id]);
        //     return;
        // }

        try {
            Log::info('[AI_Listener] Conditions passed. Processing with StatefulChatbotService.', ['message_id' => $message->id]);
            $this->chatbotService->handle($message->conversation, $message);
            Log::info('[AI_Listener] Finished processing.', ['message_id' => $message->id]);
        } catch (\Exception $e) {
            Log::error('[AI_Listener] CRITICAL ERROR during chatbot handling.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    public function failed(WhatsAppMessageReceived $event, \Throwable $exception): void
    {
        Log::critical('[AI_Listener] Job FAILED permanently after all retries.', [
            'message_id' => $event->message->id, 'exception' => $exception->getMessage(),
        ]);
        $conversation = $event->message->conversation;
        $conversation->update(['status' => 'pending', 'is_ai_handled' => true]);
    }
}
