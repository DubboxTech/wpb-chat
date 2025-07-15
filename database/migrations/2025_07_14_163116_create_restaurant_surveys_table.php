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
        Schema::create('restaurant_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('whatsapp_contact_id')->constrained()->onDelete('cascade');
            $table->string('restaurant_name');
            $table->string('full_name')->nullable();
            $table->string('cpf')->nullable();
            $table->string('cep')->nullable();
            $table->string('address')->nullable();
            $table->string('rating')->nullable();
            $table->text('comments')->nullable();
            $table->json('raw_response'); // Sempre guarde o payload original!
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_surveys');
    }
};