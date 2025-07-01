<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\AI\TranscriptionService;
use App\Services\Chatbot\StatefulChatbotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranscribeAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    protected int $messageId;
    protected string $mimeType; // Novo

    public function __construct(int $messageId, string $mimeType)
    {
        $this->messageId = $messageId;
        $this->mimeType = $mimeType; // Novo
    }

    public function handle(TranscriptionService $transcriptionService, StatefulChatbotService $chatbotService): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (!$message || $message->type !== 'audio' || empty($message->media['url'])) {
            Log::warning('Transcription job skipped: message invalid.', ['message_id' => $this->messageId]);
            return;
        }
        
        // Log "Handling..." foi movido para cá para ser mais preciso
        Log::info('Handling WhatsApp message with AI', ['message_id' => $message->id, 'conversation_id' => $message->conversation_id]);

        try {
            // Passa a URL e o mime_type para o serviço
            $transcribedText = $transcriptionService->transcribe($message->media['url'], $this->mimeType);

            if ($transcribedText) {
                $message->content = $transcribedText;
                $message->save();
                Log::info('Audio transcribed, now processing with chatbot.', ['message_id' => $this->messageId]);
                $chatbotService->handle($message->conversation, $message);
            } else {
                Log::warning('Transcription returned empty.', ['message_id' => $this->messageId]);
                $chatbotService->handleGenericMedia($message->conversation, 'audio');
            }
        } catch (\Exception $e) {
            Log::error('Transcription job failed.', ['message_id' => $this->messageId, 'error' => $e->getMessage()]);
            $this->fail($e);
        }
    }
}