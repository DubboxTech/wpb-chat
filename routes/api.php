<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\Api\MediaController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Rota pública para verificação de saúde do sistema
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'version' => config('app.version', '1.0.0')
    ]);
});

// Grupo de rotas do Webhook do WhatsApp (públicas)
Route::prefix('whatsapp')->group(function () {
    Route::get('/webhook', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.webhook.verify');
    Route::post('/webhook', [WhatsAppWebhookController::class, 'handle'])->name('whatsapp.webhook.handle');
    Route::get('/webhook/test', [WhatsAppWebhookController::class, 'test'])->name('whatsapp.webhook.test');
});

// Grupo de rotas de autenticação para clientes externos (ex: app mobile)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');
    Route::post('/register', [AuthController::class, 'register'])->name('api.register');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('api.password.forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('api.password.reset');
    Route::post('/verify-2fa', [AuthController::class, 'verifyTwoFactor'])->name('api.verify-2fa');
    Route::post('/resend-2fa', [AuthController::class, 'resend2FA'])->name('api.resend-2fa');
});

// Grupo de rotas protegidas que requerem autenticação (via Sessão/Cookie ou Token)
Route::middleware('auth:sanctum')->group(function () {
    
    // Rotas de autenticação para usuários logados
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('api.refresh');
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/enable-2fa', [AuthController::class, 'enableTwoFactor'])->name('api.enable-2fa');
        Route::post('/disable-2fa', [AuthController::class, 'disableTwoFactor'])->name('api.disable-2fa');
    });
    
    // Rotas do Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [DashboardController::class, 'getStats'])->name('dashboard.stats');
        Route::get('/chart-data', [DashboardController::class, 'getChartData'])->name('dashboard.chart-data');
    });
    
    // Rotas de Conversas
    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'getConversations'])->name('conversations.list');
        Route::get('/stats', [ConversationController::class, 'getStats'])->name('conversations.stats');
        Route::get('/search', [ConversationController::class, 'searchMessages'])->name('conversations.search');
        Route::post('/bulk-update-status', [ConversationController::class, 'bulkUpdateStatus'])->name('conversations.bulkUpdateStatus');
        
        Route::get('/{id}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::post('/{id}/messages', [ConversationController::class, 'sendMessage'])->name('conversations.sendMessage');
        Route::post('/{id}/toggle-ai', [ConversationController::class, 'toggleAI'])->name('conversations.toggleAI');
        Route::patch('/{id}/status', [ConversationController::class, 'updateStatus'])->name('conversations.updateStatus');
        Route::post('/{id}/assign', [ConversationController::class, 'assignAgent'])->name('conversations.assignAgent');
        Route::get('/{id}/history', [ConversationController::class, 'getMessageHistory'])->name('conversations.history');
    });
    
    // Rotas de Usuários
    Route::apiResource('users', UserController::class);
    Route::post('users/{user}/assign-role', [UserController::class, 'assignRole'])->middleware('role:admin')->name('users.assignRole');
    Route::post('users/{user}/remove-role', [UserController::class, 'removeRole'])->middleware('role:admin')->name('users.removeRole');
    
    // Rotas de Campanhas
    Route::prefix('campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->name('api.campaigns.index'); // RENOMEADO
        Route::post('/', [CampaignController::class, 'store'])->middleware('permission:campaigns.create')->name('api.campaigns.store');
        Route::get('/templates', [CampaignController::class, 'templates'])->name('api.campaigns.templates');
        Route::get('/{campaign}', [CampaignController::class, 'show'])->name('api.campaigns.show');
        Route::put('/{campaign}', [CampaignController::class, 'update'])->middleware('permission:campaigns.edit')->name('api.campaigns.update');
        Route::delete('/{campaign}', [CampaignController::class, 'destroy'])->middleware('permission:campaigns.delete')->name('api.campaigns.destroy');
        
        Route::post('/{campaign}/start', [CampaignController::class, 'start'])->middleware('permission:campaigns.send')->name('api.campaigns.start');
        Route::post('/{campaign}/pause', [CampaignController::class, 'pause'])->middleware('permission:campaigns.send')->name('api.campaigns.pause');
        Route::post('/{campaign}/resume', [CampaignController::class, 'resume'])->middleware('permission:campaigns.send')->name('api.campaigns.resume');
        Route::post('/{campaign}/cancel', [CampaignController::class, 'cancel'])->middleware('permission:campaigns.send')->name('api.campaigns.cancel');
        Route::get('/{campaign}/analytics', [CampaignController::class, 'analytics'])->name('api.campaigns.analytics');

        Route::get('/{campaign}/contacts', [CampaignController::class, 'getCampaignContacts'])->name('api.campaigns.contacts');

        Route::get('/{campaign}/report', [CampaignController::class, 'getReportData'])->name('api.campaigns.report');
    });

    Route::get('/whatsapp-accounts', [App\Http\Controllers\Api\CampaignController::class, 'accounts'])->name('api.whatsapp-accounts.index');

    
    // Rotas de Contas do WhatsApp
    // Route::prefix('whatsapp-accounts')->group(function () {
    //     Route::get('/', function () {
    //         return response()->json(['accounts' => auth()->user()->whatsappAccounts ?? []]);
    //     })->name('whatsapp-accounts.index');
    // });
    
    // Rotas de Treinamento da IA
    Route::prefix('ai')->group(function () {
        Route::get('/training-data', function () {
            return response()->json(['training_data' => []]);
        })->name('ai.training.index');
        Route::post('/training-data', function () {
            return response()->json(['message' => 'Training data created']);
        })->middleware('permission:ai.training')->name('ai.training.store');
    });

    Route::post('/media/upload', [MediaController::class, 'upload'])->name('media.upload');
});

// Fallback para rotas não encontradas na API
Route::fallback(function () {
    return response()->json([
        'message' => 'Endpoint não encontrado.',
    ], 404);
});
