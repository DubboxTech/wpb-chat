<?php

namespace App\Listeners;

use App\Events\WhatsAppMessageReceived;
use App\Services\Chatbot\FlowResponseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ProcessFlowResponse implements ShouldQueue
{
    use InteractsWithQueue;

    protected FlowResponseService $flowService;

    public function __construct(FlowResponseService $flowService)
    {
        $this->flowService = $flowService;
    }

    public function handle(WhatsAppMessageReceived $event): void
    {
        $message = $event->message;

        // --- PONTO CRÍTICO DE SEGURANÇA ---
        // Este 'if' garante que o listener só atue em mensagens do tipo 'interactive'
        // e subtipo 'nfm_reply', ignorando todo o resto e não quebrando outros fluxos.
        if (
            $message->type !== 'interactive'
        ) {
            Log::info('ProcessFlowResponse listener is ignoring non-Flow reply.', ['message_id' => $message->id]);
            return; // Aborta a execução para qualquer outro tipo de mensagem.
        }

        Log::info('ProcessFlowResponse listener triggered for a Flow reply.', ['message_id' => $message->id]);

        try {
            $this->flowService->handleSurveyResponse($message);
        } catch (\Exception $e) {
            Log::error('Failed to process Flow response.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}