<?php

namespace App\Services\AI;

use Google\Cloud\TextToSpeech\V1\Client\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\SsmlVoiceGender;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SynthesizeSpeechRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TextToSpeechService
{
    private TextToSpeechClient $ttsClient;

    public function __construct()
    {
        // JSON da service-account
        $credentials = storage_path('app/dubbox-24606f835eb4.json');

        try {
            $this->ttsClient = new TextToSpeechClient([
                'credentials' => $credentials,
            ]);
        } catch (Throwable $e) {
            Log::critical('Falha ao inicializar TextToSpeechClient', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Converte texto em fala, salva o MP3 no Spaces/S3 e devolve a URL pública.
     */
    public function synthesize(string $text, string $conversationId): ?string
    {
        try {
            $cleanText = $this->removeEmojis($text);
            $input = (new SynthesisInput())
                ->setText($cleanText);

            $voice = (new VoiceSelectionParams())
                ->setLanguageCode('pt-BR')
                ->setName('pt-BR-Wavenet-B')               // voz feminina Wavenet
                ->setSsmlGender(SsmlVoiceGender::FEMALE);  // opcional

            $audioCfg = (new AudioConfig())
                ->setAudioEncoding(AudioEncoding::MP3);

            /* --------- cria o request completo --------- */
            $request = (new SynthesizeSpeechRequest())
                ->setInput($input)
                ->setVoice($voice)
                ->setAudioConfig($audioCfg);

            /* --------- chama a API --------- */
            $response = $this->ttsClient->synthesizeSpeech($request);
            $mp3      = $response->getAudioContent();

            /* --------- salva no Spaces/S3 --------- */
            $fileName = Str::uuid() . '.mp3';
            $path     = "audio_responses/{$conversationId}/{$fileName}";

            Storage::disk('s3')->put($path, $mp3, 'public');
            $url = Storage::disk('s3')->url($path);

            Log::info('TTS gerado com sucesso', ['url' => $url]);
            return $url;
        } catch (Throwable $e) {
            Log::error('Erro no TTS', ['error' => $e->getMessage()]);
            return null;
        } finally {
            $this->ttsClient->close();
        }
    }

    /**
     * Remove caracteres emoji de uma string de texto.
     */
    private function removeEmojis(string $text): string
    {
        // Regex abrangente para remover a maioria dos emojis e símbolos Unicode.
        $regex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2B50}]/u';
        return preg_replace($regex, '', $text);
    }
}
