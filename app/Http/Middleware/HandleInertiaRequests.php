<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;
use App\Models\WhatsAppConversation;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user,
                // Compartilha a contagem total de mensagens não lidas com todas as páginas.
                // Considera conversas atribuídas ao usuário ou sem atribuição.
                'unreadCount' => $user ? WhatsAppConversation::where(function ($query) use ($user) {
                    $query->where('assigned_user_id', $user->id)
                          ->orWhereNull('assigned_user_id');
                })->where('status', '!=', 'closed')->sum('unread_count') : 0,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }
}
