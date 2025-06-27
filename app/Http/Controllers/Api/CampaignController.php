<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\WhatsAppTemplate;
use App\Models\WhatsAppAccount; 
use App\Services\WhatsApp\CampaignService;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CampaignController extends Controller
{
    protected CampaignService $campaignService;
    protected WhatsAppBusinessService $whatsappService; // Adicione a propriedade

    // Injete o WhatsAppBusinessService no construtor
    public function __construct(CampaignService $campaignService, WhatsAppBusinessService $whatsappService)
    {
        $this->campaignService = $campaignService;
        $this->whatsappService = $whatsappService;
    }
    

    /**
     * Display a listing of campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Campaign::with(['user', 'whatsappAccount'])
            ->where('user_id', auth()->id());

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Sort by created_at desc by default
        $query->orderBy('created_at', 'desc');

        $campaigns = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * Store a newly created campaign.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:immediate,scheduled,recurring',
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'template_name' => 'required|string',
            'template_parameters' => 'nullable|array',
            'segment_filters' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $campaignData = $request->all();
            $campaignData['user_id'] = auth()->id();
            $campaignData['status'] = $request->filled('scheduled_at') ? 'scheduled' : 'draft';

            $campaign = $this->campaignService->createCampaign($campaignData);

            // 2. Se a campanha NÃO foi agendada (ou seja, é imediata),
            // pedimos ao serviço para iniciá-la.
            if (!$request->filled('scheduled_at')) {
                $this->campaignService->startCampaign($campaign);
            }

            return response()->json([
                'success' => true,
                'message' => 'Campanha criada com sucesso.',
                'campaign' => $campaign->load(['user', 'whatsappAccount']),
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Erro ao criar campanha: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar campanha: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified campaign.
     */
    public function show(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        $campaign->load(['user', 'whatsappAccount']);
        $analytics = $this->campaignService->getCampaignAnalytics($campaign);

        return response()->json([
            'success' => true,
            'campaign' => $campaign,
            'analytics' => $analytics,
        ]);
    }

    /**
     * Update the specified campaign.
     */
    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        // Only allow updates for draft campaigns
        if ($campaign->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Apenas campanhas em rascunho podem ser editadas.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'template_parameters' => 'nullable|array',
            'segment_filters' => 'required|array',
            'scheduled_at' => 'nullable|date|after:now',
            'rate_limit_per_minute' => 'integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $campaign->update($request->all());

        // Reapply segment filters if changed
        if ($request->has('segment_filters')) {
            // Clear existing campaign contacts
            $campaign->campaignContacts()->delete();
            
            // Apply new filters
            $this->campaignService->applyCampaignSegments($campaign, $request->segment_filters);
        }

        return response()->json([
            'success' => true,
            'message' => 'Campanha atualizada com sucesso.',
            'campaign' => $campaign->load(['user', 'whatsappAccount']),
        ]);
    }

    /**
     * Start a campaign.
     */
    public function start(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        try {
            $this->campaignService->startCampaign($campaign);

            return response()->json([
                'success' => true,
                'message' => 'Campanha iniciada com sucesso.',
                'campaign' => $campaign->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao iniciar campanha: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pause a campaign.
     */
    public function pause(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        if (!$campaign->isRunning()) {
            return response()->json([
                'success' => false,
                'message' => 'Campanha não está em execução.',
            ], 422);
        }

        $this->campaignService->pauseCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campanha pausada com sucesso.',
            'campaign' => $campaign->fresh(),
        ]);
    }

    /**
     * Resume a campaign.
     */
    public function resume(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        if ($campaign->status !== 'paused') {
            return response()->json([
                'success' => false,
                'message' => 'Campanha não está pausada.',
            ], 422);
        }

        $this->campaignService->resumeCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campanha retomada com sucesso.',
            'campaign' => $campaign->fresh(),
        ]);
    }

    /**
     * Cancel a campaign.
     */
    public function cancel(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        if ($campaign->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Campanha já foi concluída.',
            ], 422);
        }

        $this->campaignService->cancelCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campanha cancelada com sucesso.',
            'campaign' => $campaign->fresh(),
        ]);
    }

    /**
     * Get campaign analytics.
     */
    public function analytics(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        $analytics = $this->campaignService->getCampaignAnalytics($campaign);

        return response()->json([
            'success' => true,
            'analytics' => $analytics,
        ]);
    }

    /**
     * Remove the specified campaign.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        // Check ownership
        if ($campaign->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado.',
            ], 403);
        }

        // Only allow deletion of draft or completed campaigns
        if (!in_array($campaign->status, ['draft', 'completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas campanhas em rascunho, concluídas ou canceladas podem ser excluídas.',
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campanha excluída com sucesso.',
        ]);
    }

    /**
     * Get available accounts for campaigns.
     */
    public function accounts(): JsonResponse
    {
        // Aqui você pode adicionar lógica para verificar as contas do usuário logado
        $accounts = WhatsAppAccount::where('status', 'active')->get();
        return response()->json(['success' => true, 'accounts' => $accounts]);
    }


    /**
     * Get available templates for campaigns from Meta API.
     */
    public function templates(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'ID da conta WhatsApp é obrigatório.'], 422);
        }

        try {
            $account = WhatsAppAccount::find($request->whatsapp_account_id);
            $this->whatsappService->setAccount($account);
            $result = $this->whatsappService->getTemplates();

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

