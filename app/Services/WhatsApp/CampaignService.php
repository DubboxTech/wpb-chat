<?php

namespace App\Services\WhatsApp;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\CampaignMessage;
use App\Models\WhatsAppContact;
use App\Models\ContactSegment;
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
        if (!$campaign->isReadyToRun() && $campaign->status !== 'draft') {
            throw new Exception('Campaign is not ready to run');
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

            // Personalize template parameters
            $parameters = $this->personalizeParameters(
                $campaign->template_parameters ?? [],
                $contact,
                $campaignContact->personalized_parameters ?? []
            );

            // Send template message
            $result = $this->whatsappService->sendTemplateMessage(
                $contact->phone_number,
                $campaign->template_name,
                $parameters
            );

            if ($result['success']) {
                $messageId = $result['data']['messages'][0]['id'] ?? null;

                // Update campaign contact status
                $campaignContact->update([
                    'status' => 'sent',
                    'message_id' => $messageId,
                    'sent_at' => now(),
                ]);

                // Create campaign message record
                CampaignMessage::create([
                    'campaign_id' => $campaign->id,
                    'campaign_contact_id' => $campaignContact->id,
                    'whatsapp_api_message_id' => $messageId,
                    'status' => 'sent',
                    'api_response' => $result['data'],
                    'sent_at' => now(),
                ]);

                Log::info('Campaign message sent successfully', [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'message_id' => $messageId
                ]);

            } else {
                // Update campaign contact with error
                $campaignContact->update([
                    'status' => 'failed',
                    'error_message' => $result['message'],
                ]);

                Log::error('Campaign message failed', [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'error' => $result['message']
                ]);
            }

            // Update campaign statistics
            $campaign->updateStats();

            // Check if campaign is completed
            $this->checkCampaignCompletion($campaign);

            return $result;

        } catch (Exception $e) {
            $campaignContact->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Campaign message exception: ' . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'contact_id' => $campaignContact->contact_id
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Personalize template parameters for contact.
     */
    protected function personalizeParameters(array $templateParams, WhatsAppContact $contact, array $personalizedParams = []): array
    {
        $finalParams = ['body' => [], 'header' => []];

        // Processa parâmetros do header (mídia)
        if (isset($templateParams['header']) && $templateParams['header']['type'] === 'media') {
            $format = strtolower($this->getMediaFormatFromUrl($templateParams['header']['url']));
            $finalParams['header'][] = [
                'type' => $format,
                $format => ['link' => $templateParams['header']['url']]
            ];
        }

        // Processa parâmetros do corpo (variáveis dinâmicas)
        if(isset($templateParams['body'])) {
            foreach ($templateParams['body'] as $param) {
                $paramValue = '';
                if ($param['type'] === 'field') {
                    // Mapeamento por campo do contato
                    if (str_starts_with($param['value'], 'custom.')) {
                        $customKey = substr($param['value'], 7);
                        $paramValue = $contact->custom_fields[$customKey] ?? '';
                    } else {
                        $paramValue = $contact->{$param['value']} ?? '';
                    }
                } else {
                    // Valor manual
                    $paramValue = $param['value'];
                }

                $finalParams['body'][] = ['type' => 'text', 'text' => $paramValue];
            }
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
     * Check if campaign is completed.
     */
    protected function checkCampaignCompletion(Campaign $campaign): void
    {
        $pendingCount = $campaign->campaignContacts()
            ->where('status', 'pending')
            ->count();

        if ($pendingCount === 0 && $campaign->isRunning()) {
            $campaign->complete();
            
            Log::info('Campaign completed', [
                'campaign_id' => $campaign->id,
                'total_contacts' => $campaign->total_contacts,
                'sent_count' => $campaign->sent_count,
                'failed_count' => $campaign->failed_count
            ]);
        }
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
        return [
            'total_contacts' => $campaign->total_contacts,
            'sent_count' => $campaign->sent_count,
            'delivered_count' => $campaign->delivered_count,
            'read_count' => $campaign->read_count,
            'failed_count' => $campaign->failed_count,
            'progress_percentage' => $campaign->getProgressPercentage(),
            'success_rate' => $campaign->getSuccessRate(),
            'read_rate' => $campaign->getReadRate(),
            'status' => $campaign->status,
            'started_at' => $campaign->started_at,
            'completed_at' => $campaign->completed_at,
        ];
    }
}

