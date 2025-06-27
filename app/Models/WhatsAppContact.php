<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppContact extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_contacts';

    protected $fillable = [
        'phone_number',
        'whatsapp_id',
        'name',
        'profile_name',
        'profile_picture',
        'tags',
        'custom_fields',
        'status',
        'last_seen_at',
        'opted_out_at',
    ];

    protected $casts = [
        'profile_picture' => 'array',
        'tags' => 'array',
        'custom_fields' => 'array',
        'last_seen_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    /**
     * Get the conversations for this contact.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'contact_id');
    }

    /**
     * Get the messages for this contact.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'contact_id');
    }

    /**
     * Scope for active contacts.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for blocked contacts.
     */
    public function scopeBlocked($query)
    {
        return $query->where('status', 'blocked');
    }

    /**
     * Scope for opted out contacts.
     */
    public function scopeOptedOut($query)
    {
        return $query->where('status', 'opted_out');
    }

    /**
     * Add tag to contact.
     */
    public function addTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            $this->update(['tags' => $tags]);
        }
    }

    /**
     * Remove tag from contact.
     */
    public function removeTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        $this->update(['tags' => array_values(array_diff($tags, [$tag]))]);
    }

    /**
     * Check if contact has tag.
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags ?? []);
    }

    /**
     * Set custom field value.
     */
    public function setCustomField(string $key, $value): void
    {
        $customFields = $this->custom_fields ?? [];
        $customFields[$key] = $value;
        $this->update(['custom_fields' => $customFields]);
    }

    /**
     * Get custom field value.
     */
    public function getCustomField(string $key, $default = null)
    {
        return ($this->custom_fields ?? [])[$key] ?? $default;
    }

    /**
     * Opt out contact.
     */
    public function optOut(): void
    {
        $this->update([
            'status' => 'opted_out',
            'opted_out_at' => now(),
        ]);
    }

    /**
     * Opt in contact.
     */
    public function optIn(): void
    {
        $this->update([
            'status' => 'active',
            'opted_out_at' => null,
        ]);
    }

    /**
     * Block contact.
     */
    public function block(): void
    {
        $this->update(['status' => 'blocked']);
    }

    /**
     * Unblock contact.
     */
    public function unblock(): void
    {
        $this->update(['status' => 'active']);
    }

    /**
     * Get display name.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: $this->profile_name ?: $this->phone_number;
    }

    /**
     * Format phone number for WhatsApp API.
     */
    public function getFormattedPhoneNumberAttribute(): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/\D/', '', $this->phone_number);
        
        // Add country code if not present
        if (!str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }
        
        return $phone;
    }
}

