<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\AI\TranscriptionService;
use App\Services\Chatbot\StatefulChatbotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    /**
     * Executa o job.
     */
    public function handle(TranscriptionService $transcriptionService, StatefulChatbotService $chatbotService): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (!$message || $message->type !== 'audio' || empty($message->media['url'])) {
            Log::warning('Transcription job skipped: message not found, not audio, or has no URL.', ['message_id' => $this->messageId]);
            return;
        }

        try {
            $transcribedText = $transcriptionService->transcribe($message->media['url']);

            if ($transcribedText) {
                // Salva a transcrição no campo de conteúdo da mensagem
                $message->content = $transcribedText;
                $message->save();
                Log::info('Audio transcribed, now processing with chatbot.', ['message_id' => $this->messageId]);

                // Chama o serviço de chatbot com a mensagem agora contendo o texto
                $chatbotService->handle($message->conversation, $message);
            } else {
                Log::warning('Transcription returned empty.', ['message_id' => $this->messageId]);
                // Responde ao usuário que não entendeu o áudio
                $chatbotService->handleGenericMedia($message->conversation, 'audio');
            }
        } catch (\Exception $e) {
            Log::error('Transcription job failed.', ['message_id' => $this->messageId, 'error' => $e->getMessage()]);
            $this->fail($e);
        }
    }
}