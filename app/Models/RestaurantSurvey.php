<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSurvey extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_account_id',
        'whatsapp_contact_id',
        'restaurant_name',
        'full_name',
        'cpf',
        'cep',
        'address',
        'rating',
        'comments',
        'raw_response',
    ];

    protected $casts = [
        'raw_response' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id');
    }
}