<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'campaign_contact_id',
        'whatsapp_message_id',
        'whatsapp_api_message_id',
        'status',
        'error_message',
        'api_response',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'api_response' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Get the campaign for the message.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the campaign contact for the message.
     */
    public function campaignContact(): BelongsTo
    {
        return $this->belongsTo(CampaignContact::class);
    }

    /**
     * Get the main WhatsApp message record if linked.
     */
    public function whatsAppMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class);
    }
}