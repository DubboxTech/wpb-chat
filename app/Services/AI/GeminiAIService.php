<?php

namespace App\Services\AI;

use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class GeminiAIService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    public function getIntent(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);
        $prompt = $this->buildIntentPrompt($userMessage, $context);
        return $this->sendRequestToGemini($prompt, 'gemini-2.0-flash-lite', 0.2, 100);
    }

    public function processMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);
        $prompt = $this->buildTextResponsePrompt($userMessage, $context);
        return $this->sendRequestToGemini($prompt, 'gemini-2.0-flash-lite', 0.7, 512);
    }

    public function analyzeDocumentFromContent(string $fileContentBase64, string $mimeType, string $promptText): ?array
    {
        $prompt = [[
            'role' => 'user',
            'parts' => [
                ['text' => $promptText],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $fileContentBase64]]
            ]
        ]];
        return $this->sendRequestToGemini($prompt);
    }

    private function sendRequestToGemini(array|string $promptContents, string $model = 'gemini-2.0-flash-lite', float $temperature = 0.7, int $maxTokens = 1024): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('Gemini API key is not configured.');
            return null;
        }
        $payload = [
            'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens]
        ];
        if (is_string($promptContents)) {
            $payload['contents'] = [['parts' => [['text' => $promptContents]]]];
        } else {
            $payload['contents'] = $promptContents;
        }
        try {
            $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";
            $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);
            if ($response->successful() && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])) {
                $data = $response->json();
                $aiResponse = $data['candidates'][0]['content']['parts'][0]['text'];
                return ['success' => true, 'response' => trim($aiResponse), 'raw_response' => $data];
            }
            Log::error('Gemini API error', ['status' => $response->status(), 'response' => $response->body()]);
            return null;
        } catch (Exception $e) {
            Log::error('Exception in GeminiAIService: ' . $e->getMessage());
            return null;
        }
    }

    private function buildConversationContext(WhatsAppConversation $conversation): string
    {
        $recentMessages = $conversation->messages()->latest()->limit(4)->get()->reverse();
        $context = "Histórico recente da conversa:\n";
        foreach ($recentMessages as $msg) {
            $prefix = $msg->direction === 'inbound' ? "Usuário" : "Assistente";
            $content = $msg->content ?? "[Mídia: {$msg->type}]";
            $context .= "{$prefix}: {$content}\n";
        }
        return $context;
    }

    private function getKnowledgeBaseContent(): string
    {
        return Cache::remember('sedes_knowledge_base', now()->addMinutes(60), function () {
            if (!Storage::disk('local')->exists('sedes.json')) {
                return '';
            }
            return Storage::disk('local')->get('sedes.json');
        });
    }
    
    /**
     * **PROMPT DE INTENÇÃO MODIFICADO**
     */
    private function buildIntentPrompt(string $userMessage, string $context): string
    {
        $knowledgeBase = $this->getKnowledgeBaseContent();

        return "Você é um assistente de IA que classifica a intenção do usuário.
Base de Conhecimento sobre os serviços:
---
{$knowledgeBase}
---

Ações disponíveis:
- agendar_cras: Usuário quer agendar, reagendar, encontrar ou ir para um CRAS.
- tentativa_de_cadastro: Usuário envia informações pessoais como CPF, endereço, CEP, nome completo, data de nascimento, etc., na tentativa de se cadastrar ou atualizar dados.
- informacoes_gerais: Usuário faz uma pergunta geral sobre programas, serviços ou pede detalhes contidos na base de conhecimento.
- saudacao_despedida: É uma saudação ('oi', 'bom dia'), despedida ('tchau', 'obrigado') ou uma resposta afirmativa/negativa simples ('sim', 'não', 'ok') que não se encaixa em outra intenção.
- nao_entendido: A intenção não é clara ou não se encaixa em nenhuma das opções acima.

Baseado na base de conhecimento, no histórico e na mensagem atual, retorne APENAS a ação correspondente.

{$context}
Mensagem atual do usuário: \"{$userMessage}\"

Ação:";
    }

    private function buildTextResponsePrompt(string $userMessage, string $context): string
    {
        $knowledgeBase = $this->getKnowledgeBaseContent();

        return "Você é o 'SIM Social', um assistente virtual amigável e especialista da Secretaria de Desenvolvimento Social (SEDES-DF).

Use a Base de Conhecimento abaixo para formular suas respostas. Seja sempre fiel às informações fornecidas.
Base de Conhecimento:
---
{$knowledgeBase}
---

Sua personalidade: você é empático, claro e direto. Use emojis para um tom mais humano.
Seu objetivo: responder perguntas gerais sobre os serviços da SEDES-DF usando a base de conhecimento.

**Contexto da Conversa:**
{$context}

**Pergunta atual do usuário:** \"{$userMessage}\"

Responda a pergunta do usuário de forma concisa e útil, baseando-se nas informações que você possui. Se a pergunta for sobre algo que não está na sua base de conhecimento, peça desculpas e ofereça para agendar um atendimento no CRAS.";
    }
}