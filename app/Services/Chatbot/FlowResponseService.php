<?php

namespace App\Services\Chatbot;

use App\Events\ChatMessageSent;
use App\Models\RestaurantSurvey;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Events\SurveySubmitted;

class FlowResponseService
{
    protected WhatsAppBusinessService $whatsappService;
    protected StatefulChatbotService $chatbotService;

    public function __construct(
        WhatsAppBusinessService $whatsappService,
        StatefulChatbotService $chatbotService
    ) {
        $this->whatsappService = $whatsappService;
        $this->chatbotService = $chatbotService;
    }

    /**
     * Analisa e salva a resposta de uma pesquisa vinda de um Flow.
     */
    public function handleSurveyResponse(WhatsAppMessage $message): void
    {
        Log::info('FlowResponseService is handling a survey response.', ['message_id' => $message->id]);

        $decodedMetadata = $message->metadata;
        if (!$decodedMetadata) {
            Log::warning('Metadata field is empty for this message.', ['message_id' => $message->id]);
            return;
        }

        $interactiveData = $decodedMetadata['interactive'] ?? null;
        if (!$interactiveData || !isset($interactiveData['nfm_reply']['response_json'])) {
            Log::warning('Flow response does not contain a valid interactive object.', ['message_id' => $message->id]);
            return;
        }

        $jsonString = $interactiveData['nfm_reply']['response_json'];
        $flowResponseData = json_decode($jsonString, true);
        
        $restaurantName = $this->getRestaurantName($flowResponseData, $message);
        if (!$restaurantName) {
            Log::error('Could not determine restaurant name for the survey. Aborting and sending apology.', [
                'message_id' => $message->id
            ]);
            $this->sendRestaurantIdentificationFailureMessage($message->conversation);
            return;
        }

        // **INÍCIO DA NOVA LÓGICA DE AVALIAÇÃO**
        $ratingRaw = $flowResponseData['screen_0_Avaliacao_4'] ?? null;
        $ratingValue = null;
        $ratingText = 'N/A'; // Valor padrão caso a avaliação não venha

        if ($ratingRaw) {
            $parts = explode('_', $ratingRaw, 2);
            if (count($parts) === 2) {
                $ratingIndex = (int) $parts[0];
                $ratingText = $parts[1]; // Ex: "Excelente", "Bom"
                // Mapeia o índice para uma escala de 5 a 1 (0=Excelente -> 5, 4=Péssimo -> 1)
                $ratingValue = 5 - $ratingIndex;
            }
        }
        // **FIM DA NOVA LÓGICA**

        $surveyData = [
            'whatsapp_account_id' => $message->conversation->whatsapp_account_id,
            'whatsapp_contact_id' => $message->contact->id,
            'restaurant_name' => $restaurantName,
            'full_name' => $flowResponseData['screen_0_Nome_Completo_0'] ?? null,
            'cpf' => $flowResponseData['screen_0_CPF_1'] ?? null,
            'cep' => $flowResponseData['screen_0_CEP_3'] ?? null,
            'address' => $flowResponseData['screen_0_Endereco_Completo_2'] ?? null,
            'rating' => $ratingValue, // Salva o valor numérico (5, 4, 3, 2, 1)
            'comments' => $flowResponseData['screen_0_Comentarios_5'] ?? null,
            'raw_response' => $flowResponseData,
        ];

        RestaurantSurvey::create($surveyData);
        Log::info('Restaurant survey saved successfully.', ['contact_id' => $message->contact->id, 'restaurant' => $restaurantName, 'rating' => $ratingValue]);
        
        event(new SurveySubmitted());
        
        $this->updateMessageContentWithSurveySummary($message, $surveyData, $ratingText);

        $this->sendConfirmationMessage($message->conversation);
        $this->chatbotService->updateState($message->conversation, null, []);
        Log::info('Conversation context reset after survey.', ['conversation_id' => $message->conversation->id]);
    }

    /**
     * Formata um resumo da pesquisa e salva no campo 'content' da mensagem.
     */
    private function updateMessageContentWithSurveySummary(WhatsAppMessage $message, array $surveyData, string $ratingText): void
    {
        $summary = "☑️ Resposta da Pesquisa de Satisfação:\n";
        $summary .= "Restaurante: {$surveyData['restaurant_name']}\n";
        // **MODIFICADO**: Exibe o texto e o valor numérico da avaliação
        $summary .= "Avaliação: {$ratingText} ({$surveyData['rating']}/5)\n";

        if (!empty($surveyData['comments'])) {
            $summary .= "Comentários: \"{$surveyData['comments']}\"\n";
        }
        if (!empty($surveyData['full_name'])) {
            $summary .= "Nome: {$surveyData['full_name']}\n";
        }
        
        $message->content = $summary;
        $message->save();

        Log::info('Message content updated with survey summary.', ['message_id' => $message->id]);
    }
    
    // ... (O restante dos métodos permanece igual)
    private function getRestaurantName(array $flowData, WhatsAppMessage $message): ?string
    {
        $restaurantInFlow = $flowData['restaurante'] ?? null;
        if ($restaurantInFlow && $restaurantInFlow !== '{{restaurante}}') {
            return $restaurantInFlow;
        }

        $conversationContext = $message->conversation->chatbot_context ?? [];
        $restaurantInContext = $conversationContext['restaurant_name'] ?? null;
        if ($restaurantInContext) {
            Log::info('Restaurant name retrieved from conversation context.', [
                'conversation_id' => $message->conversation->id
            ]);
            return $restaurantInContext;
        }

        return null;
    }

    private function sendConfirmationMessage(WhatsAppConversation $conversation): void
    {
        $this->whatsappService->setAccount($conversation->whatsappAccount);
        
        $confirmationText = "Obrigado por participar da nossa pesquisa! Sua opinião é muito importante para nós. ✨";
        
        $response = $this->whatsappService->sendTextMessage(
            $conversation->contact->phone_number,
            $confirmationText
        );

        if ($response && $response['success']) {
            $this->saveOutboundMessage($conversation, $confirmationText, $response['data']);
        }
    }

    private function sendRestaurantIdentificationFailureMessage(WhatsAppConversation $conversation): void
    {
        $apologyText = "Peço desculpas, mas não consegui identificar a unidade do Restaurante Comunitário para registrar sua pesquisa. 🤔\n\nPor favor, tente ler o QR Code da unidade novamente para que eu possa registrar sua opinião corretamente. Agradeço a sua compreensão!";
        
        $this->whatsappService->setAccount($conversation->whatsappAccount);
        $response = $this->whatsappService->sendTextMessage(
            $conversation->contact->phone_number,
            $apologyText
        );

        if ($response && $response['success']) {
            $this->saveOutboundMessage($conversation, $apologyText, $response['data']);
        }

        Log::warning('Could not identify restaurant for survey. Sent apology to user.', [
            'conversation_id' => $conversation->id,
        ]);
    }

    private function saveOutboundMessage(WhatsAppConversation $conversation, string $content, array $apiResponse): void
    {
        $newMessage = $conversation->messages()->create([
            'contact_id' => $conversation->contact_id,
            'message_id' => Str::uuid(),
            'whatsapp_message_id' => $apiResponse['messages'][0]['id'] ?? null,
            'direction' => 'outbound',
            'type' => 'text',
            'status' => 'sent',
            'content' => $content,
            'is_ai_generated' => true,
        ]);
        $conversation->touch();
        event(new ChatMessageSent($newMessage->load('contact')));
        Log::info('Outbound message saved to database.', ['message_id' => $newMessage->id]);
    }
}