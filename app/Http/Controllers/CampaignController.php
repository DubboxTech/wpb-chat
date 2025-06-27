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
}