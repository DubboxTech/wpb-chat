<?php

namespace App\Services\Chatbot;

use App\Events\ChatMessageSent;
use App\Jobs\FindCrasAndSchedule; // Importa o novo Job
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\AI\GeminiAIService;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StatefulChatbotService
{
    protected WhatsAppBusinessService $whatsappService;
    protected GeminiAIService $aiService;

    // Estados da conversa
    private const STATE_NEW_CONVERSATION = 'new_conversation';
    private const STATE_GENERAL_CONVERSATION = 'general_conversation';
    private const STATE_AWAITING_LOCATION = 'awaiting_location_for_cras';
    private const STATE_AWAITING_CRAS_RESULT = 'awaiting_cras_result'; // Novo estado
    private const STATE_AWAITING_APPOINTMENT_CONFIRMATION = 'awaiting_appointment_confirmation';
    private const STATE_CONFIRMING_TRANSFER = 'confirming_transfer';

    public function __construct(WhatsAppBusinessService $whatsappService, GeminiAIService $aiService)
    {
        $this->whatsappService = $whatsappService;
        $this->aiService = $aiService;
    }

    public function handle(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        $state = $conversation->chatbot_state;

        if ($state === self::STATE_NEW_CONVERSATION || is_null($state)) {
            $this->startConversationGreeting($conversation);
            $this->processState($conversation, $message);
            return;
        }

        $this->processState($conversation, $message);
    }

    private function startConversationGreeting(WhatsAppConversation $conversation): void
    {
        $greeting = "Olá! Sou o SIM Social, seu assistente virtual da Secretaria de Desenvolvimento Social. 😊\n\nMe diga como posso te ajudar hoje. Você pode me mandar uma mensagem de texto ou um áudio, se preferir.";
        $this->sendMessage($conversation, $greeting);
        $this->updateState($conversation, self::STATE_GENERAL_CONVERSATION);
    }

    private function processState(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        $state = $conversation->chatbot_state ?? self::STATE_GENERAL_CONVERSATION;
        $userInput = $message->content;

        if ($state === self::STATE_AWAITING_LOCATION && ($message->type === 'location' || $userInput)) {
             $this->handleLocationInput($conversation, $userInput);
             return;
        }

        switch ($state) {
            case self::STATE_AWAITING_APPOINTMENT_CONFIRMATION:
                $this->handleAppointmentConfirmation($conversation, $userInput);
                break;
            case self::STATE_CONFIRMING_TRANSFER:
                $this->handleTransferConfirmation($conversation, $userInput);
                break;
            case self::STATE_GENERAL_CONVERSATION:
            default:
                if (!empty($userInput)) {
                    $this->handleGeneralQuery($conversation, $userInput);
                } else if ($message->type !== 'audio') {
                    $this->handleGenericMedia($conversation, $message->type);
                }
                break;
        }
    }

    /**
     * **MÉTODO MODIFICADO**
     * Agora envia uma mensagem de "localizando" e dispara um job para a busca.
     */
    private function handleLocationInput(WhatsAppConversation $conversation, string $location): void
    {
        $cepText = str_contains($location, ',') ? 'sua localização' : "o CEP {$location}";
        $message = "Ótimo! 📍 Já estou localizando o CRAS mais próximo de {$cepText} para você. Aguarde um instante! 😊";
        
        $this->sendMessage($conversation, $message);
        $this->updateState($conversation, self::STATE_AWAITING_CRAS_RESULT);

        // Dispara o job em segundo plano para fazer a busca e agendamento
        FindCrasAndSchedule::dispatch($conversation->id, $location)->onQueue('default')->delay(now()->addSeconds(3));
    }

    /**
     * **NOVO MÉTODO**
     * Chamado pelo job FindCrasAndSchedule para enviar o resultado ao usuário.
     */
    public function sendCrasResult(int $conversationId, array $crasData): void
    {
        $conversation = WhatsAppConversation::find($conversationId);
        if (!$conversation) return;

        $message = "Prontinho! Encontrei a unidade mais próxima para você.\n\n*{$crasData['name']}*\n*Endereço:* {$crasData['address']}\n\nConsegui um horário para você na *{$crasData['date']}*, *{$crasData['time']}*. Fica bom? Posso confirmar o agendamento?";
        
        $this->sendMessage($conversation, $message);
        $this->updateState($conversation, self::STATE_AWAITING_APPOINTMENT_CONFIRMATION);
    }
    
    // O restante do arquivo (handleGenericMedia, handleGeneralQuery, etc.) permanece o mesmo.
    // ...
        public function handleGenericMedia(WhatsAppConversation $conversation, string $mediaType): void
    {
        $responses = [
            'image' => "Recebi sua imagem! 👍",
            'video' => "Uau, vídeo recebido! Vou dar uma olhada. 🎬",
            'sticker' => "Adorei o sticker! 😄",
            'audio' => "Recebi seu áudio, mas não consegui entender o que foi dito. Poderia tentar gravar novamente ou digitar sua dúvida, por favor?",
            'document' => "Recebi seu documento, obrigado!"
        ];

        $responseMessage = $responses[$mediaType] ?? "Recebi seu anexo, obrigado!";
        $this->sendMessage($conversation, $responseMessage);
    }

    private function handleGeneralQuery(WhatsAppConversation $conversation, string $userInput): void
    {
        $intent = $this->detectIntent($userInput);
        if (in_array($intent, ['schedule_or_update', 'schedule_appointment', 'update_details'])) {
            $this->initiateCrasLocationFlow($conversation);
        } elseif ($intent === 'transfer_human') {
            $this->offerHumanTransfer($conversation);
        } else {
            $aiResponse = $this->aiService->processMessage($conversation, $userInput);
            if ($aiResponse && !empty($aiResponse['response'])) {
                $this->sendMessage($conversation, $aiResponse['response']);
            } else {
                $this->offerHumanTransfer($conversation);
            }
        }
    }

    private function initiateCrasLocationFlow(WhatsAppConversation $conversation): void
    {
        $message = "Entendi! Para a gente resolver isso, seja para atualizar seus dados ou para um novo atendimento, o caminho é o CRAS.\n\nPara eu encontrar a unidade mais próxima e já verificar um horário para você, pode me enviar sua localização pelo anexo do WhatsApp ou apenas digitar seu CEP?";
        $this->sendMessage($conversation, $message);
        $this->updateState($conversation, self::STATE_AWAITING_LOCATION);
    }

    private function handleAppointmentConfirmation(WhatsAppConversation $conversation, string $userInput): void
    {
        $userInput = strtolower(trim($userInput));
        if (in_array($userInput, ['sim', 's', 'pode', 'confirma', 'confirmo', 'ok'])) {
            $message = "Agendamento confirmado! ✅\n\nLembre-se de levar um documento com foto e comprovante de residência, tá bom?\n\nSe precisar de mais alguma coisa, é só chamar!";
        } else {
            $message = "Tudo bem. O agendamento não foi confirmado. Se quiser tentar outra data ou horário, é só me pedir. 😉";
        }
        $this->sendMessage($conversation, $message);
        $this->updateState($conversation, self::STATE_GENERAL_CONVERSATION);
    }

    private function offerHumanTransfer(WhatsAppConversation $conversation): void
    {
        $message = "Hmm, desculpe, mas não consegui ajudar com isso. 🤔\n\nQuer que eu te transfira para um de nossos atendentes humanos? Eles podem te ajudar melhor.";
        $this->sendMessage($conversation, $message);
        $this->updateState($conversation, self::STATE_CONFIRMING_TRANSFER);
    }

    private function handleTransferConfirmation(WhatsAppConversation $conversation, string $userInput): void
    {
        $userInput = strtolower(trim($userInput));
        if (in_array($userInput, ['sim', 's', 'quero', 'pode ser', 'gostaria', 'sim por favor'])) {
            $this->transferToHuman($conversation);
        } else {
            $message = "Tudo bem! Se mudar de ideia ou precisar de outra coisa, é só me chamar. 😉";
            $this->sendMessage($conversation, $message);
            $this->updateState($conversation, self::STATE_GENERAL_CONVERSATION);
        }
    }

    private function transferToHuman(WhatsAppConversation $conversation): void
    {
        $this->sendMessage($conversation, "Combinado! Já estou transferindo sua conversa para um de nossos atendentes. Por favor, aguarde um momento que logo alguém irá te responder por aqui.");
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
    
    private function sendMessage(WhatsAppConversation $conversation, string $message): void
    {
        $this->whatsappService->setAccount($conversation->whatsappAccount);
        $response = $this->whatsappService->sendTextMessage($conversation->contact->phone_number, $message);
        if ($response['success']) {
            $newMessage = $conversation->messages()->create([
                'contact_id' => $conversation->contact_id, 'message_id' => Str::uuid(),
                'whatsapp_message_id' => $response['data']['messages'][0]['id'] ?? null,
                'direction' => 'outbound', 'type' => 'text', 'status' => 'sent',
                'content' => $message, 'is_ai_generated' => true,
            ]);
            $conversation->touch();
            event(new ChatMessageSent($newMessage->load('contact')));
        } else {
            Log::error('Chatbot failed to send message', ['conversation_id' => $conversation->id, 'error' => $response['message'] ?? 'Unknown error']);
        }
    }

    private function updateState(WhatsAppConversation $conversation, ?string $newState): void
    {
        $conversation->update(['chatbot_state' => $newState]);
    }
}