<?php

namespace App\Events;

use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public WhatsAppMessage $message;

    /**
     * Create a new event instance.
     *
     * @param WhatsAppMessage $message
     */
    public function __construct(WhatsAppMessage $message)
    {
        $this->message = $message;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Transmite em um canal geral e em um canal específico da conversa
        return [
            new PrivateChannel('whatsapp.dashboard'),
            new PrivateChannel('whatsapp.conversations.' . $this->message->conversation_id),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // O payload é similar ao do evento de recebimento para reutilização no frontend
        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'contact_id' => $this->message->contact_id,
                'direction' => $this->message->direction,
                'type' => $this->message->type,
                'content' => $this->message->content,
                'media' => $this->message->media,
                'status' => $this->message->status,
                'is_ai_generated' => $this->message->is_ai_generated,
                'time' => $this->message->created_at->format('H:i'),
                'created_at' => $this->message->created_at,
            ],
            'conversation' => [
                'id' => $this->message->conversation->id,
                'status' => $this->message->conversation->status,
                'last_message_at' => $this->message->conversation->last_message_at,
            ]
        ];
    }
}
