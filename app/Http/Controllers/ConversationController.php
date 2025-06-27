<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppBusinessService;
use App\Services\AI\GeminiAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // 1. Importar a classe Str
use App\Jobs\FetchProfilePicture;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    protected $whatsappService;
    protected $aiService;
    
    public function __construct(
        WhatsAppBusinessService $whatsappService,
        GeminiAIService $aiService
    ) {
        $this->whatsappService = $whatsappService;
        $this->aiService = $aiService;
    }
    
    public function index(Request $request)
    {
        $conversations = $this->getConversations($request);
        
        return Inertia::render('Conversations', [
            'conversations' => $conversations,
            'filters' => [
                'status' => $request->get('status'),
                'search' => $request->get('search')
            ]
        ]);
    }

    public function show($id)
    {
        $conversation = WhatsAppConversation::with(['contact', 'messages' => function($query) {
            $query->orderBy('created_at', 'asc');
        }])->findOrFail($id);
        
        // Mark as read
        $conversation->update(['unread_count' => 0]);
        
        $messages = $conversation->messages->map(function($message) {
            return [
                'id' => $message->id,
                'content' => $message->content,
                'direction' => $message->direction,
                'status' => $message->status,
                'time' => $message->created_at->format('H:i'),
                'isAiGenerated' => $message->is_ai_generated ?? false,
                'createdAt' => $message->created_at,
                'type' => $message->type,
                'media' => $message->media, // **MUDANÇA AQUI: Passa o objeto de mídia completo**
            ];
        });
        
        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'contact' => [
                    'name' => $conversation->contact->name ?? $conversation->contact->phone_number,
                    'phone' => $conversation->contact->phone_number,
                    'avatar' => $conversation->contact->profile_picture['url'] ?? null
                ],
                'status' => $conversation->status,
                'isAiHandled' => $conversation->is_ai_handled ?? false,
                'messages' => $messages
            ]
        ]);
    }
    public function sendMessage(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', Rule::in(['text', 'image', 'video', 'audio', 'document', 'location', 'template'])],
            'content' => 'required_if:type,text|string|max:4096',
            'media_url' => 'required_if:type,image,video,audio,document|url',
            'caption' => 'nullable|string|max:1024',
            'latitude' => 'required_if:type,location|numeric',
            'longitude' => 'required_if:type,location|numeric',
            'template_name' => 'required_if:type,template|string',
            'template_params' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Dados inválidos.', 'details' => $validator->errors()], 422);
        }

        $conversation = WhatsAppConversation::with('whatsappAccount')->findOrFail($id);
        $contact = $conversation->contact;
        $type = $request->input('type');
        $dataForService = $request->except(['type']); // Passa todos os outros dados

        try {
            $this->whatsappService->setAccount($conversation->whatsappAccount);
            $response = $this->whatsappService->sendMessage($contact->phone_number, $type, $dataForService);
            
            if (!$response['success']) {
                throw new \Exception($response['message'] ?? 'Falha ao enviar mensagem via API do WhatsApp.');
            }

            // Salva a mensagem no banco de dados com os dados corretos
            $message = $conversation->messages()->create([
                'contact_id' => $contact->id,
                'message_id' => Str::uuid(),
                'whatsapp_message_id' => $response['data']['messages'][0]['id'] ?? null,
                'direction' => 'outbound',
                'type' => $type,
                'status' => 'sent',
                'content' => $request->input('content') ?? $request->input('caption'),
                'media' => $request->has('media_url') ? ['url' => $request->input('media_url')] : null,
                'template_name' => $request->input('template_name'),
                'template_parameters' => $request->input('template_params'),
                'is_ai_generated' => false,
                'user_id' => auth()->id()
            ]);
            
            $conversation->touch(); // Apenas atualiza o timestamp 'updated_at'

            return response()->json(['message' => $message->fresh()]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao enviar mensagem', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao enviar mensagem: ' . $e->getMessage()], 500);
        }
    }
    
    public function toggleAI(Request $request, $id)
    {
        $conversation = WhatsAppConversation::findOrFail($id);
        
        $conversation->update([
            'is_ai_handled' => !$conversation->is_ai_handled
        ]);
        
        return response()->json([
            'isAiHandled' => $conversation->is_ai_handled
        ]);
    }
    
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:open,pending,resolved,closed'
        ]);
        
        $conversation = WhatsAppConversation::findOrFail($id);
        $conversation->update(['status' => $request->status]);
        
        return response()->json([
            'status' => $conversation->status
        ]);
    }
    
    public function assignAgent(Request $request, $id)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);
        
        $conversation = WhatsAppConversation::findOrFail($id);
        $conversation->update([
            'assigned_user_id' => $request->user_id,
            'is_ai_handled' => false
        ]);
        
        return response()->json([
            'assignedUserId' => $conversation->assigned_user_id
        ]);
    }
    
    public function getMessageHistory(Request $request, $id)
    {
        $conversation = WhatsAppConversation::findOrFail($id);
        
        $messages = WhatsAppMessage::where('conversation_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);
        
        return response()->json($messages);
    }
    
    public function searchMessages(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:3'
        ]);
        
        $messages = WhatsAppMessage::where('content', 'like', '%' . $request->query . '%')
            ->with(['conversation.contact'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return response()->json($messages);
    }
    
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'conversation_ids' => 'required|array',
            'conversation_ids.*' => 'exists:whatsapp_conversations,id',
            'status' => 'required|in:open,pending,resolved,closed'
        ]);
        
        WhatsAppConversation::whereIn('id', $request->conversation_ids)
            ->update(['status' => $request->status]);
        
        return response()->json([
            'message' => 'Status atualizado para ' . count($request->conversation_ids) . ' conversas'
        ]);
    }
    
    public function getStats()
    {
        $stats = [
            'total' => WhatsAppConversation::count(),
            'open' => WhatsAppConversation::where('status', 'open')->count(),
            'pending' => WhatsAppConversation::where('status', 'pending')->count(),
            'resolved' => WhatsAppConversation::where('status', 'resolved')->count(),
            'aiHandled' => WhatsAppConversation::where('is_ai_handled', true)->count(),
            'avgResponseTime' => $this->getAverageResponseTime(),
            'todayMessages' => WhatsAppMessage::whereDate('created_at', today())->count()
        ];
        
        return response()->json($stats);
    }
    
    private function getAverageResponseTime()
    {
        $conversations = WhatsAppConversation::with(['messages' => function($query) {
            $query->orderBy('created_at', 'asc');
        }])->where('updated_at', '>=', now()->subDays(7))->get();
        
        $responseTimes = [];
        
        foreach ($conversations as $conversation) {
            $messages = $conversation->messages;
            $lastInbound = null;
            
            foreach ($messages as $message) {
                if ($message->direction === 'inbound') {
                    $lastInbound = $message;
                } elseif ($message->direction === 'outbound' && $lastInbound) {
                    $responseTime = $message->created_at->diffInMinutes($lastInbound->created_at);
                    $responseTimes[] = $responseTime;
                    $lastInbound = null;
                }
            }
        }
        
        return count($responseTimes) > 0 ? round(array_sum($responseTimes) / count($responseTimes)) : 0;
    }

    public function getConversations(Request $request)
    {
        Log::info('Buscando conversas', [
            'user_id' => auth()->id(),
            'filters' => $request->all()
        ]);

        $query = WhatsAppConversation::with(['contact', 'lastMessage'])
            ->orderBy('updated_at', 'desc');
            
        
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }
        
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->whereHas('contact', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            })->orWhereHas('messages', function($q) use ($search) {
                $q->where('content', 'like', "%{$search}%");
            });
        }
        
        $conversations = $query->paginate(20);
        
        return $conversations->through(function($conversation) {
            return [
                'id' => $conversation->id,
                'contact' => [
                    'name' => $conversation->contact->name ?? $conversation->contact->phone_number,
                    'phone' => $conversation->contact->phone_number,
                    'avatar' => $conversation->contact->profile_picture['url'] ?? null
                ],
                'lastMessage' => $conversation->lastMessage?->content ?? '',
                'lastMessageTime' => $conversation->lastMessage?->created_at?->diffForHumans() ?? '',
                'status' => $conversation->status,
                'unreadCount' => $conversation->unread_count ?? 0,
                'isAiHandled' => $conversation->is_ai_handled ?? false,
                'updatedAt' => $conversation->updated_at
            ];
        });
    }
}
