<?php

namespace App\Services\AI;

use Google\Cloud\Speech\V2\Client\SpeechClient;
use Google\Cloud\Speech\V2\RecognitionConfig;
use Google\Cloud\Speech\V2\ExplicitDecodingConfig;
use Google\Cloud\Speech\V2\ExplicitDecodingConfig\AudioEncoding;
use Google\Cloud\Speech\V2\RecognizeRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranscriptionService
{
    private SpeechClient $speechClient;
    private string       $recognizerPath;

    public function __construct()
    {
        try {
            $credentialsPath = storage_path('app/dubbox-24606f835eb4.json');
            $creds     = json_decode(file_get_contents($credentialsPath), true, 512, JSON_THROW_ON_ERROR);
            $projectId = $creds['project_id'] ?? throw new \RuntimeException('project_id não encontrado no JSON');
            $location = env('GOOGLE_CLOUD_LOCATION', 'global');
            $this->speechClient = new SpeechClient(['credentials' => $credentialsPath]);
            $this->recognizerPath = SpeechClient::recognizerName($projectId, $location, '_');
        } catch (Throwable $e) {
            Log::critical('Falha ao inicializar SpeechClient', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Transcreve um arquivo de áudio.
     */
    public function transcribe(string $s3Url, string $mimeType): ?string
    {
        try {
            $audioContent = file_get_contents($s3Url);
            if ($audioContent === false) {
                throw new \RuntimeException("Não foi possível ler o áudio: {$s3Url}");
            }

            // **INÍCIO DA NOVA LÓGICA**
            $encoding = $this->mapMimeTypeToEncoding($mimeType);
            if ($encoding === null) {
                 Log::warning('Formato de áudio não suportado para transcrição.', ['mime_type' => $mimeType]);
                 return null;
            }
            // **FIM DA NOVA LÓGICA**

            $decoding = new ExplicitDecodingConfig([
                'encoding'          => $encoding,
                'sample_rate_hertz' => 16000,
                'audio_channel_count' => 1, 
            ]);

            $config = new RecognitionConfig([
                'explicit_decoding_config' => $decoding,
                'language_codes'           => ['pt-BR'],
                'model' => 'latest_short',
            ]);

            $request = new RecognizeRequest([
                'recognizer'  => $this->recognizerPath,
                'config'      => $config,
                'content'     => $audioContent,
            ]);

            $response = $this->speechClient->recognize(request: $request);

            $transcript = '';
            foreach ($response->getResults() as $result) {
                $alts = $result->getAlternatives();
                if ($alts && isset($alts[0])) {
                    $transcript .= $alts[0]->getTranscript();
                }
            }

            Log::info('Transcrição concluída', ['url' => $s3Url, 'text' => $transcript]);

            return $transcript ?: null;
        } catch (Throwable $e) {
            Log::error('Erro na transcrição', ['url' => $s3Url, 'error' => $e->getMessage()]);
            return null;
        } finally {
            if (isset($this->speechClient)) {
                $this->speechClient->close();
            }
        }
    }
    
    /**
     * **NOVA FUNÇÃO**
     * Mapeia o mime_type do WhatsApp para o formato de encoding da API do Google.
     */
    private function mapMimeTypeToEncoding(string $mimeType): ?int
    {
        // Limpa o mime type para pegar apenas a parte principal (ex: 'audio/ogg')
        $mainMimeType = explode(';', $mimeType)[0];

        return match ($mainMimeType) {
            'audio/ogg' => AudioEncoding::OGG_OPUS,
            'audio/amr' => AudioEncoding::AMR,
            'audio/amr-wb' => AudioEncoding::AMR_WB,
            'audio/flac' => AudioEncoding::FLAC,
            'audio/mp3', 'audio/mpeg' => AudioEncoding::MP3,
            'audio/wav', 'audio/x-wav' => AudioEncoding::LINEAR16, // WAV geralmente é LINEAR16
            // A API Speech-to-Text V2 não suporta AAC diretamente. 
            // Para isso, seria necessário converter para um formato suportado (ex: FLAC ou WAV) antes de enviar.
            // 'audio/aac', 'audio/mp4' => AudioEncoding::...
            default => null,
        };
    }
}