<?php

namespace App\Services\WhatsApp;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\CampaignMessage;
use App\Models\WhatsAppContact;
use App\Models\ContactSegment;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage; 
use App\Jobs\SendCampaignMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class CampaignService
{
    protected WhatsAppBusinessService $whatsappService;

    public function __construct(WhatsAppBusinessService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Create a new campaign.
     */
    public function createCampaign(array $data): Campaign
    {
        DB::beginTransaction();

        try {
            $campaign = Campaign::create($data);

            // Apply segment filters and add contacts
            if (isset($data['segment_filters'])) {
                $this->applyCampaignSegments($campaign, $data['segment_filters']);
            }

            DB::commit();

            Log::info('Campaign created successfully', [
                'campaign_id' => $campaign->id,
                'total_contacts' => $campaign->total_contacts
            ]);

            return $campaign;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create campaign: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Apply segment filters to campaign.
     */
    public function applyCampaignSegments(Campaign $campaign, array $filters): void
    {
        $query = WhatsAppContact::query()->where('status', 'active');

        // Apply filters
        foreach ($filters as $filter) {
            $this->applyFilter($query, $filter);
        }

        $contacts = $query->get();

        // Create campaign contacts
        foreach ($contacts as $contact) {
            CampaignContact::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'status' => 'pending',
            ]);
        }

        $campaign->update(['total_contacts' => $contacts->count()]);
    }

    /**
     * Apply individual filter to query.
     */
    protected function applyFilter($query, array $filter): void
    {
        $field = $filter['field'];
        $operator = $filter['operator'];
        $value = $filter['value'];

        switch ($field) {
            case 'tags':
                if ($operator === 'contains') {
                    $query->whereJsonContains('tags', $value);
                } elseif ($operator === 'not_contains') {
                    $query->whereJsonDoesntContain('tags', $value);
                }
                break;

            case 'last_seen_at':
                if ($operator === 'after') {
                    $query->where('last_seen_at', '>', $value);
                } elseif ($operator === 'before') {
                    $query->where('last_seen_at', '<', $value);
                } elseif ($operator === 'between') {
                    $query->whereBetween('last_seen_at', $value);
                }
                break;

            case 'custom_fields':
                $customField = $filter['custom_field'];
                if ($operator === 'equals') {
                    $query->whereJsonContains("custom_fields->{$customField}", $value);
                } elseif ($operator === 'not_equals') {
                    $query->whereJsonDoesntContain("custom_fields->{$customField}", $value);
                }
                break;

            case 'phone_number':
                if ($operator === 'starts_with') {
                    $query->where('phone_number', 'like', $value . '%');
                } elseif ($operator === 'ends_with') {
                    $query->where('phone_number', 'like', '%' . $value);
                } elseif ($operator === 'contains') {
                    $query->where('phone_number', 'like', '%' . $value . '%');
                }
                break;

            default:
                if ($operator === 'equals') {
                    $query->where($field, $value);
                } elseif ($operator === 'not_equals') {
                    $query->where($field, '!=', $value);
                } elseif ($operator === 'like') {
                    $query->where($field, 'like', '%' . $value . '%');
                }
                break;
        }
    }

    /**
     * Start campaign execution.
     */
    public function startCampaign(Campaign $campaign): void
    {
        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            throw new Exception("Campaign cannot be started because its status is '{$campaign->status}'.");
        }

        $campaign->start();

        // Dispatch jobs for sending messages
        $this->dispatchCampaignJobs($campaign);

        Log::info('Campaign started', ['campaign_id' => $campaign->id]);
    }

    /**
     * Dispatch jobs for campaign message sending.
     */
    protected function dispatchCampaignJobs(Campaign $campaign): void
    {
        $pendingContacts = $campaign->campaignContacts()
            ->where('status', 'pending')
            ->get();

        // **** INÍCIO DA CORREÇÃO ****
        // Garante que a taxa de limite seja um número positivo.
        // Se for nulo, 0, ou não definido, usamos um valor padrão seguro (ex: 20).
        $messagesPerMinute = $campaign->rate_limit_per_minute > 0 ? $campaign->rate_limit_per_minute : 20;

        // Agora, esta divisão é sempre segura.
        $delayBetweenMessages = 60 / $messagesPerMinute; // segundos
        // **** FIM DA CORREÇÃO ****

        $delay = 0;

        foreach ($pendingContacts as $campaignContact) {
            SendCampaignMessage::dispatch($campaign, $campaignContact)
                ->delay(now()->addSeconds($delay));

            $delay += $delayBetweenMessages;
        }
    }

    /**
     * Send individual campaign message.
     */
    public function sendCampaignMessage(Campaign $campaign, CampaignContact $campaignContact): array
    {
        try {
            $contact = $campaignContact->contact;
            $this->whatsappService->setAccount($campaign->whatsappAccount);

            // ---> INÍCIO DA CORREÇÃO <---
            // Passo 1: Buscar os detalhes do template para obter o idioma correto.
            $templateData = $this->whatsappService->getTemplateByName($campaign->template_name);

            if (!$templateData) {
                throw new Exception("Template '{$campaign->template_name}' not found on Meta Business account.");
            }
            
            // Extrai o código do idioma do template encontrado.
            $languageCode = $templateData['language'];
            // ---> FIM DA CORREÇÃO <---

            $personalizedParams = $this->personalizeParameters($campaign->template_parameters, $contact);

            // Passo 2: Enviar a mensagem usando o idioma dinâmico.
            // A assinatura de sendTemplateMessage deve ser (to, template_name, language_code, params)
            $result = $this->whatsappService->sendTemplateMessage(
                $contact->phone_number,
                $campaign->template_name,
                $languageCode, // 3º argumento agora é a string do idioma
                $personalizedParams // 4º argumento agora é o array de parâmetros
            );
            
            // ... resto do método (lógica de sucesso e falha) continua igual ...
            if ($result['success']) {
                $messageId = $result['data']['messages'][0]['id'] ?? null;
                $campaignContact->update(['status' => 'sent', 'message_id' => $messageId, 'sent_at' => now()]);
                // ... lógica para salvar no histórico, etc.
                $this->addMessageToConversationHistory($campaign, $contact, $messageId, $personalizedParams);
            } else {
                $errorMessage = $result['message'] ?? 'Failed to send message.';
                if (isset($result['data']['error']['message'])) {
                    $errorMessage = $result['data']['error']['message'];
                }
                $campaignContact->update(['status' => 'failed', 'error_message' => $errorMessage]);
            }
            
            $this->checkCampaignCompletion($campaign);
            return $result;

        } catch (Exception $e) {
            $campaignContact->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error('Campaign message job failed', [
                'campaign_id' => $campaign->id,
                'campaign_contact_id' => $campaignContact->id,
                'error' => $e->getMessage()
            ]);
            // Não relance a exceção para que o job não tente novamente em caso de erro definitivo
            // e pare a campanha.
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Adiciona uma cópia da mensagem da campanha no histórico da conversa individual.
     * Esta é a correção para o erro de 'message_id' sem valor padrão.
     */
    private function addMessageToConversationHistory(Campaign $campaign, WhatsAppContact $contact, ?string $apiMessageId, array $personalizedParams): void
    {
        try {
            // Garante que a conversa com o contato exista, ou a cria se for a primeira vez.
            $conversation = WhatsAppConversation::firstOrCreate(
                [
                    'contact_id' => $contact->id,
                    'whatsapp_account_id' => $campaign->whatsapp_account_id
                ],
                [
                    'conversation_id' => \Illuminate\Support\Str::uuid(),
                    'assigned_user_id' => $campaign->user_id
                ]
            );

            // Obtém o corpo da mensagem com as variáveis substituídas para salvar.
            $formattedBody = $this->getFormattedMessageBody($campaign, $personalizedParams);

            // ---> INÍCIO DA CORREÇÃO <---
            $mediaData = null;
            $mediaUrl = null;

            // Verifica se o componente de cabeçalho (header) existe e tem parâmetros.
            if (isset($personalizedParams['header'][0])) {
                $headerParam = $personalizedParams['header'][0];
                
                // Procura pela URL da mídia nos tipos suportados.
                if (isset($headerParam['image']['link'])) {
                    $mediaUrl = $headerParam['image']['link'];
                } elseif (isset($headerParam['video']['link'])) {
                    $mediaUrl = $headerParam['video']['link'];
                } elseif (isset($headerParam['document']['link'])) {
                    $mediaUrl = $headerParam['document']['link'];
                }
            }

            // Se uma URL de mídia foi encontrada, cria o objeto de mídia.
            if ($mediaUrl) {
                $mediaData = [
                    'url' => $mediaUrl,
                    'caption' => $formattedBody
                ];
            }
            // ---> FIM DA CORREÇÃO <---

            $conversation->messages()->create([
                'message_id' => \Illuminate\Support\Str::uuid(),
                'whatsapp_message_id' => $apiMessageId,
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'user_id' => $campaign->user_id,
                'direction' => 'outbound',
                'type' => 'template',
                'content' => $formattedBody,
                'media' => $mediaData, // Salva o objeto de mídia corretamente.
                'status' => 'sent',
                'sent_at' => now(),
                'is_ai_generated' => false,
            ]);

            // Atualiza o timestamp da última mensagem na conversa.
            $conversation->touch('last_message_at');

        } catch (\Exception $e) {
            // Registra um erro detalhado se não for possível salvar a mensagem no histórico.
            Log::error('CampaignService: Failed to add campaign message to conversation history.', [
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Verifica se a campanha foi concluída após o processamento de um contato.
     * Esta é parte da solução para o primeiro erro reportado.
     */
    private function checkCampaignCompletion(Campaign $campaign): void
    {
        // Força o recarregamento do estado da campanha do banco de dados para evitar condições de corrida
        $campaign->refresh();
        
        // Conta quantos contatos ainda estão com o status 'pending'
        $pendingCount = $campaign->campaignContacts()->where('status', 'pending')->count();
        
        // Se a campanha estiver rodando e não houver mais contatos pendentes, marca como concluída.
        if ($campaign->isRunning() && $pendingCount === 0) {
            $campaign->complete(); // Usa o método do modelo para atualizar o status e a data de conclusão
            Log::info('Campaign completed', ['campaign_id' => $campaign->id]);
        }
    }
    /**
     * Personalize template parameters for contact.
     */
    protected function personalizeParameters(?array $templateParams, WhatsAppContact $contact): array
    {
        $finalParams = ['body' => []];
        if (empty($templateParams)) {
            return $finalParams;
        }

        if (isset($templateParams['header']['type']) && $templateParams['header']['type'] === 'media' && !empty($templateParams['header']['url'])) {
            $format = strtolower($this->getMediaFormatFromUrl($templateParams['header']['url']));
            $finalParams['header'][] = ['type' => $format, $format => ['link' => $templateParams['header']['url']]];
        }
        
        $bodyParams = collect($templateParams)->except('header')->sortKeys()->all();

        foreach ($bodyParams as $fieldKey) {
            $paramValue = '';
            if (str_starts_with($fieldKey, 'custom.')) {
                $customKey = substr($fieldKey, 7);
                $paramValue = $contact->custom_fields[$customKey] ?? '';
            } else {
                $paramValue = $contact->{$fieldKey} ?? '';
            }
            
            $finalParams['body'][] = ['type' => 'text', 'text' => (string) $paramValue];
        }
        
        return $finalParams;
    }

    // NOVO: Método auxiliar para determinar o tipo de mídia pela extensão
    private function getMediaFormatFromUrl(string $url): string
    {
        $extension = pathinfo($url, PATHINFO_EXTENSION);
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'png' => 'image',
            'mp4' => 'video',
            'pdf' => 'document',
            default => 'document',
        };
    }
    /**
     * Replace placeholders in parameters.
     */
    protected function replaceParameterPlaceholders(array $parameters, array $replacements): array
    {
        $json = json_encode($parameters);
        
        foreach ($replacements as $placeholder => $value) {
            $json = str_replace($placeholder, $value, $json);
        }

        return json_decode($json, true);
    }

    /**
     * Pause campaign.
     */
    public function pauseCampaign(Campaign $campaign): void
    {
        $campaign->pause();
        Log::info('Campaign paused', ['campaign_id' => $campaign->id]);
    }

    /**
     * Resume campaign.
     */
    public function resumeCampaign(Campaign $campaign): void
    {
        $campaign->resume();
        $this->dispatchCampaignJobs($campaign);
        Log::info('Campaign resumed', ['campaign_id' => $campaign->id]);
    }

    /**
     * Cancel campaign.
     */
    public function cancelCampaign(Campaign $campaign): void
    {
        $campaign->cancel();
        Log::info('Campaign cancelled', ['campaign_id' => $campaign->id]);
    }

    /**
     * Get campaign analytics.
     */
    public function getCampaignAnalytics(Campaign $campaign): array
    {
        // Contagem de status diretamente da tabela de contatos da campanha
        $statusCounts = $campaign->campaignContacts()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalContacts = $campaign->total_contacts;
        $sentCount = $statusCounts->get('sent', 0) + $statusCounts->get('delivered', 0) + $statusCounts->get('read', 0);
        $deliveredCount = $statusCounts->get('delivered', 0) + $statusCounts->get('read', 0);
        $readCount = $statusCounts->get('read', 0);
        $failedCount = $statusCounts->get('failed', 0);

        // Calcula as percentagens com base nos dados em tempo real
        $progressPercentage = $totalContacts > 0 ? round((($sentCount + $failedCount) / $totalContacts) * 100, 2) : 0;
        $successRate = $sentCount > 0 ? round(($deliveredCount / $sentCount) * 100, 2) : 0;
        $readRate = $deliveredCount > 0 ? round(($readCount / $deliveredCount) * 100, 2) : 0;

        return [
            'total_contacts' => $totalContacts,
            'sent_count' => $sentCount,
            'delivered_count' => $deliveredCount,
            'read_count' => $readCount,
            'failed_count' => $failedCount,
            'progress_percentage' => $progressPercentage,
            'success_rate' => $successRate,
            'read_rate' => $readRate,
            'status' => $campaign->status,
            'started_at' => $campaign->started_at,
            'completed_at' => $campaign->completed_at,
        ];
    }

    /**
     * Obtém os dados detalhados do relatório da campanha.
     */
    public function getCampaignReportData(Campaign $campaign): array
    {
        // Obtém as estatísticas gerais calculadas em tempo real.
        $analytics = $this->getCampaignAnalytics($campaign);

        // 1. Prepara os dados para o gráfico de pizza (Doughnut).
        $analytics['chart_status'] = [
            'labels' => ['Entregue', 'Lido', 'Falhou', 'Aguardando Entrega'],
            'data' => [
                $analytics['delivered_count'] - $analytics['read_count'],
                $analytics['read_count'],
                $analytics['failed_count'],
                $analytics['sent_count'] - $analytics['delivered_count'],
            ],
        ];

        // 2. Prepara os dados para o gráfico de linha do tempo.
        $readData = $campaign->campaignContacts()
            ->whereNotNull('read_at')
            ->select(DB::raw('DATE(read_at) as date'), DB::raw('count(*) as total'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->pluck('total', 'date');

        $analytics['chart_read_timeline'] = [
            'labels' => $readData->keys(),
            'data' => $readData->values(),
        ];

        // 3. Adiciona a lista paginada de contatos da campanha.
        $analytics['contacts'] = $campaign->campaignContacts()
            ->with('contact:id,name,phone_number') // Carrega apenas os campos necessários
            ->orderBy('created_at', 'asc') // Ordena por ordem de envio
            ->paginate(20); // Pagina o resultado

        return $analytics;
    }

    private function getFormattedMessageBody(Campaign $campaign, array $personalizedParams): ?string
    {
        try {
            $this->whatsappService->setAccount($campaign->whatsappAccount);
            $templatesResponse = $this->whatsappService->getTemplates();
            if (!$templatesResponse['success']) return null;

            $templateBody = null;
            foreach ($templatesResponse['data'] as $template) {
                if ($template['name'] === $campaign->template_name) {
                    foreach ($template['components'] as $component) {
                        if ($component['type'] === 'BODY') {
                            $templateBody = $component['text'];
                            break 2;
                        }
                    }
                }
            }
            if (!$templateBody) return null;
            
            foreach ($personalizedParams['body'] as $index => $param) {
                $placeholder = '{{' . ($index + 1) . '}}';
                $templateBody = str_replace($placeholder, $param['text'], $templateBody);
            }
            return $templateBody;
        } catch (\Exception $e) {
            Log::error('Could not format message body for logging.', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
}

