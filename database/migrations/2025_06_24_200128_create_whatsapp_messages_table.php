<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->onDelete('cascade');
            $table->foreignId('contact_id')->constrained('whatsapp_contacts')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('message_id')->unique();
            $table->string('whatsapp_message_id')->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('type', ['text', 'image', 'video', 'audio', 'document', 'location', 'contact', 'template', 'interactive']);
            $table->enum('status', ['sent', 'delivered', 'read', 'failed', 'pending'])->default('pending');
            $table->text('content')->nullable();
            $table->json('media')->nullable();
            $table->json('metadata')->nullable();
            $table->string('template_name')->nullable();
            $table->json('template_parameters')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->timestamps();
            
            $table->index(['conversation_id', 'created_at']);
            $table->index(['direction', 'status']);
            $table->index(['whatsapp_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};

