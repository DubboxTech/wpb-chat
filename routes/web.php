<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\ConversationController; // 1. Importe o controller
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\AiTrainingController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ProfileController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Redireciona a raiz para a página de login se não estiver autenticado
Route::get('/', function () {
    return redirect()->route('login');
});

// Agrupa as rotas que exigem autenticação
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations');

    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns');

    Route::get('/ai/training', function () {
        return Inertia::render('AI/Training');
    })->name('ai.training');

    Route::get('/profile', function () {
        return Inertia::render('Profile/Show');
    })->name('profile');

    Route::get('/settings', function () {
        return Inertia::render('Settings');
    })->name('settings');

    // Rota para fazer logout
    Route::post('/logout', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    })->name('logout');
});

// --- ROTAS DE AUTENTICAÇÃO PÚBLICAS ---

// Rota para EXIBIR a página de login
Route::get('/login', function () {
    return Inertia::render('Auth/Login');
})->name('login')->middleware('guest');

// Rota para PROCESSAR a submissão do formulário de login
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'string', 'email'],
        'password' => ['required', 'string'],
    ]);

    if (Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
        $request->session()->regenerate();
        return redirect()->intended('dashboard');
    }

    return back()->withErrors([
        'email' => 'As credenciais fornecidas são inválidas.',
    ])->onlyInput('email');
});