<?php

namespace App\Services\Chatbot;

use App\Events\ChatMessageSent; // 1. IMPORTAR O NOVO EVENTO
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StatefulChatbotService
{
    protected WhatsAppBusinessService $whatsappService;

    // Constantes para os estados, facilitando a manutenção e clareza do código
    private const STATE_AWAITING_MENU_CHOICE = 'awaiting_menu_choice';
    private const STATE_AWAITING_CPF = 'awaiting_cpf_for_update';
    private const STATE_AWAITING_CEP = 'awaiting_cep_for_update';
    private const STATE_AWAITING_PROTOCOL = 'awaiting_protocol_for_status';
    private const STATE_AWAITING_APPOINTMENT_DATE = 'awaiting_appointment_date';

    public function __construct(WhatsAppBusinessService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }
    
    // ... (Os métodos handle, processState, processIntent e os handlers específicos permanecem os mesmos) ...
    public function handle(WhatsAppConversation $conversation, string $userInput): void
    {
        $state = $conversation->chatbot_state;

        // Se a conversa tem um estado definido, processa a resposta para aquele estado.
        if ($state) {
            $this->processState($conversation, $userInput, $state);
            return;
        }

        // Se não há estado, é o início de uma nova interação. Envia o menu.
        $this->sendMainMenu($conversation);
    }
    private function processState(WhatsAppConversation $conversation, string $userInput, string $state): void
    {
        switch ($state) {
            case self::STATE_AWAITING_MENU_CHOICE:
                $this->processIntent($conversation, $userInput);
                break;

            case self::STATE_AWAITING_CPF:
                $this->handleCpfInput($conversation, $userInput);
                break;

            case self::STATE_AWAITING_CEP:
                $this->handleCepInput($conversation, $userInput);
                break;
            
            case self::STATE_AWAITING_PROTOCOL:
                $this->handleProtocolInput($conversation, $userInput);
                break;
            
            case self::STATE_AWAITING_APPOINTMENT_DATE:
                $this->handleAppointmentDateInput($conversation, $userInput);
                break;

            default:
                Log::warning('Unknown chatbot state detected. Resetting conversation.', ['state' => $state, 'conversation_id' => $conversation->id]);
                $this->sendMainMenu($conversation);
                break;
        }
    }
    private function processIntent(WhatsAppConversation $conversation, string $userInput): void
    {
        $intent = $this->detectIntentFromMenu($userInput);

        switch ($intent) {
            case 'atualizar_cadastro':
                $this->startUpdateFlow($conversation);
                break;
            case 'consultar_status':
                $this->startCheckStatusFlow($conversation);
                break;
            case 'agendar_atendimento':
                $this->startScheduleCrasFlow($conversation);
                break;
            case 'info_beneficios':
                $this->startInfoBenefitsFlow($conversation);
                break;
            case 'falar_atendente':
                $this->transferToHuman($conversation);
                break;
            default:
                $this->sendMessage($conversation, "Desculpe, não reconheci essa opção. Por favor, digite um dos números do menu.");
                $this->sendMainMenu($conversation, false);
                break;
        }
    }
    private function handleCpfInput(WhatsAppConversation $conversation, string $cpf): void
    {
        $cleanedCpf = preg_replace('/\D/', '', $cpf);
        $validator = Validator::make(['cpf' => $cleanedCpf], ['cpf' => 'required|digits:11']);

        if ($validator->fails()) {
            $this->sendMessage($conversation, "O CPF informado parece inválido. Por favor, digite novamente apenas os 11 números do seu CPF.");
            return;
        }

        Log::info('CPF Recebido e validado', ['cpf' => $cleanedCpf, 'conversation_id' => $conversation->id]);
        $this->sendMessage($conversation, "Obrigado! Localizei o cadastro para o CPF final " . substr($cleanedCpf, -4) . ". Para continuar, qual o seu novo CEP? (apenas números)");
        $this->updateState($conversation, self::STATE_AWAITING_CEP, ['cpf' => $cleanedCpf]);
    }
    private function handleCepInput(WhatsAppConversation $conversation, string $cep): void
    {
        $cleanedCep = preg_replace('/\D/', '', $cep);
        $validator = Validator::make(['cep' => $cleanedCep], ['cep' => 'required|digits:8']);

        if ($validator->fails()) {
            $this->sendMessage($conversation, "O CEP informado parece inválido. Por favor, digite novamente os 8 números do seu CEP.");
            return;
        }
        
        Log::info('CEP recebido. Finalizando fluxo.', ['cep' => $cleanedCep, 'conversation_id' => $conversation->id]);
        $this->sendMessage($conversation, "Perfeito! Seu endereço foi atualizado com sucesso. Posso te ajudar com mais alguma coisa?");
        $this->sendMainMenu($conversation, false);
    }
    private function handleProtocolInput(WhatsAppConversation $conversation, string $protocol): void
    {
        $protocol = trim($protocol);
        $mockedStatus = ['Em análise', 'Aprovado e liberado', 'Pendente de documentação', 'Finalizado'];
        $randomStatus = $mockedStatus[array_rand($mockedStatus)];

        $this->sendMessage($conversation, "Consulta de protocolo realizada. O status do protocolo *{$protocol}* é: *{$randomStatus}*.\n\nDeseja algo mais?");
        $this->sendMainMenu($conversation, false);
    }
    private function handleAppointmentDateInput(WhatsAppConversation $conversation, string $date): void
    {
        $mockedTimes = ['09:30', '10:00', '14:30', '15:00'];
        $randomTime = $mockedTimes[array_rand($mockedTimes)];

        $this->sendMessage($conversation, "Agendamento confirmado! ✅\n\nSeu atendimento no CRAS mais próximo foi agendado para o dia *{$date}* às *{$randomTime}*.\n\nLembre-se de levar um documento com foto.");
        $this->sendMainMenu($conversation, false);
    }
    private function startUpdateFlow(WhatsAppConversation $conversation): void
    {
        $this->sendMessage($conversation, "Ok, vamos atualizar seu cadastro. Por favor, digite seu CPF (apenas os números).");
        $this->updateState($conversation, self::STATE_AWAITING_CPF);
    }
    private function startCheckStatusFlow(WhatsAppConversation $conversation): void
    {
        $this->sendMessage($conversation, "Para consultar o status, por favor, digite o número do seu protocolo.");
        $this->updateState($conversation, self::STATE_AWAITING_PROTOCOL);
    }
    private function startScheduleCrasFlow(WhatsAppConversation $conversation): void
    {
        $this->sendMessage($conversation, "Claro. Para qual dia você gostaria de agendar seu atendimento no CRAS? (Ex: 30/07/2025)");
        $this->updateState($conversation, self::STATE_AWAITING_APPOINTMENT_DATE);
    }
    private function startInfoBenefitsFlow(WhatsAppConversation $conversation): void
    {
        $message = "Atualmente, a SEDES-DF oferece diversos benefícios, como o Bolsa Família, Benefício de Prestação Continuada (BPC) e auxílios emergenciais. Para mais detalhes sobre cada um e como solicitar, acesse nosso site oficial.\n\nComo mais posso ajudar?";
        $this->sendMessage($conversation, $message);
        $this->sendMainMenu($conversation, false);
    }

    // --- FUNÇÕES DE UTILITÁRIO ---
    
    private function sendMessage(WhatsAppConversation $conversation, string $message): void
    {
        $this->whatsappService->setAccount($conversation->whatsappAccount);
        $response = $this->whatsappService->sendTextMessage($conversation->contact->phone_number, $message);

        if ($response['success']) {
            // 2. SALVAR MENSAGEM E DISPARAR O EVENTO
            $newMessage = $conversation->messages()->create([
                'contact_id' => $conversation->contact_id,
                'message_id' => Str::uuid(),
                'whatsapp_message_id' => $response['data']['messages'][0]['id'] ?? null,
                'direction' => 'outbound',
                'type' => 'text',
                'status' => 'sent',
                'content' => $message,
                'is_ai_generated' => true,
                'user_id' => null,
            ]);
            $conversation->touch();

            // Dispara o evento para notificar o frontend em tempo real
            event(new ChatMessageSent($newMessage->load('contact'))); // Carrega a relação de contato
        } else {
            Log::error('Chatbot failed to send message', ['conversation_id' => $conversation->id, 'error' => $response['message'] ?? 'Unknown error']);
        }
    }

    // ... (O resto do arquivo permanece igual) ...
    private function sendMainMenu(WhatsAppConversation $conversation, bool $sendGreeting = true): void
    {
        $greeting = "Olá! Sou o SIM SOCIAL, assistente virtual da SEDES-DF.";
        $question = $sendGreeting ? "Como posso te ajudar hoje?" : "Como mais posso ajudar?";
        $menu = "1️⃣  Atualizar dados cadastrais\n2️⃣  Consultar status de protocolo\n3️⃣  Agendar atendimento no CRAS\n4️⃣  Informações sobre benefícios\n0️⃣  Falar com um atendente";
        
        $fullMessage = $sendGreeting ? "{$greeting} {$question}\n\n{$menu}" : "{$question}\n\n{$menu}";
        
        $this->sendMessage($conversation, $fullMessage);
        $this->updateState($conversation, self::STATE_AWAITING_MENU_CHOICE, [], true); // Limpa contexto
    }
    private function updateState(WhatsAppConversation $conversation, ?string $newState, array $context = [], bool $clearContext = false): void
    {
        $updateData = ['chatbot_state' => $newState];
        
        if ($clearContext) {
            $updateData['chatbot_context'] = null;
        } else {
            $currentContext = $conversation->chatbot_context ?? [];
            $updateData['chatbot_context'] = empty($context) ? $currentContext : array_merge($currentContext, $context);
        }

        $conversation->update($updateData);
    }
    private function detectIntentFromMenu(string $input): ?string
    {
        $trimmedInput = trim(strtolower($input));

        if (str_contains($trimmedInput, 'atualizar') || str_contains($trimmedInput, 'cadastro') || $trimmedInput === '1') return 'atualizar_cadastro';
        if (str_contains($trimmedInput, 'status') || str_contains($trimmedInput, 'protocolo') || $trimmedInput === '2') return 'consultar_status';
        if (str_contains($trimmedInput, 'agendar') || str_contains($trimmedInput, 'cras') || $trimmedInput === '3') return 'agendar_atendimento';
        if (str_contains($trimmedInput, 'info') || str_contains($trimmedInput, 'beneficio') || $trimmedInput === '4') return 'info_beneficios';
        if (str_contains($trimmedInput, 'atendente') || str_contains($trimmedInput, 'humano') || $trimmedInput === '0') return 'falar_atendente';

        return null;
    }
    private function transferToHuman(WhatsAppConversation $conversation)
    {
        $this->sendMessage($conversation, "Certo. Estou te transferindo para um de nossos atendentes. Por favor, aguarde um momento.");
        
        $conversation->update([
            'status' => 'pending', 
            'is_ai_handled' => false,
            'chatbot_state' => 'transferred'
        ]);
    }
}
