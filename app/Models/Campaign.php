<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'whatsapp_account_id',
        'name',
        'description',
        'type',
        'status',
        'template_name',
        'template_parameters',
        'segment_filters',
        'scheduled_at',
        'started_at',
        'completed_at',
        'total_contacts',
        'sent_count',
        'delivered_count',
        'read_count',
        'failed_count',
        'rate_limit_per_minute',
        'recurring_config',
    ];

    protected $casts = [
        'template_parameters' => 'array',
        'segment_filters' => 'array',
        'recurring_config' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user that owns the campaign.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the WhatsApp account for the campaign.
     */
    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class);
    }

    /**
     * Get the campaign contacts.
     */
    public function campaignContacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class);
    }

    /**
     * Get the campaign messages.
     */
    public function campaignMessages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    /**
     * Scope for active campaigns.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['scheduled', 'running']);
    }

    /**
     * Scope for completed campaigns.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for scheduled campaigns.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Check if campaign is ready to run.
     */
    public function isReadyToRun(): bool
    {
        return $this->status === 'scheduled' && 
               $this->scheduled_at && 
               $this->scheduled_at->isPast();
    }

    /**
     * Check if campaign is running.
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if campaign is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Start the campaign.
     */
    public function start(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Pause the campaign.
     */
    public function pause(): void
    {
        $this->update(['status' => 'paused']);
    }

    /**
     * Resume the campaign.
     */
    public function resume(): void
    {
        $this->update(['status' => 'running']);
    }

    /**
     * Complete the campaign.
     */
    public function complete(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Cancel the campaign.
     */
    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    /**
     * Update campaign statistics.
     */
    public function updateStats(): void
    {
        $stats = $this->campaignContacts()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = "read" THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            ')
            ->first();

        $this->update([
            'total_contacts' => $stats->total,
            'sent_count' => $stats->sent,
            'delivered_count' => $stats->delivered,
            'read_count' => $stats->read,
            'failed_count' => $stats->failed,
        ]);
    }

    /**
     * Get campaign progress percentage.
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_contacts === 0) {
            return 0;
        }

        $processed = $this->sent_count + $this->failed_count;
        return round(($processed / $this->total_contacts) * 100, 2);
    }

    /**
     * Get campaign success rate.
     */
    public function getSuccessRate(): float
    {
        if ($this->sent_count === 0) {
            return 0;
        }

        return round(($this->delivered_count / $this->sent_count) * 100, 2);
    }

    /**
     * Get campaign read rate.
     */
    public function getReadRate(): float
    {
        if ($this->delivered_count === 0) {
            return 0;
        }

        return round(($this->read_count / $this->delivered_count) * 100, 2);
    }

    /**
     * Define a relação de muitos-para-muitos com WhatsAppContact.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(WhatsAppContact::class, 'campaign_contacts', 'campaign_id', 'contact_id');
    }
}

