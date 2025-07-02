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

    // Estados simplificados
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

    public function handle(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        $respondWithAudio = ($message->type === 'audio');

        if ($message->type === 'location') {
            $this->handleLocationInput($conversation, $message->content, false);
            return;
        }

        // **INÍCIO DA NOVA LÓGICA DE BOAS-VINDAS**
        $isNewOrReopened = false;
        // Verifica se a conversa estava fechada
        if ($conversation->status === 'closed') {
            $conversation->update(['status' => 'open']);
            $isNewOrReopened = true;
        } 
        // Verifica se é a primeira mensagem de uma nova conversa
        else if ($this->isFirstMessageOfConversation($conversation, $message)) {
            $isNewOrReopened = true;
        }
        
        if ($isNewOrReopened) {
            $this->startConversationGreeting($conversation, $respondWithAudio);
        }
        // **FIM DA NOVA LÓGICA DE BOAS-VINDAS**

        $this->processState($conversation, $message, $respondWithAudio);
    }
    
    private function isFirstMessageOfConversation(WhatsAppConversation $conversation, WhatsAppMessage $message): bool
    {
        // Considera "primeira mensagem" se o estado for nulo e for a mensagem mais antiga.
        return is_null($conversation->chatbot_state) && $message->id === $conversation->messages()->oldest()->first()->id;
    }

    /**
     * **MENSAGEM DE BOAS-VINDAS ATUALIZADA**
     */
    private function startConversationGreeting(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $greeting = "Olá! Que bom ter você por aqui! ✨\n\nEu sou o SIM Social, o assistente virtual da Secretaria de Desenvolvimento Social, e estou pronto para te ajudar a encontrar informações e agendar atendimentos no CRAS.\n\nFique à vontade para me mandar uma mensagem de texto ou um áudio explicando o que você precisa! 🎤";
        $this->sendResponse($conversation, $greeting, $respondWithAudio);
        $this->updateState($conversation, null);
    }

    private function processState(WhatsAppConversation $conversation, WhatsAppMessage $message, bool $respondWithAudio): void
    {
        $state = $conversation->chatbot_state;
        $userInput = $message->content;

        if ($state === self::STATE_AWAITING_LOCATION && $userInput) {
             $this->handleLocationInput($conversation, $userInput, $respondWithAudio);
             return;
        }
        if ($state === self::STATE_AWAITING_APPOINTMENT_CONFIRMATION) {
            $this->handleAppointmentConfirmation($conversation, $userInput, $respondWithAudio);
            return;
        }
        
        if (empty($userInput)) {
            $this->handleGenericMedia($conversation, $message->type, $respondWithAudio);
            return;
        }

        $intentResponse = $this->geminiService->getIntent($conversation, $userInput);
        $intent = trim($intentResponse['response'] ?? 'nao_entendido');
        Log::info('Gemini Intent Detected', ['intent' => $intent, 'conversation_id' => $conversation->id]);

        switch ($intent) {
            case 'agendar_cras':
                $this->initiateCrasLocationFlow($conversation, $respondWithAudio);
                break;
            case 'tentativa_de_cadastro':
                $this->handleCadastroAttempt($conversation, $respondWithAudio);
                break;
            case 'informacoes_gerais':
            case 'saudacao_despedida':
                $this->answerGeneralQuestion($conversation, $userInput, $respondWithAudio);
                break;
            default:
                $this->answerGeneralQuestion($conversation, $userInput, $respondWithAudio);
                break;
        }
    }

    private function handleCadastroAttempt(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Peço desculpas, mas por segurança, ainda não consigo receber dados cadastrais por aqui. Para atualizar suas informações, o ideal é um atendimento presencial.";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->initiateCrasLocationFlow($conversation, $respondWithAudio, false);
    }

    private function initiateCrasLocationFlow(WhatsAppConversation $conversation, bool $respondWithAudio, bool $sendIntro = true): void
    {
        if ($sendIntro) {
            $messageIntro = "Claro! Para agendamentos, preciso saber onde você está.";
            $this->sendResponse($conversation, $messageIntro, $respondWithAudio);
        }
        $messagePrompt = "Por favor, me envie sua localização pelo anexo do WhatsApp ou digite seu CEP.";
        $this->sendResponse($conversation, $messagePrompt, $respondWithAudio);
        $this->updateState($conversation, self::STATE_AWAITING_LOCATION);
    }
    
    private function answerGeneralQuestion(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $aiResponse = $this->geminiService->processMessage($conversation, $userInput);
        if ($aiResponse && !empty($aiResponse['response'])) {
            $this->sendResponse($conversation, $aiResponse['response'], $respondWithAudio);
        } else {
            $this->sendResponse($conversation, "Desculpe, não consegui processar sua solicitação. Posso tentar agendar um atendimento no CRAS para você?", $respondWithAudio);
        }
        $this->updateState($conversation, null);
    }

    private function handleLocationInput(WhatsAppConversation $conversation, string $location, bool $respondWithAudio): void
    {
        $cepText = str_contains($location, ',') ? 'sua localização' : "o CEP {$location}";
        $message = "Ótimo! 📍 Já estou localizando o CRAS mais próximo de {$cepText} para você. Aguarde um instante!";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, 'awaiting_cras_result');
        FindCrasAndSchedule::dispatch($conversation->id, $location)->onQueue('default')->delay(now()->addSeconds(3));
    }

    /**
     * **MÉTODO ATUALIZADO**
     * Aumentado o leque de palavras de confirmação.
     */
    private function handleAppointmentConfirmation(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $userInput = strtolower(trim($userInput));
        $positiveResponses = [
            'sim', 's', 'pode', 'confirma', 'confirmo', 'ok', 'pode confirmar', 
            'pode sim', 'pode ser', 'isso', 'isso mesmo', 'confirme', 'confirmo sim', 
            'claro', 'quero', 'quero sim', 'aceito'
        ];
        
        $message = "Tudo bem, o agendamento não foi confirmado. Se quiser tentar outra data, é só me pedir.";
        if (in_array($userInput, $positiveResponses)) {
            $message = "Agendamento confirmado! ✅ Lembre-se de levar um documento com foto e comprovante de residência. Se precisar de mais alguma coisa, é só chamar!";
        }
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null);
    }
    
    public function sendCrasResult(int $conversationId, array $crasData): void
    {
        $conversation = WhatsAppConversation::find($conversationId);
        if (!$conversation) return;
        $message = "Prontinho! Encontrei a unidade mais próxima para você.\n\n*{$crasData['name']}*\n*Endereço:* {$crasData['address']}\n\nConsegui um horário para você na *{$crasData['date']}, {$crasData['time']}*. Fica bom? Posso confirmar?";
        $this->sendResponse($conversation, $message, false);
        $this->updateState($conversation, self::STATE_AWAITING_APPOINTMENT_CONFIRMATION);
    }

    public function updateState(WhatsAppConversation $conversation, ?string $newState): void
    {
        $conversation->update(['chatbot_state' => $newState]);
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
            'contact_id' => $conversation->contact_id,
            'message_id' => Str::uuid(),
            'whatsapp_message_id' => $apiResponse['messages'][0]['id'] ?? null,
            'direction' => 'outbound',
            'type' => $messageData['type'],
            'media' => $messageData['media'] ?? null,
            'status' => 'sent',
            'content' => $content,
            'is_ai_generated' => true,
        ]);
        $conversation->touch();
        event(new ChatMessageSent($newMessage->load('contact')));
    }
    
    public function handleGenericMedia(WhatsAppConversation $conversation, string $mediaType, bool $asAudio = false): void
    {
        $responses = [
            'image' => "Recebi sua imagem!",
            'video' => "Vídeo recebido! Vou dar uma olhada.",
            'sticker' => "Adorei o sticker!",
            'audio' => "Recebi seu áudio, mas não consegui entender. Poderia gravar novamente ou digitar sua dúvida?",
            'document' => "Recebi seu documento, obrigado!"
        ];
        $responseMessage = $responses[$mediaType] ?? "Recebi seu anexo, obrigado!";
        $this->sendResponse($conversation, $responseMessage, $asAudio);
    }
}
