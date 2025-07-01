<?php

namespace App\Jobs;

use App\Events\MediaMessageUpdated;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Jobs\TranscribeAudio;
use App\Jobs\AnalyzeDocumentWithGemini; // Importa o novo job

class DownloadMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    protected int $messageId;
    protected string $mediaId;
    protected int $accountId;

    public function __construct(int $messageId, string $mediaId, int $accountId)
    {
        $this->messageId = $messageId;
        $this->mediaId = $mediaId;
        $this->accountId = $accountId;
    }

    public function handle(WhatsAppBusinessService $whatsappService): void
    {
        $message = WhatsAppMessage::find($this->messageId);
        $account = WhatsAppAccount::find($this->accountId);

        if (!$message || !$account) { return; }

        try {
            $whatsappService->setAccount($account);
            $mediaInfo = $whatsappService->getMediaInfo($this->mediaId);
            if (!$mediaInfo || !isset($mediaInfo['url'])) { throw new \Exception('Could not retrieve media URL.'); }
            
            $response = Http::withToken($account->access_token)->get($mediaInfo['url']);
            if ($response->failed()) { throw new \Exception('Failed to download media content.'); }
            
            $fileContent = $response->body();
            $fileExtension = $this->getExtensionFromMimeType($mediaInfo['mime_type']);
            $filePath = "media/{$message->conversation->conversation_id}/{$this->mediaId}.{$fileExtension}";
            
            Storage::disk('s3')->put($filePath, $fileContent, 'public');
            $s3Url = Storage::disk('s3')->url($filePath);
            
            $mediaData = $message->media;
            $mediaData['url'] = $s3Url;
            $message->media = $mediaData;
            $message->save();

            event(new MediaMessageUpdated($message));
            Log::info('Media downloaded and event dispatched.', ['message_id' => $this->messageId]);

            // **LÓGICA ATUALIZADA**
            if ($message->type === 'audio') {
                TranscribeAudio::dispatch($this->messageId, $mediaInfo['mime_type'])->onQueue('transcriptions');
            } elseif ($message->type === 'document') {
                // Dispara o novo job para análise direta pelo Gemini
                AnalyzeDocumentWithGemini::dispatch($this->messageId)->onQueue('documents');
            }

        } catch (\Exception $e) {
            Log::error('Failed to download media.', ['message_id' => $this->messageId, 'error' => $e->getMessage()]);
            $this->fail($e);
        }
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $parts = explode(';', $mimeType);
        $mime = $parts[0];
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
            'audio/aac' => 'aac', 'audio/mp4' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/amr' => 'amr', 'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            default => 'bin',
        };
    }
}