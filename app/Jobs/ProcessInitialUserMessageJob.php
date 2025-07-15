<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\Chatbot\StatefulChatbotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInitialUserMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The WhatsApp message instance.
     *
     * @var \App\Models\WhatsAppMessage
     */
    public $message;

    /**
     * Create a new job instance.
     *
     * @param WhatsAppMessage $message
     */
    public function __construct(WhatsAppMessage $message)
    {
        $this->message = $message;
    }

    /**
     * Execute the job.
     *
     * @param StatefulChatbotService $chatbotService
     * @return void
     */
    public function handle(StatefulChatbotService $chatbotService): void
    {
        Log::info('Executing ProcessInitialUserMessageJob for message.', ['message_id' => $this->message->id]);
        
        // Chama o método público que lida com a lógica da IA
        $chatbotService->handleAiResponse($this->message);
    }
}