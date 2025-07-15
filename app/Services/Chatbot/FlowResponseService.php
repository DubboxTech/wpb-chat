<?php

namespace App\Services\Chatbot;

use App\Models\RestaurantSurvey;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsApp\WhatsAppBusinessService;
use App\Events\ChatMessageSent;
use Illuminate\Support\Str;

class FlowResponseService
{
    protected WhatsAppBusinessService $whatsappService;

    public function __construct(WhatsAppBusinessService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Analisa e salva a resposta de uma pesquisa vinda de um Flow.
     */
    public function handleSurveyResponse(WhatsAppMessage $message): void
    {
        Log::info('FlowResponseService is handling a survey response.', ['message_id' => $message->id]);

        // --- CORREÇÃO DEFINITIVA APLICADA AQUI ---
        // 1. Pega o conteúdo da coluna 'metadata', que é uma string JSON.
        $metadataJson = $message->metadata;
        if (!$metadataJson) {
            Log::warning('Metadata field is empty for this message.', ['message_id' => $message->id]);
            return;
        }

        // 2. Decodifica a string JSON para um array PHP.
        $decodedMetadata = json_decode($metadataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to decode metadata JSON.', ['message_id' => $message->id, 'metadata_string' => $metadataJson]);
            return;
        }

        // 3. Agora, busca com segurança o objeto 'interactive' de dentro do array decodificado.
        $interactiveData = $decodedMetadata['interactive'] ?? null;
        if (!$interactiveData) {
            Log::warning('Flow response does not contain an interactive object in the decoded metadata.', ['message_id' => $message->id]);
            return;
        }

        $jsonString = $interactiveData['nfm_reply']['response_json'] ?? null;
        if (!$jsonString) {
            Log::warning('Interactive data does not contain response_json.', ['message_id' => $message->id]);
            return;
        }

        $flowResponseData = json_decode($jsonString, true);
        
        $restaurantName = $this->getRestaurantName($flowResponseData, $message);

        if (!$restaurantName) {
            Log::error('Could not determine restaurant name for the survey. Aborting.', [
                'message_id' => $message->id
            ]);
            return;
        }

        $ratingRaw = $flowResponseData['screen_0_Avaliacao_4'] ?? null;
        $rating = $ratingRaw ? explode('_', $ratingRaw, 2)[1] : null;

        $surveyData = [
            'whatsapp_account_id' => $message->conversation->whatsapp_account_id,
            'whatsapp_contact_id' => $message->contact->id,
            'restaurant_name' => $restaurantName,
            'full_name' => $flowResponseData['screen_0_Nome_Completo_0'] ?? null,
            'cpf' => $flowResponseData['screen_0_CPF_1'] ?? null,
            'cep' => $flowResponseData['screen_0_CEP_3'] ?? null,
            'address' => $flowResponseData['screen_0_Endereco_Completo_2'] ?? null,
            'rating' => $rating,
            'comments' => $flowResponseData['screen_0_Comentarios_5'] ?? null,
            'raw_response' => $flowResponseData,
        ];

        RestaurantSurvey::create($surveyData);

        Log::info('Restaurant survey saved successfully.', ['contact_id' => $message->contact->id, 'restaurant' => $restaurantName]);

        $this->sendConfirmationMessage($message->conversation);
    }

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
        Log::info('Confirmation message saved to database.', ['message_id' => $newMessage->id]);
    }
}