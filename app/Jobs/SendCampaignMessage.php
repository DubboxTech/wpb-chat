<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Services\WhatsApp\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Campaign $campaign;
    public CampaignContact $campaignContact;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(Campaign $campaign, CampaignContact $campaignContact)
    {
        $this->campaign = $campaign;
        $this->campaignContact = $campaignContact;
    }

    /**
     * Execute the job.
     */
    public function handle(CampaignService $campaignService): void
    {
        // Check if campaign is still running
        if (!$this->campaign->isRunning()) {
            Log::info('Campaign message job skipped - campaign not running', [
                'campaign_id' => $this->campaign->id,
                'campaign_contact_id' => $this->campaignContact->id,
                'campaign_status' => $this->campaign->status
            ]);
            return;
        }

        // Check if contact is still pending
        if ($this->campaignContact->status !== 'pending') {
            Log::info('Campaign message job skipped - contact not pending', [
                'campaign_id' => $this->campaign->id,
                'campaign_contact_id' => $this->campaignContact->id,
                'contact_status' => $this->campaignContact->status
            ]);
            return;
        }

        // Send the message
        $result = $campaignService->sendCampaignMessage($this->campaign, $this->campaignContact);

        // Recarrega a campanha para garantir que temos os dados mais recentes
        $this->campaign->refresh();

        // Conta quantos contatos ainda estão pendentes
        $pendingContacts = CampaignContact::where('campaign_id', $this->campaign->id)
                                          ->where('status', 'pending')
                                          ->count();

        // Se não houver mais contatos pendentes, finaliza a campanha
        if ($pendingContacts === 0) {
            $this->campaign->complete(); // Utiliza o método do modelo Campaign
        }

        if (!$result['success']) {
            Log::error('Campaign message job failed', [
                'campaign_id' => $this->campaign->id,
                'campaign_contact_id' => $this->campaignContact->id,
                'error' => $result['message']
            ]);

            // If this is the last attempt, mark as failed
            if ($this->attempts() >= $this->tries) {
                $this->campaignContact->update([
                    'status' => 'failed',
                    'error_message' => 'Max retry attempts reached: ' . $result['message']
                ]);
            } else {
                // Retry the job
                $this->release(30); // Retry after 30 seconds
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Campaign message job failed with exception', [
            'campaign_id' => $this->campaign->id,
            'campaign_contact_id' => $this->campaignContact->id,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Mark campaign contact as failed
        $this->campaignContact->update([
            'status' => 'failed',
            'error_message' => 'Job failed: ' . $exception->getMessage()
        ]);

        // Update campaign statistics
        $this->campaign->updateStats();
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'campaign:' . $this->campaign->id,
            'contact:' . $this->campaignContact->contact_id,
            'whatsapp-campaign'
        ];
    }
}

