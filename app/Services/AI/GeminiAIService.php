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

    /**
     * **NOVO MÉTODO**
     * Usa o Gemini para identificar a intenção principal do usuário.
     */
    public function getIntent(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);
        $prompt = $this->buildIntentPrompt($userMessage, $context);
        
        // Usamos um modelo mais rápido para classificação de intenção
        return $this->sendRequestToGemini($prompt, 'gemini-2.0-flash-lite', 0.2, 100);
    }

    /**
     * Processa uma pergunta geral do usuário para obter uma resposta em texto.
     */
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

    private function sendRequestToGemini(array|string $promptContents, string $model = 'gemini-1.5-pro', float $temperature = 0.7, int $maxTokens = 1024): ?array
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
        $recentMessages = $conversation->messages()->latest()->limit(8)->get()->reverse();
        $context = "Histórico da conversa:\n";
        foreach ($recentMessages as $msg) {
            $prefix = $msg->direction === 'inbound' ? "Usuário" : "Assistente";
            $content = $msg->content ?? "[Mídia: {$msg->type}]";
            $context .= "{$prefix}: {$content}\n";
        }
        return $context;
    }
    
    /**
     * **NOVO PROMPT**
     * Instrução para o Gemini agir como um classificador de intenção.
     */
    private function buildIntentPrompt(string $userMessage, string $context): string
    {
        return "Você é um assistente de IA que classifica a intenção do usuário.
Ações disponíveis:
- agendar_cras: Usuário quer agendar, reagendar, encontrar ou ir para um CRAS.
- consultar_beneficio: Usuário quer saber sobre o status de um benefício (Bolsa Família, BPC). Inclui perguntas como 'consultar meu benefício'.
- atualizar_cadastro: Usuário menciona atualizar, mudar ou corrigir seus dados cadastrais.
- informacoes_gerais: Usuário faz uma pergunta geral sobre programas, serviços, ou pede detalhes.
- transferir_atendente: Usuário pede explicitamente para falar com uma pessoa, atendente ou humano.
- saudacao_despedida: É uma saudação ('oi', 'bom dia'), despedida ('tchau', 'obrigado') ou uma resposta afirmativa/negativa simples ('sim', 'não', 'ok') que não se encaixa em outra intenção.
- nao_entendido: A intenção não é clara ou não se encaixa em nenhuma das opções acima.

Baseado no histórico e na mensagem atual, retorne APENAS a ação correspondente.

{$context}
Mensagem atual do usuário: \"{$userMessage}\"

Ação:";
    }

    private function buildTextResponsePrompt(string $userMessage, string $context): string
    {
        return "Você é o 'SIM Social', um assistente virtual amigável e prestativo da Secretaria de Desenvolvimento Social (SEDES-DF).
Sua personalidade: você é empático, claro e direto. Use emojis para um tom mais humano.
Seu objetivo: responder perguntas gerais sobre os serviços da SEDES-DF.

**Contexto da Conversa:**
{$context}

**Pergunta atual do usuário:** \"{$userMessage}\"

Responda a pergunta do usuário de forma concisa e útil. Se a pergunta for sobre algo que você não sabe, peça desculpas e ofereça para transferir para um atendente.";
    }
}