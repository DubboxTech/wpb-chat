<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppAccount extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'name',
        'phone_number_id',
        'business_account_id',
        'access_token',
        'webhook_verify_token',
        'app_secret',
        'phone_number',
        'display_phone_number',
        'status',
        'webhook_fields',
        'verified_at',
        'is_default',
    ];

    protected $casts = [
        'webhook_fields' => 'array',
        'verified_at' => 'datetime',
        'is_default' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'webhook_verify_token',
        'app_secret',
    ];

    /**
     * Get the conversations for this account.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class);
    }

    /**
     * Get the templates for this account.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class);
    }

    /**
     * Scope for active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for default account.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Set this account as default.
     */
    public function setAsDefault(): void
    {
        // Remove default from all other accounts
        static::where('id', '!=', $this->id)->update(['is_default' => false]);
        
        // Set this account as default
        $this->update(['is_default' => true]);
    }

    /**
     * Check if account is verified.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->status === 'active';
    }

    /**
     * Get formatted phone number for display.
     */
    public function getFormattedPhoneNumberAttribute(): string
    {
        return $this->display_phone_number ?: $this->phone_number;
    }
}

