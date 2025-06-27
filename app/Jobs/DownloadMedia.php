<?php

namespace App\Jobs;

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

class DownloadMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageId;
    protected $mediaId;
    protected $accountId;

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

        if (!$message || !$account) {
            Log::error('Could not find message or account for media download.', ['message_id' => $this->messageId, 'account_id' => $this->accountId]);
            return;
        }

        try {
            // 1. Define a conta correta no serviço
            $whatsappService->setAccount($account);
            
            // 2. Obtém a URL de download temporária da Meta
            $mediaInfo = $whatsappService->getMediaInfo($this->mediaId);
            if (!$mediaInfo || !isset($mediaInfo['url'])) {
                throw new \Exception('Could not retrieve media URL from Meta.');
            }
            $mediaUrl = $mediaInfo['url'];

            // 3. Baixa o conteúdo da mídia
            $response = Http::withToken($account->access_token)->get($mediaUrl);
            if ($response->failed()) {
                throw new \Exception('Failed to download media content from Meta URL.');
            }
            $fileContent = $response->body();

            // 4. Salva o arquivo no S3 (Digital Ocean Spaces)
            $fileExtension = $this->getExtensionFromMimeType($mediaInfo['mime_type']);
            $filePath = "media/{$message->conversation->conversation_id}/{$this->mediaId}.{$fileExtension}";
            
            Storage::disk('s3')->put($filePath, $fileContent, 'public');

            // 5. Obtém a URL pública e salva no banco de dados
            $s3Url = Storage::disk('s3')->url($filePath);
            
            $mediaData = $message->media;
            $mediaData['url'] = $s3Url; // Adiciona a URL ao JSON
            $message->media = $mediaData;
            $message->save();

            Log::info('Media downloaded and stored successfully.', ['message_id' => $this->messageId, 's3_url' => $s3Url]);

        } catch (\Exception $e) {
            Log::error('Failed to download media.', [
                'message_id' => $this->messageId,
                'media_id' => $this->mediaId,
                'error' => $e->getMessage()
            ]);
            $this->fail($e); // Marca o job como falho para possível nova tentativa
        }
    }

    private function getExtensionFromMimeType(string $mimeType): string
    {
        $parts = explode(';', $mimeType);
        $mime = $parts[0];
        
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/aac' => 'aac',
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/amr' => 'amr',
            'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
