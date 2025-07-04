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
    private function sendWelcomeMessage(WhatsAppConversation $conversation): void
    {
        // 1. Envia a mensagem de texto introdutória
        $welcomeText = "Olá! 👋 Eu sou o *SIM Social*, o assistente virtual da Secretaria de Desenvolvimento Social (SEDES-DF).\n\nPara facilitar, ouça o áudio a seguir com um resumo do que eu posso fazer por você! 👇";
        $this->sendResponse($conversation, $welcomeText, false);

        try {
            // 2. Define a URL estática para o áudio de boas-vindas
            $audioUrl = 'https://whatsapp-dubbox.nyc3.digitaloceanspaces.com/audio_responses/59442778-78df-4c06-b939-a62646ef412c/0be3802b-e095-4938-909c-50763df0089f.mp3';

            if ($audioUrl) {
                // 3. Envia a mensagem de áudio
                $this->whatsappService->setAccount($conversation->whatsappAccount);
                $response = $this->whatsappService->sendAudioMessage($conversation->contact->phone_number, $audioUrl);

                // 4. Salva a mensagem de áudio enviada no histórico
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

    /**
     * **MÉTODO ATUALIZADO**
     * Contém a lógica de resposta para todas as intenções, com textos
     * mais amigáveis e formatados para o WhatsApp.
     */
    private function executeIntent(WhatsAppConversation $conversation, array $analysis, string $userInput, bool $respondWithAudio): void
    {
        $intent = $analysis['intent'] ?? 'nao_entendido';
        Log::info('Executando intenção', ['intent' => $intent, 'conversation_id' => $conversation->id]);
        
        $responseText = null;
        $callToAction = "\n\nPosso te ajudar com mais alguma informação sobre este ou outro programa? 😊";

        switch ($intent) {
            case 'agendar_cras':
            case 'atualizar_cadastro':
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
            
            case 'cartao_gas':
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
            
            case 'auxilio_natalidade':
                $responseText = "Que momento especial! 👶 O *Auxílio Natalidade* é um apoio de R$ 200,00 (pago em parcela única) para ajudar com as primeiras despesas do bebê em famílias de baixa renda." .
                                "\n\nPara solicitar, a mamãe ou o responsável deve procurar o CRAS mais próximo com seus documentos e os do recém-nascido. ✨";
                break;

            case 'auxilio_funeral':
                $responseText = "Sinto muito pela sua perda. Para apoiar as famílias de baixa renda neste momento difícil, a SEDES oferece o *Auxílio Funeral*. O benefício pode ser um valor de R$ 415,00 ou a cobertura do serviço funerário. 🙏" .
                                "\n\nPara solicitar, é necessário ir a um CRAS com a certidão de óbito e os documentos da família.";
                $callToAction = "\n\nSe precisar de mais alguma orientação, estou à disposição."; // Tom mais sóbrio
                break;
            
            case 'restaurantes_comunitarios':
                $responseText = "Claro! Os *Restaurantes Comunitários* são uma ótima opção! 🍽️" .
                                "\n\nTemos 18 unidades no DF que servem refeições completas e de qualidade por um preço super acessível, a partir de R$ 2,00. E o melhor: é aberto para *toda* a população!";
                break;
                
            case 'cadunico':
                $responseText = "O *Cadastro Único*, ou CadÚnico, é a porta de entrada para a maioria dos programas sociais dos governos Federal e do DF, como o Bolsa Família e o Prato Cheio. 📝" .
                                "\n\nManter ele atualizado é muito importante! Para se inscrever ou atualizar, você precisa agendar um atendimento no CRAS mais próximo da sua casa.";
                $callToAction = "\n\nPosso ajudar a encontrar uma unidade ou quer saber de outro programa?";
                break;

            // -- NOVAS INTENÇÕES --
            case 'morar_bem':
                $responseText = "O programa *Morar Bem* é coordenado pela CODHAB, não pela SEDES. Ele busca facilitar o acesso à moradia. Para se inscrever ou obter informações, você deve procurar diretamente a CODHAB ou o site oficial deles.";
                break;
            case 'isencao_concurso':
                $responseText = "Sim, pessoas inscritas no CadÚnico e com baixa renda podem ter direito à *isenção da taxa de inscrição em concursos públicos* federais e distritais. A solicitação é feita diretamente no site da banca organizadora do concurso, utilizando seu número do NIS.";
                break;
            case 'fomento_rural':
                $responseText = "O programa de *Fomento às Atividades Produtivas Rurais* oferece um apoio financeiro para pequenos produtores rurais investirem em seus projetos. Para saber mais sobre os critérios, o ideal é procurar a Emater-DF ou uma unidade do CRAS.";
                break;
            case 'tarifa_social_agua':
                $responseText = "A *Tarifa Social de Água e Esgoto* é um desconto na conta de água para famílias de baixa renda inscritas no CadÚnico. Para solicitar, você deve entrar em contato com a CAESB com seus documentos e o número do NIS em mãos.";
                break;
            case 'carteira_idoso':
                $responseText = "A *Carteira da Pessoa Idosa* é um documento que permite a idosos de baixa renda ter acesso a viagens interestaduais gratuitas ou com desconto. Você pode solicitar a sua no CRAS mais próximo!";
                break;
            case 'previdencia_dona_de_casa':
                $responseText = "A *contribuição previdenciária reduzida* para pessoas de família de baixa renda que se dedicam exclusivamente ao trabalho doméstico (donas de casa) é um direito! A alíquota é de 5% sobre o salário mínimo. A inscrição é feita junto ao INSS, e o CRAS pode te orientar sobre como proceder.";
                break;
            case 'id_jovem':
                $responseText = "O *ID Jovem* é um documento gratuito para jovens de 15 a 29 anos de baixa renda, que garante benefícios como meia-entrada em eventos e vagas gratuitas ou com desconto em viagens. Você pode emitir o seu pelo aplicativo ID Jovem ou em um CRAS.";
                break;
            case 'vale_gas_nacional':
                $responseText = "O *Auxílio Gás dos Brasileiros*, também conhecido como Vale-Gás Nacional, é um benefício do Governo Federal pago a cada dois meses. Ele é destinado a famílias inscritas no CadÚnico ou que recebem o BPC.";
                break;
            case 'auxilio_inclusao':
                $responseText = "O *Auxílio-Inclusão* é um benefício para pessoas com deficiência que recebem o BPC e começam a trabalhar com carteira assinada. Ele é um incentivo para a inclusão no mercado de trabalho. Para mais detalhes, o ideal é procurar o INSS.";
                break;
            case 'pe_de_meia':
                $responseText = "O *Pé-de-Meia* é um programa de incentivo financeiro para estudantes do ensino médio de escolas públicas. O objetivo é ajudar na permanência e conclusão dos estudos. A gestão do programa é feita pelo Ministério da Educação.";
                break;
            case 'dignidade_menstrual':
                 $responseText = "O programa *Dignidade Menstrual* distribui absorventes gratuitos para pessoas em situação de vulnerabilidade. Você pode encontrar os pontos de distribuição nas Unidades Básicas de Saúde (UBS) e em alguns CRAS.";
                 break;
            case 'servico_convivencia':
                $responseText = "O *Serviço de Convivência e Fortalecimento de Vínculos (SCFV)* oferece atividades em grupo (culturais, esportivas, etc.) para crianças, adolescentes, adultos e idosos, buscando fortalecer os laços familiares e comunitários. Procure o CRAS da sua região para saber quais grupos estão disponíveis!";
                break;

            case 'info_sedes':
            case 'informacoes_gerais':
            case 'saudacao_despedida':
                $this->answerGeneralQuestion($conversation, $userInput, $respondWithAudio);
                return;

            default:
                $this->askForClarification($conversation, $respondWithAudio);
                return;
        }

        if ($responseText) {
            $this->sendResponse($conversation, $responseText . $callToAction, $respondWithAudio);
            $this->updateState($conversation, null);
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