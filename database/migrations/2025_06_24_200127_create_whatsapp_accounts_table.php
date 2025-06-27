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
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone_number_id')->unique();
            $table->string('business_account_id');
            $table->string('access_token');
            $table->string('webhook_verify_token');
            $table->string('app_secret');
            $table->string('phone_number');
            $table->string('display_phone_number')->nullable();
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->json('webhook_fields')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};

