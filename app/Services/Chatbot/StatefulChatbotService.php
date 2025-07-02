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
    private const STATE_CONFIRMING_TRANSFER = 'confirming_transfer';

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

        // Se a conversa for nova, envia uma saudação e depois processa a mensagem.
        if (is_null($conversation->chatbot_state)) {
            $this->startConversationGreeting($conversation, $respondWithAudio);
        }

        $this->processState($conversation, $message, $respondWithAudio);
    }
    
    private function startConversationGreeting(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $greeting = "Olá! Sou o SIM Social, seu assistente virtual da Secretaria de Desenvolvimento Social. Como posso te ajudar hoje?";
        $this->sendResponse($conversation, $greeting, $respondWithAudio);
    }

    private function processState(WhatsAppConversation $conversation, WhatsAppMessage $message, bool $respondWithAudio): void
    {
        $state = $conversation->chatbot_state;
        $userInput = $message->content;

        // Lógica de estados específicos (quando o bot precisa de uma resposta direta)
        if ($state === self::STATE_AWAITING_LOCATION && $userInput) {
             $this->handleLocationInput($conversation, $userInput, $respondWithAudio);
             return;
        }
        if ($state === self::STATE_AWAITING_APPOINTMENT_CONFIRMATION) {
            $this->handleAppointmentConfirmation($conversation, $userInput, $respondWithAudio);
            return;
        }
        if ($state === self::STATE_CONFIRMING_TRANSFER) {
            $this->handleTransferConfirmation($conversation, $userInput, $respondWithAudio);
            return;
        }

        // **FLUXO PRINCIPAL BASEADO EM IA**
        // Se não estiver em um estado de espera específico, usa a IA para entender a intenção.
        if (empty($userInput)) {
            $this->handleGenericMedia($conversation, $message->type, $respondWithAudio);
            return;
        }

        $intentResponse = $this->geminiService->getIntent($conversation, $userInput);
        $intent = trim($intentResponse['response'] ?? 'nao_entendido');
        Log::info('Gemini Intent Detected', ['intent' => $intent, 'conversation_id' => $conversation->id]);

        switch ($intent) {
            case 'agendar_cras':
            case 'atualizar_cadastro':
                $this->initiateCrasLocationFlow($conversation, $respondWithAudio);
                break;
            case 'consultar_beneficio':
                $this->initiateBenefitConsultationFlow($conversation, $respondWithAudio);
                break;
            case 'transferir_atendente':
                $this->offerHumanTransfer($conversation, $respondWithAudio);
                break;
            
            // Caso 'saudacao_despedida' agora cai aqui junto com 'informacoes_gerais'
            case 'informacoes_gerais':
            case 'saudacao_despedida':
                $this->answerGeneralQuestion($conversation, $userInput, $respondWithAudio);
                break;
            
            default: // nao_entendido
                $this->askForClarification($conversation, $respondWithAudio);
                break;
        }
    }

    // --- MÉTODOS DE FLUXO DE AÇÃO ---

    

    private function initiateCrasLocationFlow(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Claro! Para agendamentos ou atualizações no CRAS, preciso saber onde você está. Por favor, me envie sua localização pelo anexo do WhatsApp ou digite seu CEP.";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, self::STATE_AWAITING_LOCATION);
    }
    
    private function initiateBenefitConsultationFlow(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Para consultar seu benefício, preciso confirmar alguns dados. Por favor, qual o seu CPF?";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        // Aqui você mudaria o estado para, por exemplo, 'awaiting_cpf'
    }

    private function answerGeneralQuestion(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $aiResponse = $this->geminiService->processMessage($conversation, $userInput);
        if ($aiResponse && !empty($aiResponse['response'])) {
            $this->sendResponse($conversation, $aiResponse['response'], $respondWithAudio);
        } else {
            $this->offerHumanTransfer($conversation, $respondWithAudio);
        }
        $this->updateState($conversation, null); // Reseta o estado para uma conversa geral
    }

    private function askForClarification(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Desculpe, não entendi muito bem. Você poderia me dizer de outra forma como posso te ajudar?";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null);
    }
    
    // ... (O restante dos métodos, como handleLocationInput, handleAppointmentConfirmation, etc., permanecem os mesmos)
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
    
    public function sendCrasResult(int $conversationId, array $crasData): void
    {
        $conversation = WhatsAppConversation::find($conversationId);
        if (!$conversation) return;
        $message = "Prontinho! Encontrei a unidade mais próxima para você.\n\n*{$crasData['name']}*\n*Endereço:* {$crasData['address']}\n\nConsegui um horário para você na *{$crasData['date']}, {$crasData['time']}*. Fica bom? Posso confirmar?";
        $this->sendResponse($conversation, $message, false);
        $this->updateState($conversation, self::STATE_AWAITING_APPOINTMENT_CONFIRMATION);
    }
        private function handleLocationInput(WhatsAppConversation $conversation, string $location, bool $respondWithAudio): void
    {
        $cepText = str_contains($location, ',') ? 'sua localização' : "o CEP {$location}";
        $message = "Ótimo! Já estou localizando o CRAS mais próximo de {$cepText} para você. Aguarde um instante!";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, 'awaiting_cras_result');
        FindCrasAndSchedule::dispatch($conversation->id, $location)->onQueue('default')->delay(now()->addSeconds(3));
    }
    private function handleAppointmentConfirmation(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $userInput = strtolower(trim($userInput));
        $message = "Tudo bem, o agendamento não foi confirmado. Se quiser tentar outra data, é só me pedir.";
        if (in_array($userInput, ['sim', 's', 'pode', 'confirma', 'confirmo', 'ok'])) {
            $message = "Agendamento confirmado! Lembre-se de levar um documento com foto e comprovante de residência. Se precisar de mais alguma coisa, é só chamar!";
        }
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null);
    }

    private function offerHumanTransfer(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Desculpe, não consegui ajudar com isso. Quer que eu te transfira para um de nossos atendentes?";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, self::STATE_CONFIRMING_TRANSFER);
    }

    private function handleTransferConfirmation(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $userInput = strtolower(trim($userInput));
        if (in_array($userInput, ['sim', 's', 'quero', 'pode ser', 'gostaria', 'sim por favor'])) {
            $this->transferToHuman($conversation);
        } else {
            $message = "Tudo bem! Se mudar de ideia, é só me chamar.";
            $this->sendResponse($conversation, $message, $respondWithAudio);
            $this->updateState($conversation, null);
        }
    }

    public function updateState(WhatsAppConversation $conversation, ?string $newState): void
    {
        $conversation->update(['chatbot_state' => $newState]);
    }

    private function transferToHuman(WhatsAppConversation $conversation): void
    {
        $this->sendResponse($conversation, "Combinado! Estou transferindo sua conversa. Por favor, aguarde um momento que logo um atendente irá te responder.", false);
        $conversation->update(['status' => 'pending', 'is_ai_handled' => false, 'chatbot_state' => 'transferred']);
    }
}