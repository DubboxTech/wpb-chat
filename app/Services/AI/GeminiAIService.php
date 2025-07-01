<?php

namespace App\Services\AI;

use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiAIService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    public function processMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('Gemini API key is not configured.');
            return null;
        }

        try {
            $context = $this->buildConversationContext($conversation);
            $prompt = $this->buildPrompt($userMessage, $context);
            
            $url = "{$this->baseUrl}/models/gemini-1.5-flash:generateContent?key={$this->apiKey}";

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 256, // Reduzido para forçar respostas menores
                    ]
                ]);

            if ($response->successful() && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])) {
                $data = $response->json();
                $aiResponse = $data['candidates'][0]['content']['parts'][0]['text'];
                
                return ['success' => true, 'response' => trim($aiResponse), 'raw_response' => $data];
            }

            Log::error('Gemini API error', ['status' => $response->status(), 'response' => $response->body()]);
            return null;

        } catch (Exception $e) {
            Log::error('Exception in GeminiAIService: ' . $e->getMessage(), ['conversation_id' => $conversation->id]);
            return null;
        }
    }

    protected function buildConversationContext(WhatsAppConversation $conversation): string
    {
        $recentMessages = $conversation->messages()->latest()->limit(6)->get()->reverse();
        $context = "Histórico recente da conversa:\n";
        foreach ($recentMessages as $msg) {
            $prefix = $msg->direction === 'inbound' ? "Usuário" : "Assistente";
            $context .= "{$prefix}: {$msg->content}\n";
        }
        return $context;
    }

    protected function buildPrompt(string $userMessage, string $context): string
    {
        $businessInfo = $this->getBusinessInfo();
        
        return "Você é o 'SIM Social', um assistente virtual da {$businessInfo['name']}.
Sua personalidade: você é proativo, amigável e resolve problemas de forma direta.

**Diretrizes de Resposta CRÍTICAS:**
- **MANTENHA AS RESPOSTAS CURTAS E DIRETAS!** Use no máximo 2 ou 3 frases.
- Use emojis para manter um tom amigável.
- Seu papel é apenas responder a **perguntas gerais** sobre programas sociais (Bolsa Família, CRAS, etc.).
- **NÃO** dê instruções como 'acesse o site' ou 'ligue para um número'. A lógica do chatbot já cuida disso.
- Se não souber a resposta, diga algo como 'Puxa, essa informação eu não tenho. Quer que eu te transfira para um atendente?'.

{$context}

Pergunta atual do usuário: {$userMessage}

Sua resposta (curta, direta e amigável):";
    }

    protected function getBusinessInfo(): array
    {
        return [
            'name' => 'Secretaria de Desenvolvimento Social do DF',
        ];
    }
}