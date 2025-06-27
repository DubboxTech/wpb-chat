<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\WhatsAppConversation;
use App\Models\Campaign;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppContact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    /**
     * Exibe o dashboard principal com todos os dados necessários.
     */
    public function index()
    {
        return Inertia::render('Dashboard', [
            'stats' => $this->getDashboardStats(),
            'chartData' => [
                'messagesByHour' => $this->getMessagesByHourData(),
                'conversationStatus' => $this->getConversationStatusData(),
            ],
            'mapData' => $this->getContactsLocationData(),
            'recentActivity' => $this->getRecentActivity(),
        ]);
    }

    /**
     * Agrega as principais estatísticas para os cards.
     */
    private function getDashboardStats()
    {
        $activeConversations = WhatsAppConversation::where('status', 'open')->count();
        $activeCampaigns = Campaign::whereIn('status', ['running', 'scheduled'])->count();
        $messagesToday = WhatsAppMessage::whereDate('created_at', today())->count();
        
        $aiMessages = WhatsAppMessage::where('is_ai_generated', true)->where('created_at', '>=', now()->subDays(7))->count();
        $totalInboundMessages = WhatsAppMessage::where('direction', 'inbound')->where('created_at', '>=', now()->subDays(7))->count();
        $aiResolutionRate = $totalInboundMessages > 0 ? round(($aiMessages / $totalInboundMessages) * 100) : 0;

        return [
            'activeConversations' => $activeConversations,
            'activeCampaigns' => $activeCampaigns,
            'messagesToday' => $messagesToday,
            'aiResolutionRate' => $aiResolutionRate,
        ];
    }

    /**
     * Prepara os dados para o gráfico de mensagens por hora.
     */
    private function getMessagesByHourData()
    {
        $messages = WhatsAppMessage::where('created_at', '>=', now()->subDay())
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('count', 'hour')
            ->all();

        // Garante que todas as 24 horas estão presentes no array
        $labels = range(0, 23);
        $data = array_map(fn($hour) => $messages[$hour] ?? 0, $labels);

        return [
            'labels' => array_map(fn($h) => str_pad($h, 2, '0', STR_PAD_LEFT) . 'h', $labels),
            'data' => $data,
        ];
    }

    /**
     * Prepara os dados para o gráfico de status das conversas.
     */
    private function getConversationStatusData()
    {
        $statusCounts = WhatsAppConversation::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'labels' => ['Abertas', 'Pendentes', 'Resolvidas', 'Fechadas'],
            'data' => [
                $statusCounts['open'] ?? 0,
                $statusCounts['pending'] ?? 0,
                $statusCounts['resolved'] ?? 0,
                $statusCounts['closed'] ?? 0,
            ],
        ];
    }
    
    /**
     * Prepara os dados de geolocalização dos contatos.
     */
    private function getContactsLocationData()
    {
        // Pega todos os contatos que possuem um CEP no campo customizado
        $contacts = WhatsAppContact::where('custom_fields->cep', '!=', null)->get();

        return $contacts->map(function ($contact) {
            $cep = $contact->custom_fields['cep'] ?? null;
            if (!$cep) return null;

            $coords = $this->getCoordsForCep($cep);

            return [
                'name' => $contact->name,
                'cep' => $cep,
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
            ];
        })->filter()->values(); // Remove nulos e reindexa o array
    }

    /**
     * Simula a conversão de CEP para coordenadas (substituir por uma API real em produção).
     */
    private function getCoordsForCep(string $cep): array
    {
        // Base de coordenadas de Brasília para simulação
        $baseLat = -15.7942;
        $baseLng = -47.8825;

        // Gera um desvio aleatório para simular diferentes localizações
        // Isso cria pins espalhados ao redor de Brasília
        $lat = $baseLat + (rand(-500, 500) / 10000);
        $lng = $baseLng + (rand(-500, 500) / 10000);

        return ['lat' => $lat, 'lng' => $lng];
    }
    
    /**
     * Busca atividades recentes para exibição no dashboard.
     */
    private function getRecentActivity()
    {
        $recentConversations = WhatsAppConversation::with(['contact', 'lastMessage'])
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function($conversation) {
                return [
                    'id' => $conversation->id,
                    'contact' => ['name' => $conversation->contact->name ?? 'Contato'],
                    'lastMessage' => Str::limit($conversation->lastMessage?->content ?? '...', 40),
                    'time' => $conversation->updated_at->diffForHumans(),
                    'unread' => $conversation->unread_count ?? 0
                ];
            });
        
        $recentCampaigns = Campaign::orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function($campaign) {
                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'contacts' => $campaign->total_contacts,
                    'status' => $campaign->status,
                    'progress' => $campaign->getProgressPercentage()
                ];
            });
        
        return [
            'conversations' => $recentConversations,
            'campaigns' => $recentCampaigns
        ];
    }
}
