<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'whatsapp_messages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'contact_id',
        'user_id',
        'message_id',
        'whatsapp_message_id',
        'direction',
        'type',
        'status',
        'content',
        'media',
        'metadata',
        'template_name',
        'template_parameters',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'is_ai_generated',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'media' => 'array',
        'metadata' => 'array',
        'template_parameters' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'is_ai_generated' => 'boolean',
    ];

    /**
     * Get the conversation that the message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    /**
     * Get the contact that the message is associated with.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'contact_id');
    }

    /**
     * Get the user who sent the message (if outbound and not AI).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
