<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\WhatsAppConversation;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});


// Canal para o Dashboard geral, qualquer usuário logado pode ouvir
Broadcast::channel('whatsapp.dashboard', function ($user) {
    return (int) $user->id > 0;
});

// Canal privado para cada conversa
// Apenas o usuário atribuído (ou um admin) pode ouvir esta conversa
Broadcast::channel('whatsapp.conversations.{conversationId}', function ($user, $conversationId) {
    $conversation = WhatsAppConversation::find($conversationId);
    
    // Permite se o usuário for um admin ou se a conversa estiver atribuída a ele
    return $user->hasRole('admin') || $user->id === $conversation->assigned_user_id;
});
