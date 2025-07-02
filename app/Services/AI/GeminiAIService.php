<?php

namespace App\Services\AI;

use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

/**
 * Serviço de integração com o Google Gemini para o chatbot SIM Social.
 * Agora envia **todo** o histórico de mensagens e **toda** a base de dados (sedes.json)
 * para o modelo, conforme solicitado.
 */
class GeminiAIService
{
    /** --------------------------------------------------------------------
     *  Configuração de API
     * -------------------------------------------------------------------*/
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    private ?string $sedesKnowledgeBase = null;

    /** --------------------------------------------------------------------
     *  Construtor
     * -------------------------------------------------------------------*/
    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key');
        $this->loadKnowledgeBase();
    }

    /** --------------------------------------------------------------------
     *  Carrega o arquivo sedes.json completo
     * -------------------------------------------------------------------*/
    private function loadKnowledgeBase(): void
    {
        try {
            if (Storage::disk('local')->exists('sedes.json')) {
                $this->sedesKnowledgeBase = Storage::disk('local')->get('sedes.json');
                Log::info('Base de conhecimento (sedes.json) carregada com sucesso.');
            } else {
                Log::warning('Arquivo de base de conhecimento (sedes.json) não encontrado.');
            }
        } catch (Exception $e) {
            Log::error('Falha ao carregar sedes.json.', ['error' => $e->getMessage()]);
        }
    }

    /** --------------------------------------------------------------------
     *  ANALYSE – identifica intenção do usuário
     * -------------------------------------------------------------------*/
    public function analyzeUserMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context     = $this->buildConversationContext($conversation);   // **todo o histórico**
        $prompt      = $this->buildAnalysisPrompt($userMessage, $context, $conversation->chatbot_state);
        Log::debug('Prompt de análise enviado ao Gemini.', ['prompt' => $prompt]);
        $rawResponse = $this->sendRequestToGemini($prompt);

        if (!$rawResponse || empty($rawResponse['response'])) {
            return null;
        }

        $jsonString = $this->extractJsonFromString($rawResponse['response']);
        $analysis   = json_decode($jsonString, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($analysis)) {
            Log::info('Análise do Gemini recebida.', ['analysis' => $analysis]);
            return $analysis;
        }

        Log::warning('Falha ao decodificar JSON da análise.', ['raw_response' => $rawResponse['response']]);
        return null;
    }

    /** --------------------------------------------------------------------
     *  PROCESS – gera a resposta do assistente
     * -------------------------------------------------------------------*/
    public function processMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);       // **todo o histórico**
        $prompt  = $this->buildTextResponsePrompt($userMessage, $context);

        Log::debug('Prompt enviado ao Gemini.', ['prompt' => $prompt]);

        // Temperatura 0.7 para respostas mais próximas da fala humana
        return $this->sendRequestToGemini($prompt, 0.7);
    }

    /** --------------------------------------------------------------------
     *  ENVIO À API GEMINI
     * -------------------------------------------------------------------*/
    private function sendRequestToGemini(
        string $promptContents,
        float  $temperature = 0.2,
        int    $maxTokens   = 2048
    ): ?array {
        if (empty($this->apiKey)) {
            Log::error('Chave da API do Gemini não configurada.');
            return null;
        }

        $model   = 'gemini-2.0-flash-lite';
        $payload = [
            'generationConfig' => [
                'temperature'     => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
            'contents' => [['parts' => [['text' => $promptContents]]]],
        ];

        try {
            $url      = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";
            $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if (
                $response->successful()
                && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])
            ) {
                return [
                    'success'  => true,
                    'response' => trim($response->json()['candidates'][0]['content']['parts'][0]['text']),
                ];
            }

            Log::error('Erro na API do Gemini.', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        } catch (Exception $e) {
            Log::error('Exceção ao chamar Gemini.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** --------------------------------------------------------------------
     *  MONTA TODO O HISTÓRICO DA CONVERSA
     * -------------------------------------------------------------------*/
    private function buildConversationContext(WhatsAppConversation $conversation): string
    {
        // Carrega todas as mensagens da conversa para garantir o contexto completo.
        $messages = $conversation->messages()->orderBy('created_at', 'asc')->get();

        if ($messages->count() <= 1) {
            return 'Nenhum histórico de conversa anterior.';
        }

        // Remove a última mensagem da coleção, pois é a que está sendo processada atualmente.
        $messages->pop();

        $context = "Histórico da conversa:\n";
        foreach ($messages as $msg) {
            $author  = $msg->direction === 'inbound' ? 'Usuário' : 'Assistente';
            $content = $msg->content ?? "[Mídia: {$msg->type}]";
            $context .= "{$author}: {$content}\n";
        }
        return $context;
    }
    /** --------------------------------------------------------------------
     *  CRIA O PROMPT DE ANÁLISE (JSON OBRIGATÓRIO)
     * -------------------------------------------------------------------*/
    private function buildAnalysisPrompt(string $userMessage, string $context, ?string $state): string
    {
        $stateDescription = $state
            ? "O estado atual da conversa é '{$state}'."
            : 'A conversa não possui estado específico.';

        $jsonWrapper = "Responda APENAS com o JSON solicitado, sem texto extra.\n\n";

        return $jsonWrapper . <<<PROMPT
Você é um sistema de classificação. Devolva JSON com:
{
  "is_off_topic": boolean,
  "contains_pii": boolean,
  "pii_type": "cpf" | "rg" | "cnh" | "outro" | null,
  "cep_detected": string | null,
  "intent": "agendar_cras" | "consultar_beneficio" | "atualizar_cadastro" |
            "informacoes_gerais" | "transferir_atendente" | "saudacao_despedida" |
            "nao_entendido"
}

Diretrizes:
1. {$stateDescription}
2. CPF/RG/CNH = PII. CEP NÃO é PII.
3. Se encontrar 8 dígitos consecutivos, extraia em "cep_detected".
4. Intenção principal → preencher "intent".

Contexto completo:
{$context}

Mensagem do usuário: "{$userMessage}"
PROMPT;
    }

    /** --------------------------------------------------------------------
     *  CRIA O PROMPT DE RESPOSTA (TODO O KB + HISTÓRICO)
     * -------------------------------------------------------------------*/
    private function buildTextResponsePrompt(string $userMessage, string $context): string
    {
        return <<<PROMPT
Você é o **SIM Social**, assistente virtual da SEDES-DF.

--- BASE DE CONHECIMENTO (COMPLETE) ---
{$this->sedesKnowledgeBase}
--- FIM DA BASE ---

# Regras
1. Responda em português claro e amigável. Use emojis se desejar.
2. NÃO retorne JSON, números puros ou código – apenas texto compreensível.
3. Se não souber, diga que não possui essa informação e pergunte se pode ajudar em algo mais.

# Histórico
{$context}

# Pergunta atual
{$userMessage}
PROMPT;
    }

    /** --------------------------------------------------------------------
     *  Extrai o primeiro JSON válido encontrado na string
     * -------------------------------------------------------------------*/
    private function extractJsonFromString(string $text): ?string
    {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        return ($start !== false && $end !== false)
            ? substr($text, $start, $end - $start + 1)
            : null;
    }
}
