<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    private $supportUser;
    private $defaultAccount;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Iniciando Seeder de Demonstração para Assistência Social...');

        $this->supportUser = User::where('email', 'support@whatsappbusiness.com')->first();
        $this->defaultAccount = WhatsAppAccount::where('is_default', true)->first();

        if (!$this->defaultAccount || !$this->supportUser) {
            $this->command->error('Conta padrão do WhatsApp ou usuário de suporte não encontrado. Rode os seeders iniciais (RolePermissionSeeder, WhatsAppAccountSeeder) primeiro.');
            return;
        }

        $this->command->info('Limpando dados de demonstração antigos...');
        
        // *** CORREÇÃO AQUI: Removido o DB::transaction() ***
        // A transação não é compatível com o comando TRUNCATE do MySQL.
        // A lógica de desabilitar/habilitar chaves estrangeiras é mantida.
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        WhatsAppMessage::query()->truncate();
        WhatsAppConversation::query()->truncate();
        WhatsAppContact::query()->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('Criando dados históricos (últimos 30 dias)...');
        $this->createHistoricalContacts(15);

        $this->command->info('Criando dados de alta atividade (últimas 24 horas)...');
        $this->createRecentActiveContacts(5);

        $this->command->info('Seeder de demonstração finalizado com sucesso!');
    }

    /**
     * Cria contatos e conversas com atividade distribuída nos últimos 30 dias.
     */
    private function createHistoricalContacts(int $count): void
    {
        $contactsData = [
            ['name' => 'Ana Paula Vieira', 'phone' => '5561991234561', 'tags' => ['cadastro_unico_desatualizado', 'agendamento_cras'], 'cep' => '71503-505'],
            ['name' => 'Bruno Alves Martins', 'phone' => '5561991234562', 'tags' => ['beneficiario_bolsa_familia'], 'cep' => '72301-503'],
            ['name' => 'Carla Dias Souza', 'phone' => '5561991234563', 'tags' => ['documentacao_pendente', 'bpc'], 'cep' => '70160-900'],
            ['name' => 'Daniel Farias Lima', 'phone' => '5561991234564', 'tags' => ['primeiro_contato'], 'cep' => '70390-015'],
            ['name' => 'Eduarda Ferreira', 'phone' => '5561991234565', 'tags' => ['visita_social_solicitada'], 'cep' => '70770-516'],
            ['name' => 'Fábio Azevedo', 'phone' => '5561991234566', 'tags' => ['beneficio_concedido'], 'cep' => '70297-400'],
            ['name' => 'Gabriela Costa', 'phone' => '5561991234567', 'tags' => ['cesta_basica'], 'cep' => '70632-200'],
            ['name' => 'Heitor Pereira', 'phone' => '5561991234568', 'tags' => ['primeiro_contato'], 'cep' => '70673-433'],
            ['name' => 'Isabela Rocha', 'phone' => '5561991234569', 'tags' => ['atendimento_cras', 'documentacao_pendente'], 'cep' => '70741-680'],
            ['name' => 'João Vitor Almeida', 'phone' => '5561991234570', 'tags' => ['beneficiario_bolsa_familia'], 'cep' => '70864-530'],
            ['name' => 'Larissa Mendes', 'phone' => '5561991234571', 'tags' => ['recurso_beneficio'], 'cep' => '72005-015'],
            ['name' => 'Marcos Aurélio', 'phone' => '5561991234572', 'tags' => ['cadastro_novo'], 'cep' => '71900-100'],
            ['name' => 'Natália Ribeiro', 'phone' => '5561991234573', 'tags' => ['agendamento'], 'cep' => '71000-010'],
            ['name' => 'Otávio Nunes', 'phone' => '5561991234574', 'tags' => ['informacao_geral'], 'cep' => '73010-111'],
            ['name' => 'Patrícia Souza', 'phone' => '5561991234575', 'tags' => ['suporte_resolvido'], 'cep' => '70640-517'],
        ];

        foreach (array_slice($contactsData, 0, $count) as $index => $data) {
            $this->createConversationForContact($data, Carbon::now()->subDays(rand(2, 29)), $index);
        }
    }

    /**
     * Cria contatos e conversas com alta atividade nas últimas 24 horas.
     */
    private function createRecentActiveContacts(int $count): void
    {
        $contactsData = [
            ['name' => 'Rafael Guimarães', 'phone' => '5561981234501', 'tags' => ['urgente', 'bloqueio_beneficio'], 'cep' => '70833-010'],
            ['name' => 'Sofia Bernardes', 'phone' => '5561981234502', 'tags' => ['agendamento_cras', 'familia_grande'], 'cep' => '72110-800'],
            ['name' => 'Thiago Carvalho', 'phone' => '5561981234503', 'tags' => ['documentacao_pendente'], 'cep' => '71939-000'],
            ['name' => 'Úrsula Medeiros', 'phone' => '5561981234504', 'tags' => ['primeiro_contato'], 'cep' => '70658-151'],
            ['name' => 'Vinícius Nogueira', 'phone' => '5561981234505', 'tags' => ['auxilio_aluguel'], 'cep' => '73330-001'],
        ];

        foreach (array_slice($contactsData, 0, $count) as $index => $data) {
            $this->createConversationForContact($data, Carbon::now()->subHours(rand(1, 23)), $index, true);
        }
    }

    private function createConversationForContact(array $data, Carbon $createdAt, int $index, bool $isRecent = false)
    {
        $contact = WhatsAppContact::create([
            'name' => $data['name'], 'phone_number' => $data['phone'], 'whatsapp_id' => $data['phone'],
            'tags' => $data['tags'], 'custom_fields' => ['cep' => $data['cep'], 'cidade' => 'Brasília'],
            'status' => 'active', 'last_seen_at' => $createdAt->addMinutes(rand(5, 60)),
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        $isAssignedToHuman = ($index % 3 == 0);
        $isResolved = !$isRecent && ($index % 4 == 0);

        $conversation = WhatsAppConversation::create([
            'whatsapp_account_id' => $this->defaultAccount->id, 'contact_id' => $contact->id,
            'assigned_user_id' => $isAssignedToHuman ? $this->supportUser->id : null,
            'conversation_id' => Str::uuid(),
            'status' => $isResolved ? 'resolved' : ($isAssignedToHuman ? 'pending' : 'open'),
            'is_ai_handled' => !$isAssignedToHuman, 'last_message_at' => $createdAt->addHours(1),
            'unread_count' => $isRecent || ($isAssignedToHuman && !$isResolved) ? rand(1, 3) : 0,
            'created_at' => $createdAt, 'updated_at' => $createdAt->addHours(1),
        ]);

        if ($isRecent) {
            $this->createMessagesForConversation($conversation, 10, 15);
        } else {
            $this->createMessagesForConversation($conversation, 4, 8);
        }
    }
    
    private function createMessagesForConversation(WhatsAppConversation $conversation, int $minMessages, int $maxMessages)
    {
        $dialogues = [
            [['in', 'bom dia, preciso atualizar meu cadastro unico'], ['out', true, 'Bom dia! Para isso, preciso do seu CPF.'], ['in', '12345678900'], ['out', true, 'Obrigada. Agora, por favor, me informe o seu novo CEP.'], ['in', '71503505'], ['out', true, 'Perfeito, endereço localizado. A atualização foi solicitada e será concluída em até 48 horas.']],
            [['in', 'oi, meu bolsa familia foi bloqueado'], ['out', true, 'Olá! Entendo. O bloqueio pode ocorrer por dados desatualizados. Para analisar, preciso que vá ao CRAS mais próximo.'], ['in', 'precisa agendar?'], ['out', false, $this->supportUser->id, 'Sim, o agendamento é necessário. Deseja marcar agora?']],
            [['in', 'Quero agendar atendimento no CRAS'], ['out', true, 'Claro. Qual o melhor dia para você na próxima semana?'], ['in', 'terça'], ['out', true, 'Temos horários às 09:00 e 14:00. Qual prefere?'], ['in', '14h'], ['out', true, 'Confirmado para a próxima terça, às 14:00. Leve um documento com foto.']],
            [['in', 'como faço pra pegar cesta basica?'], ['out', true, 'Olá. A concessão de cestas básicas é feita após uma análise. Para iniciar, me informe seu CPF.'], ['in', 'ok, 98765432111'], ['out', true, 'Sua solicitação foi aberta com o protocolo Nº ' . rand(2024000, 2024999) . '. Um assistente social entrará em contato.']],
            [['in', 'Meu cartão do benefício não funcionou.'], ['out', true, 'Lamento por isso. Pode me confirmar os 4 últimos dígitos do cartão?'], ['in', 'é 5544'], ['out', false, $this->supportUser->id, 'Verifiquei aqui e parece haver um bloqueio de segurança. Estou transferindo para o setor responsável resolver para você. Por favor, aguarde.']],
        ];

        $chosenDialogue = $dialogues[array_rand($dialogues)];
        $messageCount = rand($minMessages, $maxMessages);

        $baseTime = Carbon::now()->subHours(rand(0, 23));
        if ($conversation->created_at->isBefore($baseTime)) {
            $baseTime = $conversation->created_at;
        }

        for ($i = 0; $i < $messageCount && $i < count($chosenDialogue); $i++) {
            $msg = $chosenDialogue[$i];
            $messageTime = $baseTime->addMinutes($i * rand(1, 5));

            $conversation->messages()->create([
                'contact_id' => $conversation->contact_id, 'message_id' => Str::uuid(), 'whatsapp_message_id' => 'wamid.DEMO_' . Str::random(20),
                'direction' => $msg[0] === 'in' ? 'inbound' : 'outbound',
                'type' => 'text', 'status' => 'read', 'content' => $msg[2] ?? $msg[1],
                'is_ai_generated' => $msg[0] === 'out' ? $msg[1] : false,
                'user_id' => $msg[0] === 'out' && !$msg[1] ? $msg[2] : null,
                'created_at' => $messageTime, 'read_at' => $messageTime->addSeconds(rand(10, 60)), 'updated_at' => $messageTime,
            ]);
        }
        
        $lastMessage = $conversation->messages()->latest('created_at')->first();
        if ($lastMessage) {
            $conversation->last_message_at = $lastMessage->created_at;
            $conversation->save();
        }
    }
}
