<?php

namespace App\Services\Chatbot;

use App\Events\ChatMessageSent;
use App\Jobs\FindCrasAndSchedule;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\AI\GeminiAIService;
use App\Services\AI\TextToSpeechService;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StatefulChatbotService
{
    protected WhatsAppBusinessService $whatsappService;
    protected GeminiAIService $geminiService;
    protected TextToSpeechService $ttsService;

    private const STATE_AWAITING_LOCATION = 'awaiting_location';
    private const STATE_AWAITING_APPOINTMENT_CONFIRMATION = 'awaiting_appointment_confirmation';

    public function __construct(
        WhatsAppBusinessService $whatsappService,
        GeminiAIService $geminiService,
        TextToSpeechService $ttsService
    ) {
        $this->whatsappService = $whatsappService;
        $this->geminiService = $geminiService;
        $this->ttsService = $ttsService;
    }

    /**
     * MÉTODO HANDLE ATUALIZADO:
     * Recebe o novo parâmetro 'isNewConversation'.
     */
    public function handle(WhatsAppConversation $conversation, WhatsAppMessage $message, bool $isNewConversation = false): void
    {
        $respondWithAudio = ($message->type === 'audio');

        // 1. Se for uma nova conversa, envia a saudação primeiro.
        if ($isNewConversation) {
            $this->sendWelcomeMessage($conversation, $respondWithAudio);
        }

        if ($message->type === 'location') {
            $this->handleLocationInput($conversation, $message->content, false);
            return;
        }
        
        $this->processState($conversation, $message, $respondWithAudio);
    }
    
    /**
     * 2. NOVO MÉTODO:
     * Envia uma mensagem de boas-vindas amigável.
     */
    private function sendWelcomeMessage(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $welcomeText = "Olá! 👋 Eu sou o SIM Social, o assistente virtual da Secretaria de Desenvolvimento Social (SEDES-DF).\n\n" .
                       "Estou aqui para te ajudar com informações sobre nossos programas, serviços e unidades de atendimento. " .
                       "Você pode me mandar uma mensagem de texto ou um áudio explicando o que precisa. Em que posso te ajudar hoje? 😊";
        
        $this->sendResponse($conversation, $welcomeText, $respondWithAudio);
    }

    private function processState(WhatsAppConversation $conversation, WhatsAppMessage $message, bool $respondWithAudio): void
    {
        $userInput = $message->content;
        if (empty($userInput)) {
            $this->handleGenericMedia($conversation, $message->type, $respondWithAudio);
            return;
        }

        $currentState = $conversation->chatbot_state;

        if ($currentState) {
            switch ($currentState) {
                case self::STATE_AWAITING_APPOINTMENT_CONFIRMATION:
                    $this->handleAppointmentConfirmation($conversation, $userInput, $respondWithAudio);
                    return;
            }
        }
        
        $this->processMessageWithAI($conversation, $userInput, $respondWithAudio);
    }
    
    private function processMessageWithAI(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $analysis = $this->geminiService->analyzeUserMessage($conversation, $userInput);
        if (!$analysis) {
            $this->askForClarification($conversation, $respondWithAudio);
            return;
        }
        if ($analysis['is_off_topic'] === true) {
            $this->handleOffTopicQuestion($conversation, $analysis, $userInput, $respondWithAudio);
            return;
        }
        $this->continueOnTopicFlow($conversation, $analysis, $userInput, $respondWithAudio);
    }

    private function handleOffTopicQuestion(WhatsAppConversation $conversation, array $analysis, string $userInput, bool $respondWithAudio): void
    {
        Log::info('Lidando com pergunta fora de tópico.', ['conversation_id' => $conversation->id, 'new_intent' => $analysis['intent']]);
        $originalState = $conversation->chatbot_state;
        $this->executeIntent($conversation, $analysis, $userInput, $respondWithAudio);
        if ($originalState === self::STATE_AWAITING_LOCATION) {
            $this->sendResponse($conversation, "Consegui te ajudar com sua outra dúvida? 😊 Voltando ao nosso agendamento, você poderia me informar o seu CEP, por favor?", $respondWithAudio);
            $this->updateState($conversation, $originalState);
        }
    }

    private function continueOnTopicFlow(WhatsAppConversation $conversation, array $analysis, string $userInput, bool $respondWithAudio): void
    {
        if ($analysis['contains_pii']) {
            $this->handlePiiDetected($conversation, $analysis['pii_type'], $respondWithAudio);
            return;
        }
        if ($analysis['cep_detected']) {
            $this->handleLocationInput($conversation, $analysis['cep_detected'], $respondWithAudio);
            return;
        }
        $this->executeIntent($conversation, $analysis, $userInput, $respondWithAudio);
    }

    private function executeIntent(WhatsAppConversation $conversation, array $analysis, string $userInput, bool $respondWithAudio): void
    {
        $intent = $analysis['intent'] ?? 'nao_entendido';
        Log::info('Executando intenção', ['intent' => $intent, 'conversation_id' => $conversation->id]);
        switch ($intent) {
            case 'agendar_cras':
            case 'atualizar_cadastro':
                $this->initiateCrasLocationFlow($conversation, $respondWithAudio);
                break;
            case 'consultar_beneficio':
                $this->initiateBenefitConsultationFlow($conversation, $respondWithAudio);
                break;
            case 'informacoes_gerais':
            case 'saudacao_despedida':
                $this->answerGeneralQuestion($conversation, $userInput, $respondWithAudio);
                break;
            default:
                $this->askForClarification($conversation, $respondWithAudio);
                break;
        }
    }
    
    private function handleAppointmentConfirmation(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $userInput = strtolower(trim($userInput));
        $affirmations = ['sim', 's', 'pode', 'confirma', 'confirmo', 'ok', 'pode sim', 'pode confirmar', 'claro', 'com certeza'];
        if (in_array($userInput, $affirmations)) {
            $message = "Agendamento confirmado! ✅ Lembre-se de levar um documento com foto e comprovante de residência. Se precisar de mais alguma coisa, é só chamar!";
        } else {
            $message = "Tudo bem, o agendamento não foi confirmado. Se quiser tentar outra data ou horário, é só me pedir. 😉";
        }
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null);
    }

    // ... (demais métodos permanecem os mesmos)
    private function handlePiiDetected(WhatsAppConversation $conversation, ?string $piiType, bool $respondWithAudio): void
    {
        $typeName = match ($piiType) { 'cpf' => 'CPF', 'rg' => 'RG', 'cnh' => 'CNH', default => 'documento pessoal' };
        $message = "Para sua segurança, não posso tratar dados como {$typeName} por aqui. Por favor, dirija-se a uma unidade de atendimento do CRAS para prosseguir com sua solicitação.";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null);
    }
    
    private function initiateCrasLocationFlow(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Claro! Para agendamentos ou atualizações no CRAS, preciso saber onde você está. Por favor, me envie sua localização pelo anexo do WhatsApp ou digite seu CEP.";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, self::STATE_AWAITING_LOCATION);
    }
    
    private function initiateBenefitConsultationFlow(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Entendi que você deseja consultar um benefício. Para isso, você precisará se dirigir a uma unidade do CRAS com seu CPF e documento com foto.";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null);
    }
    
    private function answerGeneralQuestion(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $aiResponse = $this->geminiService->processMessage($conversation, $userInput);
        if ($aiResponse && !empty($aiResponse['response'])) {
            $this->sendResponse($conversation, $aiResponse['response'], $respondWithAudio);
        } else {
            $this->askForClarification($conversation, $respondWithAudio);
        }
        $this->updateState($conversation, null);
    }

    private function askForClarification(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Desculpe, não entendi muito bem. Você poderia me dizer de outra forma como posso te ajudar?";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null);
    }
    
    private function handleLocationInput(WhatsAppConversation $conversation, string $location, bool $respondWithAudio): void
    {
        $cepText = str_contains($location, ',') ? 'sua localização' : "o CEP {$location}";
        $message = "Ótimo! Já estou localizando o CRAS mais próximo de {$cepText} para você. Aguarde um instante!";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, 'awaiting_cras_result');
        FindCrasAndSchedule::dispatch($conversation->id, $location)->onQueue('default')->delay(now()->addSeconds(3));
    }

    public function sendCrasResult(int $conversationId, array $crasData): void
    {
        $conversation = WhatsAppConversation::find($conversationId);
        if (!$conversation) return;
        $message = "Prontinho! Encontrei a unidade mais próxima para você.\n\n*{$crasData['name']}*\n*Endereço:* {$crasData['address']}\n\nConsegui um horário para você na *{$crasData['date']}, {$crasData['time']}*. Fica bom? Posso confirmar?";
        $this->sendResponse($conversation, $message, false);
        $this->updateState($conversation, self::STATE_AWAITING_APPOINTMENT_CONFIRMATION);
    }
    
    public function sendResponse(WhatsAppConversation $conversation, string $text, bool $asAudio = false): void
    {
        $this->whatsappService->setAccount($conversation->whatsappAccount);
        $messageData = [];
        $response = null;
        if ($asAudio) {
            $audioUrl = $this->ttsService->synthesize($text, $conversation->conversation_id);
            if ($audioUrl) {
                $response = $this->whatsappService->sendAudioMessage($conversation->contact->phone_number, $audioUrl);
                $messageData = ['type' => 'audio', 'media' => ['url' => $audioUrl]];
            }
        }
        if (!$response || !$response['success']) {
            $response = $this->whatsappService->sendTextMessage($conversation->contact->phone_number, $text);
            $messageData = ['type' => 'text'];
        }
        if ($response && $response['success']) {
            $contentToSave = ($messageData['type'] === 'audio') ? null : $text;
            $this->saveOutboundMessage($conversation, $contentToSave, $response['data'], $messageData);
        }
    }
    
    private function saveOutboundMessage(WhatsAppConversation $conversation, ?string $content, array $apiResponse, array $messageData): void
    {
        $newMessage = $conversation->messages()->create([
            'contact_id' => $conversation->contact_id, 'message_id' => Str::uuid(),
            'whatsapp_message_id' => $apiResponse['messages'][0]['id'] ?? null, 'direction' => 'outbound',
            'type' => $messageData['type'], 'media' => $messageData['media'] ?? null, 'status' => 'sent',
            'content' => $content, 'is_ai_generated' => true,
        ]);
        $conversation->touch();
        event(new ChatMessageSent($newMessage->load('contact')));
    }
    
    public function handleGenericMedia(WhatsAppConversation $conversation, string $mediaType, bool $asAudio = false): void
    {
        $responses = [
            'image' => "Recebi sua imagem! 👍", 'video' => "Vídeo recebido! Vou dar uma olhada. 🎬",
            'sticker' => "Adorei o sticker! 😄", 'audio' => "Recebi seu áudio, mas não consegui entender. Poderia gravar novamente ou, se preferir, digitar sua dúvida?",
            'document' => "Recebi seu documento, obrigado!"
        ];
        $responseMessage = $responses[$mediaType] ?? null;
        if ($responseMessage) {
            $this->sendResponse($conversation, $responseMessage, $asAudio);
        } else {
            $this->askForClarification($conversation, $asAudio);
        }
    }

    public function updateState(WhatsAppConversation $conversation, ?string $newState): void
    {
        $conversation->update(['chatbot_state' => $newState]);
    }
}