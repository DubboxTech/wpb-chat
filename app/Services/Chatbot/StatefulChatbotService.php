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

    // Definição dos estados possíveis da conversa
    private const STATE_AWAITING_LOCATION = 'awaiting_location';
    private const STATE_AWAITING_APPOINTMENT_CONFIRMATION = 'awaiting_appointment_confirmation';
    private const STATE_AWAITING_SURVEY_AGE = 'awaiting_survey_age';

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
     * Ponto de entrada principal para lidar com uma nova mensagem.
     */
    public function handle(WhatsAppConversation $conversation, WhatsAppMessage $message, bool $isNewConversation = false): void
    {
        $respondWithAudio = ($message->type === 'audio');

        if ($isNewConversation) {
            $this->sendWelcomeMessage($conversation);
        }

        if ($message->type === 'location') {
            $this->handleLocationInput($conversation, $message->content, false);
            return;
        }
        
        $this->processState($conversation, $message, $respondWithAudio);
    }
    
    /**
     * Envia a mensagem de boas-vindas para novas conversas.
     */
    private function sendWelcomeMessage(WhatsAppConversation $conversation): void
    {
        $welcomeText = "Olá! 👋 Eu sou o *SIM Social*, o assistente virtual da Secretaria de Desenvolvimento Social (SEDES-DF).\n\nPara facilitar, ouça o áudio a seguir com um resumo do que eu posso fazer por você! 👇";
        $this->sendResponse($conversation, $welcomeText, false);

        try {
            $audioUrl = 'https://whatsapp-dubbox.nyc3.digitaloceanspaces.com/audio_responses/59442778-78df-4c06-b939-a62646ef412c/0be3802b-e095-4938-909c-50763df0089f.mp3';

            if ($audioUrl) {
                $this->whatsappService->setAccount($conversation->whatsappAccount);
                $response = $this->whatsappService->sendAudioMessage($conversation->contact->phone_number, $audioUrl);

                if ($response && $response['success']) {
                    $messageData = ['type' => 'audio', 'media' => ['url' => $audioUrl]];
                    $this->saveOutboundMessage($conversation, null, $response['data'], $messageData);
                }
            }
        } catch (\Exception $e) {
            Log::error('Falha ao enviar áudio de boas-vindas.', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Processa a mensagem do usuário com base no estado atual da conversa.
     */
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
                case self::STATE_AWAITING_SURVEY_AGE:
                    $this->handleSurveyAgeResponse($conversation, $userInput, $respondWithAudio);
                    return;
            }
        }
        
        $this->processMessageWithAI($conversation, $userInput, $respondWithAudio);
    }
    
    /**
     * Envia a mensagem para a IA para análise e decide o próximo passo.
     */
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

    /**
     * Executa a ação correspondente à intenção identificada pela IA.
     */
    private function executeIntent(WhatsAppConversation $conversation, array $analysis, string $userInput, bool $respondWithAudio): void
    {
        $intent = $analysis['intent'] ?? 'nao_entendido';
        Log::info('Executando intenção', ['intent' => $intent, 'conversation_id' => $conversation->id]);
        
        $responseText = null;
        $callToAction = "\n\nPosso te ajudar com mais alguma informação sobre este ou outro programa? 😊";

        switch ($intent) {
            case 'pesquisa_restaurante_comunitario':
                $restaurantName = $analysis['restaurante_identificado'] ?? 'Não identificado';
                $this->initiateRestaurantSurveyFlow($conversation, $restaurantName);
                return;

            case 'restaurantes_comunitarios':
                $responseText = "Claro! Os Restaurantes Comunitários são uma ótima opção! 🍽️\n\nTemos 18 unidades no DF que servem refeições completas e de qualidade por um preço super acessível. Posso te ajudar com a localização de algum deles?";
                break;

            case 'agendar_cras':
            case 'unidades_atendimento':
                $this->initiateCrasLocationFlow($conversation, $respondWithAudio);
                return;

            case 'df_social':
                $responseText = "O benefício *DF Social* é um valor de R$ 150,00 mensais, destinado a famílias de baixa renda inscritas no CadÚnico. 📄" .
                                "\n\nPara saber todos os detalhes e como solicitar, o ideal é procurar uma unidade do CRAS ou acessar o site do GDF Social.";
                break;
            
            case 'prato_cheio':
                $responseText = "O *Cartão Prato Cheio* é uma ajuda e tanto! 💳 Ele oferece um crédito de R$ 250,00 por mês para a compra de alimentos para famílias em situação de insegurança alimentar." .
                                "\n\nVocê pode conferir o calendário de pagamentos e a lista de beneficiários no site oficial da Sedes-DF. 😉";
                break;
            
            case 'cartao_gas_df':
                $responseText = "Claro! O *Cartão Gás* do DF concede um auxílio de R$ 100,00 a cada dois meses para ajudar na compra do botijão de gás de 13kg. 🍳🔥" .
                                "\n\nÉ um apoio importante para as famílias de baixa renda aqui do Distrito Federal.";
                break;

            case 'bolsa_familia':
                $responseText = "O *Bolsa Família* é um programa essencial do Governo Federal! 👨‍👩‍👧‍👦" .
                                "\n\nO valor base é de *R$ 600,00*, com valores adicionais para famílias com crianças e gestantes." .
                                "\n\nA porta de entrada para receber é estar com o *Cadastro Único (CadÚnico)* em dia. Você pode fazer ou atualizar o seu em uma unidade do CRAS.";
                break;

            case 'bpc':
                $responseText = "O *Benefício de Prestação Continuada (BPC/LOAS)* garante um salário-mínimo por mês para idosos com 65 anos ou mais e para pessoas com deficiência de qualquer idade, desde que a renda da família seja baixa. 👵♿" .
                                "\n\nA solicitação é feita diretamente no INSS, mas o CRAS é o lugar certo para receber toda a orientação que você precisa!";
                break;
            
            case 'morar_bem':
                $responseText = "O programa *Morar Bem* é coordenado pela CODHAB, não pela SEDES. Ele busca facilitar o acesso à moradia. Para se inscrever ou obter informações, você deve procurar diretamente a CODHAB ou o site oficial deles.";
                break;
            
            case 'info_sedes':
            case 'informacoes_gerais':
            case 'saudacao_despedida':
                $this->answerGeneralQuestion($conversation, $userInput, $respondWithAudio);
                return;

            case 'nao_entendido':
            default:
                $this->askForClarification($conversation, $respondWithAudio);
                return;
        }

        if ($responseText) {
            $this->sendResponse($conversation, $responseText . $callToAction, $respondWithAudio);
            $this->updateState($conversation, null, []);
        }
    }

    /**
     * Inicia o fluxo de pesquisa do restaurante.
     */
    private function initiateRestaurantSurveyFlow(WhatsAppConversation $conversation, string $restaurantName): void
    {
        Log::info('Iniciando pesquisa de satisfação via Flow Template', [
            'conversation_id' => $conversation->id,
            'restaurant' => $restaurantName
        ]);

        $this->whatsappService->setAccount($conversation->whatsappAccount);

        $flowToken = '2011056406392867'; // O ideal é que isso venha de uma config

        $bodyParameters = [
            ['type' => 'text', 'text' => $restaurantName]
        ];
        
        $response = $this->whatsappService->sendFlowTemplateMessage(
            $conversation->contact->phone_number,
            'pesquisa_rc_2025_07',
            'en_US',
            $flowToken,
            $bodyParameters
        );

        if ($response && $response['success']) {
            $this->saveOutboundMessage(
                $conversation,
                "Formulário de pesquisa para restaurante comunitário - $restaurantName enviado.",
                $response['data'],
                ['type' => 'template']
            );
        }

        // --- CORREÇÃO APLICADA AQUI ---
        // Agora, ao definir o estado da conversa, também salvamos o nome do restaurante
        // no contexto. Isso garante que teremos a informação para o fallback.
        $this->updateState($conversation, 'flow_sent', ['restaurant_name' => $restaurantName]);
    }

    /**
     * Lida com a resposta do usuário à primeira pergunta da pesquisa.
     */
    private function handleSurveyAgeResponse(WhatsAppConversation $conversation, string $age, bool $respondWithAudio): void
    {
        $context = $conversation->chatbot_context ?? [];
        $context['user_age'] = filter_var($age, FILTER_SANITIZE_NUMBER_INT);
        
        // Atualiza para o próximo estado da pesquisa, mas não envia a próxima pergunta.
        $this->updateState($conversation, 'awaiting_survey_frequency', $context); 
        
        // --- ALTERAÇÃO APLICADA AQUI ---
        // A mensagem de "frequência de uso" foi desabilitada comentando as linhas abaixo.
        /*
        $message = "Obrigado! Com que frequência você utiliza os serviços do restaurante?\n\n1. Diariamente\n2. Algumas vezes por semana\n3. Raramente";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        */
        
        Log::info('Survey age received and processed. Next message disabled as requested.', [
            'conversation_id' => $conversation->id,
            'age_received' => $age
        ]);
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
        $this->updateState($conversation, null, []);
    }

    private function handlePiiDetected(WhatsAppConversation $conversation, ?string $piiType, bool $respondWithAudio): void
    {
        $typeName = match ($piiType) { 'cpf' => 'CPF', 'rg' => 'RG', 'cnh' => 'CNH', default => 'documento pessoal' };
        $message = "Para sua segurança, não posso tratar dados como {$typeName} por aqui. Por favor, dirija-se a uma unidade de atendimento do CRAS para prosseguir com sua solicitação.";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null, []);
    }
    
    private function initiateCrasLocationFlow(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Claro! Para agendamentos ou atualizações no CRAS, preciso saber onde você está. Por favor, me envie sua localização pelo anexo do WhatsApp ou digite seu CEP.";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, self::STATE_AWAITING_LOCATION);
    }
    
    private function answerGeneralQuestion(WhatsAppConversation $conversation, string $userInput, bool $respondWithAudio): void
    {
        $aiResponse = $this->geminiService->processMessage($conversation, $userInput);
        if ($aiResponse && !empty($aiResponse['response'])) {
            $this->sendResponse($conversation, $aiResponse['response'], $respondWithAudio);
        } else {
            $this->askForClarification($conversation, $respondWithAudio);
        }
        $this->updateState($conversation, null, []);
    }

    private function askForClarification(WhatsAppConversation $conversation, bool $respondWithAudio): void
    {
        $message = "Desculpe, não entendi muito bem. Você poderia me dizer de outra forma como posso te ajudar?";
        $this->sendResponse($conversation, $message, $respondWithAudio);
        $this->updateState($conversation, null, []);
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

    private function sendTemplate(WhatsAppConversation $conversation, string $templateName, array $parameters, string $languageCode = 'pt_BR'): void
    {
        $this->whatsappService->setAccount($conversation->whatsappAccount);
        
        $response = $this->whatsappService->sendTemplateMessage(
            $conversation->contact->phone_number,
            $templateName,
            $languageCode,
            $parameters
        );

        if ($response && $response['success']) {
            $messageData = [
                'type' => 'template',
                'template_name' => $templateName,
                'template_parameters' => $parameters
            ];
            $this->saveOutboundMessage($conversation, "Template '{$templateName}' ({$languageCode}) enviado.", $response['data'], $messageData);
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

    public function updateState(WhatsAppConversation $conversation, ?string $newState, array $contextData = []): void
    {
        $currentContext = $conversation->chatbot_context ?? [];
        $newContext = !empty($contextData) ? array_merge($currentContext, $contextData) : $currentContext;

        $updatePayload = ['chatbot_state' => $newState];
        
        if (is_null($newState)) {
            $updatePayload['chatbot_context'] = null;
        } elseif (!empty($contextData)) {
            $updatePayload['chatbot_context'] = $newContext;
        }

        $conversation->update($updatePayload);
    }
}