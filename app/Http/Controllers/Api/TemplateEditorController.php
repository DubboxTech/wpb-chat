<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\TemplateEditorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TemplateEditorController extends Controller
{
    protected TemplateEditorService $templateEditorService;

    public function __construct(TemplateEditorService $templateEditorService)
    {
        $this->templateEditorService = $templateEditorService;
    }

    /**
     * Lista todos os templates para uma conta WhatsApp.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'ID da conta inválido.'], 422);
        }

        $account = WhatsAppAccount::find($request->whatsapp_account_id);
        $templates = $this->templateEditorService->listTemplates($account);

        return response()->json(['success' => true, 'data' => $templates]);
    }

    /**
     * Cria um novo template de mensagem.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'category' => 'required|string|in:AUTHENTICATION,MARKETING,UTILITY',
            'language' => 'required|string',
            'components' => 'required|array|min:1',
            'components.*.type' => 'required|in:HEADER,BODY,FOOTER,BUTTONS',
            'components.*.text' => 'required_if:components.*.type,BODY,FOOTER|string',
            'components.*.format' => 'required_if:components.*.type,HEADER|in:TEXT,IMAGE,VIDEO,DOCUMENT',
            'components.*.buttons' => 'required_if:components.*.type,BUTTONS|array',
            'components.*.buttons.*.type' => 'required|in:QUICK_REPLY,URL,PHONE_NUMBER',
            'components.*.buttons.*.text' => 'required|string',
            'components.*.buttons.*.url' => 'required_if:components.*.buttons.*.type,URL|url',
            'components.*.buttons.*.phone_number' => 'required_if:components.*.buttons.*.type,PHONE_NUMBER|string',

        ], [
            'name.regex' => 'O nome do modelo pode conter apenas letras minúsculas, números e underscores (_).'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Dados inválidos.', 'errors' => $validator->errors()], 422);
        }

        $account = WhatsAppAccount::find($request->whatsapp_account_id);
        
        $payload = [
            'name' => $request->name,
            'language' => $request->language,
            'category' => $request->category,
            'components' => $request->components
        ];

        $result = $this->templateEditorService->createTemplate($account, $payload);

        if (!($result['success'] ?? false)) {
            return response()->json($result, 400);
        }

        return response()->json($result, 201);
    }

    /**
     * Deleta um template de mensagem.
     */
    public function destroy(Request $request, string $templateName): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'ID da conta inválido.'], 422);
        }

        $account = WhatsAppAccount::find($request->whatsapp_account_id);

        $result = $this->templateEditorService->deleteTemplate($account, $templateName);

        if (!($result['success'] ?? false)) {
            return response()->json($result, 400);
        }

        return response()->json($result, 200);
    }
}