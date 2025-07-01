<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\AI\GeminiAIService;
use App\Services\Chatbot\StatefulChatbotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // Importa o Storage

class AnalyzeDocumentWithGemini implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    protected int $messageId;

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    public function handle(GeminiAIService $geminiService, StatefulChatbotService $chatbotService): void
    {
        $message = WhatsAppMessage::find($this->messageId);

        if (!$message || $message->type !== 'document' || empty($message->media['url'])) {
            Log::warning('Document analysis job skipped: message invalid or has no URL.', ['message_id' => $this->messageId]);
            return;
        }

        try {
            $chatbotService->sendMessage(
                $message->conversation,
                "Recebi seu documento! Usando a IA para analisar o conteúdo, só um instante... 📄✨"
            );

            // **INÍCIO DA NOVA LÓGICA**
            // Extrai o caminho do arquivo no S3 a partir da URL completa
            $filePath = parse_url($message->media['url'], PHP_URL_PATH);
            // Remove a barra inicial se houver
            $filePath = ltrim($filePath, '/');

            // Lê o conteúdo bruto do arquivo do S3
            $fileContent = Storage::disk('s3')->get($filePath);
            if (!$fileContent) {
                throw new \Exception("Não foi possível ler o arquivo do S3: {$filePath}");
            }

            // Codifica o conteúdo em base64
            $fileContentBase64 = base64_encode($fileContent);
            // **FIM DA NOVA LÓGICA**

            $prompt = "Por favor, analise o documento que enviei. Resuma os pontos principais em uma lista curta e me diga qual a finalidade deste arquivo. Seja breve e direto.";
            
            // Chama o novo método que envia o conteúdo do arquivo
            $geminiResponse = $geminiService->analyzeDocumentFromContent(
                $fileContentBase64,
                $message->media['mime_type'],
                $prompt
            );

            if ($geminiResponse && !empty($geminiResponse['response'])) {
                $chatbotService->sendMessage($message->conversation, $geminiResponse['response']);
            } else {
                $chatbotService->sendMessage($message->conversation, "Consegui ler seu documento, mas não consegui analisá-lo no momento. Poderia me dizer do que se trata?");
            }

        } catch (\Exception $e) {
            Log::error('Document analysis job failed.', ['message_id' => $this->messageId, 'error' => $e->getMessage()]);
            $this->fail($e);
        } finally {
            $chatbotService->updateState($message->conversation, 'general_conversation');
        }
    }
}