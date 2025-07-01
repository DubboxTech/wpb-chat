<?php

namespace App\Services\AI;

use Google\Cloud\Speech\V2\Client\SpeechClient;
use Google\Cloud\Speech\V2\RecognitionConfig;
use Google\Cloud\Speech\V2\ExplicitDecodingConfig;
use Google\Cloud\Speech\V2\ExplicitDecodingConfig\AudioEncoding;
use Google\Cloud\Speech\V2\RecognizeRequest;
use Google\Protobuf\FieldMask;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranscriptionService
{
    private SpeechClient $speechClient;
    private string       $recognizerPath;

    public function __construct()
    {
        try {
            // Caminho absoluto para o JSON da service-account
            $credentialsPath = storage_path('app/dubbox-24606f835eb4.json');

            // Extrai automaticamente o project_id do JSON
            $creds     = json_decode(file_get_contents($credentialsPath), true, 512, JSON_THROW_ON_ERROR);
            $projectId = $creds['project_id'] ?? throw new \RuntimeException(
                'project_id não encontrado no JSON de credenciais'
            );

            // Região padrão (pode ajustar via .env)
            $location = env('GOOGLE_CLOUD_LOCATION', 'global');

            $this->speechClient = new SpeechClient([
                'credentials' => $credentialsPath,
            ]);

            // Reconhecedor implícito “_”
            $this->recognizerPath = SpeechClient::recognizerName($projectId, $location, '_');
        } catch (Throwable $e) {
            Log::critical('Falha ao inicializar SpeechClient', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Transcreve um arquivo OGG/Opus 16 kHz (≤ 60 s).
     *
     * @param  string $s3Url
     * @return string|null
     */
    public function transcribe(string $s3Url): ?string
    {
        try {
            $audioContent = file_get_contents($s3Url);
            if ($audioContent === false) {
                throw new \RuntimeException("Não foi possível ler o áudio: {$s3Url}");
            }

            /* ---------- Configuração de decodificação ---------- */
            $decoding = new ExplicitDecodingConfig([
                'encoding'          => AudioEncoding::OGG_OPUS,
                'sample_rate_hertz' => 16_000,
                'audio_channel_count' => 1, 
            ]);

            $config = new RecognitionConfig([
                'explicit_decoding_config' => $decoding,
                'language_codes'           => ['pt-BR'],
                'model' => 'latest_short',
            ]);

            /* ---------- Monta a requisição ---------- */
            $request = new RecognizeRequest([
                'recognizer'  => $this->recognizerPath,
                'config'      => $config,
                'content'     => $audioContent,
            ]);

            $response = $this->speechClient->recognize(request: $request);

            /* ---------- Extrai a transcrição ---------- */
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
                $this->speechClient->close(); // Libera recursos gRPC/REST
            }
        }
    }
}
