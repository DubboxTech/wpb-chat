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
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number')->unique();
            $table->string('whatsapp_id')->nullable();
            $table->string('name')->nullable();
            $table->string('profile_name')->nullable();
            $table->json('profile_picture')->nullable();
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->enum('status', ['active', 'blocked', 'opted_out'])->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();
            
            $table->index(['phone_number', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};

