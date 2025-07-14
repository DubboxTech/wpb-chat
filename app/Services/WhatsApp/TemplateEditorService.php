<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TemplateEditorService
{
    protected WhatsAppBusinessService $whatsappService;

    public function __construct(WhatsAppBusinessService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Lista os templates de uma conta específica.
     *
     * @param WhatsAppAccount $account
     * @return array
     */
    public function listTemplates(WhatsAppAccount $account): array
    {
        try {
            $this->whatsappService->setAccount($account);
            $result = $this->whatsappService->getAllTemplates();

            // Retorna apenas os dados dos templates
            return $result['data'] ?? [];
        } catch (Exception $e) {
            Log::error('Erro ao listar templates no TemplateEditorService', [
                'account_id' => $account->id,
                'error' => $e->getMessage()
            ]);
            // Retorna um array vazio em caso de erro para não quebrar a UI
            return [];
        }
    }

    /**
     * Cria um novo template de mensagem.
     *
     * @param WhatsAppAccount $account
     * @param array $data
     * @return array
     */
    public function createTemplate(WhatsAppAccount $account, array $data): array
    {
        $this->whatsappService->setAccount($account);
        return $this->whatsappService->createTemplate($data);
    }

    /**
     * Deleta um template de mensagem pelo nome.
     *
     * @param WhatsAppAccount $account
     * @param string $templateName
     * @return array
     */
    public function deleteTemplate(WhatsAppAccount $account, string $templateName): array
    {
        $this->whatsappService->setAccount($account);
        return $this->whatsappService->deleteTemplate($templateName);
    }
}