<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $apiKey;
    protected $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=' . $this->apiKey;
    }

    public function generateContent(string $prompt, array $history = []): array
    {
        $payload = [
            'contents' => [],
        ];

        foreach ($history as $message) {
            $payload['contents'][] = ['role' => $message['role'], 'parts' => [['text' => $message['text']]]];
        }

        $payload['contents'][] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

        try {
            $response = Http::post($this->apiUrl, $payload);
            $response->throw(); // Throw an exception for bad responses (4xx or 5xx)

            $data = $response->json();
            Log::info('Gemini API Response', ['data' => $data]);

            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $geminiResponse = $data['candidates'][0]['content']['parts'][0]['text'];
                // Attempt to parse JSON from the response
                $jsonStart = strpos($geminiResponse, '{');
                $jsonEnd = strrpos($geminiResponse, '}');

                if ($jsonStart !== false && $jsonEnd !== false) {
                    $jsonString = substr($geminiResponse, $jsonStart, $jsonEnd - $jsonStart + 1);
                    $parsedJson = json_decode($jsonString, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $parsedJson;
                    }
                }
                // If not a valid JSON, return as a simple text reply
                return ['reply' => $geminiResponse, 'next_state' => 'unknown', 'intent' => 'desconhecido', 'handoff' => false];
            }

            Log::warning('Gemini API did not return expected text content', ['response' => $data]);
            return ['reply' => 'Desculpe, não consegui gerar uma resposta significativa.', 'next_state' => 'unknown', 'intent' => 'desconhecido', 'handoff' => false];

        } catch (\Exception $e) {
            Log::error('Error communicating with Gemini API', ['error' => $e->getMessage()]);
            return ['reply' => 'Ocorreu um erro ao processar sua solicitação. Por favor, tente novamente mais tarde.', 'next_state' => 'error', 'intent' => 'desconhecido', 'handoff' => true];
        }
    }
}


