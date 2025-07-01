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

    // Constantes de estado (awaiting_menu_choice foi removido da lógica ativa)
    private const STATE_NEW_CONVERSATION = 'new_conversation';
    private const STATE_GENERAL_CONVERSATION = 'general_conversation';
    private const STATE_AWAITING_LOCATION = 'awaiting_location_for_cras';
    private const STATE_AWAITING_CRAS_RESULT = 'awaiting_cras_result';
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
        $state = $conversation->chatbot_state;

        if ($state === self::STATE_NEW_CONVERSATION || is_null($state)) {
            $this->startConversationGreeting($conversation, $respondWithAudio);
            $this->processState($conversation, $message, $respondWithAudio);
            return;
        }

        $this->processState($conversation, $message, $respondWithAudio);
    }

    private function startConversationGreeting(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $greeting = "Olá! Sou o SIM Social, seu assistente virtual da Secretaria de Desenvolvimento Social. Me diga como posso te ajudar hoje. Você pode me mandar uma mensagem de texto ou um áudio, se preferir.";
        $this->sendResponse($conversation, $greeting, $respondWithAudio);
        $this->updateState($conversation, self::STATE_GENERAL_CONVERSATION);
    }

    private function processState(WhatsAppConversation $conversation, WhatsAppMessage $message, bool $respondWithAudio): void
    {
        $state = $conversation->chatbot_state ?? self::STATE_GENERAL_CONVERSATION;
        $userInput = $message->content;

        if ($state === self::STATE_AWAITING_LOCATION && ($message->type === 'location' || $userInput)) {
             $this->handleLocationInput($conversation, $userInput, $respondWithAudio);
             return;
        }
        
        // **LÓGICA CORRIGIDA**
        // O estado 'awaiting_menu_choice' não é mais usado de forma rígida.
        // Qualquer estado que não seja uma espera específica (como confirmação)
        // cairá no 'default' e será processado pela IA.
        switch ($state) {
            case self::STATE_AWAITING_APPOINTMENT_CONFIRMATION:
                $this->handleAppointmentConfirmation($conversation, $userInput, $respondWithAudio);
                break;
            case self::STATE_CONFIRMING_TRANSFER:
                $this->handleTransferConfirmation($conversation, $userInput, $respondWithAudio);
                break;
            case self::STATE_AWAITING_CRAS_RESULT:
                // Se o usuário responder enquanto espera o resultado do CRAS, a IA pode responder.
                // Ex: "está demorando muito?" -> a IA pode responder "Só mais um instante, por favor!"
                $this->handleGeneralQuery($conversation, $userInput, $respondWithAudio);
                break;
            case self::STATE_GENERAL_CONVERSATION:
            default:
                if (!empty($userInput)) {
                    $this->handleGeneralQuery($conversation, $userInput, $respondWithAudio);
                } else if ($message->type !== 'audio') {
                    $this->handleGenericMedia($conversation, $message->type, $respondWithAudio);
                }
                break;
        }
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
    
    private function handleGeneralQuery(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $intent = $this->detectIntent($userInput);
        if (in_array($intent, ['schedule_or_update', 'schedule_appointment', 'update_details'])) {
            $this->initiateCrasLocationFlow($conversation, $respondWithAudio);
        } elseif ($intent === 'transfer_human') {
            $this->offerHumanTransfer($conversation, $respondWithAudio);
        } else {
            $aiResponse = $this->geminiService->processMessage($conversation, $userInput);
            if ($aiResponse && !empty($aiResponse['response'])) {
                $this->sendResponse($conversation, $aiResponse['response'], $respondWithAudio);
            } else {
                $this->offerHumanTransfer($conversation, $respondWithAudio);
            }
        }
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
        $this->updateState($conversation, self::STATE_AWAITING_CRAS_RESULT);
        FindCrasAndSchedule::dispatch($conversation->id, $location)->onQueue('default')->delay(now()->addSeconds(3));
    }

    private function initiateCrasLocationFlow(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Entendi! Para atualizar dados ou agendar um atendimento, o caminho é o CRAS. Para eu encontrar a unidade mais próxima e já verificar um horário, pode me enviar sua localização ou apenas digitar seu CEP?";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, self::STATE_AWAITING_LOCATION);
    }

    private function handleAppointmentConfirmation(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $userInput = strtolower(trim($userInput));
        $message = "Tudo bem, o agendamento não foi confirmado. Se quiser tentar outra data, é só me pedir.";
        if (in_array($userInput, ['sim', 's', 'pode', 'confirma', 'confirmo', 'ok'])) {
            $message = "Agendamento confirmado! Lembre-se de levar um documento com foto e comprovante de residência. Se precisar de mais alguma coisa, é só chamar!";
        }
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, self::STATE_GENERAL_CONVERSATION);
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
            $this->updateState($conversation, self::STATE_GENERAL_CONVERSATION);
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

    private function detectIntent(string $input): ?string
    {
        $input = strtolower($input);
        $updateKeywords = ['atualizar', 'mudar', 'alterar', 'corrigir', 'meus dados', 'meu cadastro'];
        $scheduleKeywords = ['agendar', 'marcar', 'agendamento', 'horário', 'atendimento', 'cras'];
        $transferKeywords = ['atendente', 'humano', 'pessoa', 'falar com alguém'];
        $isUpdate = $this->containsKeywords($input, $updateKeywords);
        $isSchedule = $this->containsKeywords($input, $scheduleKeywords);
        if ($isUpdate || $isSchedule) return 'schedule_or_update';
        if ($this->containsKeywords($input, $transferKeywords)) return 'transfer_human';
        return null;
    }

    private function containsKeywords(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (Str::contains($text, $keyword)) return true;
        }
        return false;
    }
}