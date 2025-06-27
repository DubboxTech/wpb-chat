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
        Schema::create('ai_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->onDelete('cascade');
            $table->foreignId('message_id')->constrained('whatsapp_messages')->onDelete('cascade');
            $table->foreignId('training_data_id')->nullable()->constrained('ai_training_data')->onDelete('set null');
            $table->text('user_message');
            $table->text('ai_response');
            $table->float('confidence_score');
            $table->json('gemini_response')->nullable();
            $table->enum('status', ['sent', 'failed', 'fallback'])->default('sent');
            $table->boolean('was_helpful')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('responded_at');
            $table->timestamps();
            
            $table->index(['conversation_id', 'responded_at']);
            $table->index(['confidence_score', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_responses');
    }
};

