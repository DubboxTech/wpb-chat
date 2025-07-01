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

        $dateFormatted = now()
            ->addWeekdays(3)
            ->locale('pt_BR') // Define o idioma para Português do Brasil
            ->translatedFormat('l, d \d\e F'); // Usa o método para traduzir os nomes do dia/mês

        $crasData = [
            'name' => 'CRAS Brasília (Asa Sul)',
            'address' => 'Av. L2 Sul, SGAS 614/615',
            'time' => 'às 10:00',
            'date' => $dateFormatted, // Usa a data formatada
        ];
        
        // Chama o serviço para enviar a resposta final para o usuário
        $chatbotService->sendCrasResult($this->conversationId, $crasData);
    }
}