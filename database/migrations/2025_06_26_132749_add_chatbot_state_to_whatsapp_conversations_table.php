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
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->string('chatbot_state')->nullable()->after('status'); // Guarda o nó atual, ex: 'awaiting_cpf'
            $table->json('chatbot_context')->nullable()->after('chatbot_state'); // Guarda dados temporários, ex: CPF
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            //
        });
    }
};
