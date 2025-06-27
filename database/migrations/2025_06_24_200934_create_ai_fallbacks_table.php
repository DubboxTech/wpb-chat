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
        Schema::create('ai_fallbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->onDelete('cascade');
            $table->foreignId('message_id')->constrained('whatsapp_messages')->onDelete('cascade');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('user_message');
            $table->text('ai_attempted_response')->nullable();
            $table->float('confidence_score')->nullable();
            $table->enum('reason', ['low_confidence', 'no_match', 'complex_query', 'manual_escalation']);
            $table->enum('status', ['pending', 'assigned', 'resolved'])->default('pending');
            $table->text('human_response')->nullable();
            $table->timestamp('escalated_at');
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('should_train')->default(false);
            $table->timestamps();
            
            $table->index(['status', 'escalated_at']);
            $table->index(['assigned_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_fallbacks');
    }
};

