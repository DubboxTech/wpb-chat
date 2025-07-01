<?php

namespace App\Jobs;

use App\Services\Chatbot\StatefulChatbotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FindCrasAndSchedule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $conversationId;
    protected string $location;

    public function __construct(int $conversationId, string $location)
    {
        $this->conversationId = $conversationId;
        $this->location = $location;
    }

    public function handle(StatefulChatbotService $chatbotService): void
    {
        Log::info('Finding CRAS and scheduling appointment.', [
            'conversation_id' => $this->conversationId,
            'location' => $this->location
        ]);

        // --- LÓGICA DE BUSCA (ATUALMENTE MOCKADA) ---
        // Aqui você poderia, no futuro, consultar uma API de geolocalização
        // ou um banco de dados com os endereços dos CRAS.

        // Para o CEP 70610-410 (Sudoeste), o CRAS da Asa Sul é o mais próximo.
        $crasData = [
            'name' => 'CRAS Brasília (Asa Sul)',
            'address' => 'Av. L2 Sul, SGAS 614/615',
            'time' => 'às 10:00',
            'date' => now()->addWeekdays(3)->format('l, d \d\e F'), // Simula para 3 dias úteis no futuro
        ];
        
        // Chama o serviço para enviar a resposta final para o usuário
        $chatbotService->sendCrasResult($this->conversationId, $crasData);
    }
}