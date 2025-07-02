<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Campaign;
use App\Models\WhatsAppContact;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $query = Campaign::with('whatsappAccount')
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $campaigns = $query->paginate(10)->withQueryString();

        $tags = WhatsAppContact::whereNotNull('tags')
                                ->select('tags')
                                ->get()
                                ->pluck('tags')
                                ->flatten()
                                ->unique()
                                ->values()
                                ->all();

        return Inertia::render('Campaigns', [
            'campaigns' => $campaigns,
            'filters' => $request->only(['search', 'status']),
            'segments' => $tags,
        ]);
    }

    public function report(Campaign $campaign)
    {
        // Verifica se o utilizador autenticado é o dono da campanha
        if ($campaign->user_id !== auth()->id()) {
            abort(403, 'Acesso não autorizado.');
        }

        // Garante que apenas campanhas concluídas podem ter relatórios
        if ($campaign->status !== 'completed') {
            abort(404, 'Relatórios estão disponíveis apenas para campanhas concluídas.');
        }

        // Renderiza a página Inertia, passando os dados da campanha
        return Inertia::render('Campaigns/Report', [
            'campaign' => $campaign->load('whatsappAccount'), // Carrega a relação para ter os dados da conta
        ]);
    }
}