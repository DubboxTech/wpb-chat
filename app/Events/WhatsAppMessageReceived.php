<?php

namespace App\Events;

use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsAppMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public WhatsAppMessage $message;
    public bool $isNewConversation; // <-- PROPRIEDADE ADICIONADA

    /**
     * Create a new event instance.
     *
     * @param WhatsAppMessage $message
     * @param bool $isNewConversation Indica se a conversa é nova ou foi reaberta
     */
    public function __construct(WhatsAppMessage $message, bool $isNewConversation = false)
    {
        $this->message = $message;
        $this->isNewConversation = $isNewConversation; // <-- ATRIBUIÇÃO ADICIONADA
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('whatsapp.conversations.' . $this->message->conversation_id),
            new PrivateChannel('whatsapp.dashboard'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'contact_id' => $this->message->contact_id,
                'direction' => $this->message->direction,
                'type' => $this->message->type,
                'content' => $this->message->content,
                'media' => $this->message->media,
                'created_at' => $this->message->created_at,
                'contact' => [
                    'id' => $this->message->contact->id,
                    'name' => $this->message->contact->display_name,
                    'phone_number' => $this->message->contact->phone_number,
                ],
            ],
            'conversation' => [
                'id' => $this->message->conversation->id,
                'status' => $this->message->conversation->status,
                'unread_count' => $this->message->conversation->unread_count,
                'last_message_at' => $this->message->conversation->last_message_at,
            ],
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.received';
    }
}

