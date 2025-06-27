<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    protected WhatsAppWebhookService $webhookService;

    public function __construct(WhatsAppWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Handle webhook verification (GET request).
     */
    public function verify(Request $request): string
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        
        // Verify the webhook
        if ($mode === 'subscribe' && $token === 'libapache2') {
            Log::info('WhatsApp webhook verified successfully');
            return $challenge;
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'token' => $token,
            'expected_token' => 'libapache2'
        ]);

        abort(403, 'Forbidden');
    }

    /**
     * Handle incoming webhook data (POST request).
     */
    public function handle(Request $request): JsonResponse
    {
        // Verify signature
        $signature = $request->header('X-Hub-Signature-256');
        $payload = $request->getContent();
        
        // if ($signature) {
        //     $appSecret = config('services.whatsapp.app_secret');
        //     if (!$this->webhookService->verifySignature($payload, $signature, $appSecret)) {
        //         Log::warning('WhatsApp webhook signature verification failed');
        //         return response()->json(['error' => 'Invalid signature'], 403);
        //     }
        // }

        // Process webhook data
        $data = $request->all();
        
        Log::info('WhatsApp webhook received', [
            'object' => $data['object'] ?? null,
            'entry_count' => count($data['entry'] ?? [])
        ]);

        // Only process WhatsApp messages
        if (($data['object'] ?? null) !== 'whatsapp_business_account') {
            return response()->json(['status' => 'ignored']);
        }

        $result = $this->webhookService->processWebhook($data);

        if ($result['success']) {
            return response()->json(['status' => 'success']);
        }

        Log::error('WhatsApp webhook processing failed', [
            'message' => $result['message'],
            'data' => $data
        ]);

        return response()->json(['status' => 'error'], 500);
    }

    /**
     * Test webhook endpoint.
     */
    public function test(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'WhatsApp webhook endpoint is working',
            'timestamp' => now()->toISOString()
        ]);
    }
}

