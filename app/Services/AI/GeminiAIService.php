<?php

namespace App\Services\AI;

use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class GeminiAIService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    private ?string $sedesKnowledgeBase = null;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key');
        $this->loadKnowledgeBase();
    }

    private function loadKnowledgeBase(): void
    {
        try {
            if (Storage::disk('local')->exists('sedes.json')) {
                $this->sedesKnowledgeBase = Storage::disk('local')->get('sedes.json');
            }
        } catch (Exception $e) {
            Log::error('Falha ao carregar sedes.json.', ['error' => $e->getMessage()]);
        }
    }

    public function analyzeUserMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);
        $prompt = $this->buildAnalysisPrompt($userMessage, $context, $conversation->chatbot_state);
        $rawResponse = $this->sendRequestToGemini($prompt);

        if (!$rawResponse || empty($rawResponse['response'])) {
            return null;
        }

        $jsonString = $this->extractJsonFromString($rawResponse['response']);
        $analysis = json_decode($jsonString, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($analysis)) {
            Log::info('Análise do Gemini recebida.', ['analysis' => $analysis]);
            return $analysis;
        }

        Log::warning('Falha ao decodificar JSON da análise.', ['raw_response' => $rawResponse['response']]);
        return null;
    }

    public function processMessage(WhatsAppConversation $conversation, string $userMessage): ?array
    {
        $context = $this->buildConversationContext($conversation);
        $prompt = $this->buildTextResponsePrompt($userMessage, $context);
        return $this->sendRequestToGemini($prompt, 0.7);
    }

    private function sendRequestToGemini(string $promptContents, float $temperature = 0.2, int $maxTokens = 2048): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('Chave da API do Gemini não configurada.');
            return null;
        }

        $model = 'gemini-2.0-flash-lite';
        $payload = [
            'generationConfig' => ['temperature' => $temperature, 'maxOutputTokens' => $maxTokens],
            'contents' => [['parts' => [['text' => $promptContents]]]],
        ];

        try {
            $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";
            $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->successful() && isset($response->json()['candidates'][0]['content']['parts'][0]['text'])) {
                return ['success' => true, 'response' => trim($response->json()['candidates'][0]['content']['parts'][0]['text'])];
            }
            Log::error('Erro na API do Gemini.', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        } catch (Exception $e) {
            Log::error('Exceção ao chamar Gemini.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildConversationContext(WhatsAppConversation $conversation): string
    {
        $messages = $conversation->messages()->orderBy('created_at', 'asc')->get();
        if ($messages->isEmpty()) return 'Nenhum histórico de conversa anterior.';
        $context = "Histórico da conversa:\n";
        foreach ($messages as $msg) {
            $author = $msg->direction === 'inbound' ? 'Usuário' : 'Assistente';
            $content = $msg->content ?? "[Mídia: {$msg->type}]";
            $context .= "{$author}: {$content}\n";
        }
        return $context;
    }

    private function buildAnalysisPrompt(string $userMessage, string $context, ?string $state): string
    {
        $stateDescription = $state ? "O estado atual da conversa é '{$state}'." : 'A conversa não possui estado específico.';
        $jsonWrapper = "Responda APENAS com o JSON solicitado, sem texto extra.\n\n";

        return $jsonWrapper . <<<PROMPT
Você é um sistema de classificação para o chatbot da **SEDES-DF (Secretaria de Desenvolvimento Social do Distrito Federal)**.
Seu objetivo é identificar a intenção do usuário sobre programas sociais e serviços.

Devolva um JSON com a seguinte estrutura:
{
  "is_off_topic": boolean,
  "contains_pii": boolean,
  "pii_type": "cpf" | "rg" | "cnh" | "outro" | null,
  "cep_detected": string | null,
  "intent": "df_social" | "df_sem_miseria" | "restaurantes_comunitarios" | "prato_cheio" | "cartao_gas" | "auxilio_natalidade" | "auxilio_funeral" | "auxilio_vulnerabilidade" | "acessuas_trabalho" | "projeto_warao" | "bolsa_familia" | "vale_gas_nacional" | "tarifa_social_energia" | "bpc" | "cadunico" | "unidades_atendimento" | "agendar_cras" | "info_sedes" | "informacoes_gerais" | "transferir_atendente" | "saudacao_despedida" | "nao_entendido"
}

Diretrizes de Mapeamento de Intenção:
- "DF Social": `df_social`
- "DF Sem Miséria": `df_sem_miseria`
- "Restaurante comunitário", "comida barata", "almoço por 2 reais": `restaurantes_comunitarios`
- "Prato Cheio": `prato_cheio`
- "Cartão Gás" ou "auxílio gás do DF": `cartao_gas`
- "Auxílio Natalidade", "ajuda para recém-nascido": `auxilio_natalidade`
- "Auxílio Funeral", "ajuda com enterro": `auxilio_funeral`
- "Auxílio por calamidade", "ajuda por despejo ou incêndio": `auxilio_vulnerabilidade`
- "Acessuas", "curso", "emprego": `acessuas_trabalho`
- "Warao", "ajuda para refugiados": `projeto_warao`
- "Bolsa Família": `bolsa_familia`
- "Vale-Gás" (nacional): `vale_gas_nacional`
- "Conta de luz com desconto", "tarifa de energia": `tarifa_social_energia`
- "BPC", "LOAS", "benefício para idoso ou pessoa com deficiência": `bpc`
- "Cadastro Único", "CadÚnico", "como me cadastrar": `cadunico`
- "Endereço do CRAS", "onde fica o CREAS": `unidades_atendimento`
- "Agendar no CRAS", "marcar atendimento": `agendar_cras`
- "O que é a Sedes", "qual o trabalho da secretaria": `info_sedes`

Outras Diretrizes:
1. {$stateDescription}
2. Se a intenção não se encaixar em nenhuma acima mas for sobre a SEDES, use `informacoes_gerais`.
3. CPF/RG/CNH são considerados PII. CEP NÃO é PII.
4. Se encontrar um CEP (8 dígitos), extraia-o para "cep_detected".

Contexto da conversa:
{$context}

Mensagem do usuário para analisar: "{$userMessage}"
PROMPT;
    }

    private function buildTextResponsePrompt(string $userMessage, string $context): string
    {
        return <<<PROMPT
Você é o **SIM Social**, o assistente virtual da **SEDES-DF**. Sua função é ajudar cidadãos com informações sobre programas e serviços sociais, com base no conhecimento fornecido.

--- BASE DE CONHECIMENTO (SEDES.JSON) ---
{$this->sedesKnowledgeBase}
--- FIM DA BASE ---

# Regras Essenciais
1. Seja sempre amigável, prestativo e utilize uma linguagem clara e acessível. Emojis são bem-vindos.
2. NUNCA invente informações. Se a resposta não estiver na base de conhecimento, informe que não possui detalhes sobre aquele tópico específico no momento e pergunte se pode ajudar em algo mais.
3. Não peça dados pessoais sensíveis como CPF ou RG.
4. Responda apenas com texto compreensível, sem JSON ou código.

# Histórico da Conversa
{$context}

# Pergunta do Usuário
{$userMessage}
PROMPT;
    }

    private function extractJsonFromString(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        return ($start !== false && $end !== false) ? substr($text, $start, $end - $start + 1) : null;
    }
}