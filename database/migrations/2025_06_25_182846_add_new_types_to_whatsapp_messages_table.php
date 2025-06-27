<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Define a lista completa de tipos de mensagem, incluindo os novos
        $types = [
            'text', 'image', 'video', 'audio', 'document', 'location', 
            'contact', 'template', 'interactive', 'sticker', 'unsupported'
        ];

        // Converte o array em uma string formatada para a query SQL
        $typeEnum = "'" . implode("','", $types) . "'";

        // Usa DB::statement para alterar a coluna ENUM. É mais seguro para enum.
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN type ENUM({$typeEnum}) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         // Define a lista original de tipos de mensagem para reverter
        $originalTypes = [
            'text', 'image', 'video', 'audio', 'document', 'location', 
            'contact', 'template', 'interactive'
        ];
        $originalTypeEnum = "'" . implode("','", $originalTypes) . "'";

        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN type ENUM({$originalTypeEnum}) NOT NULL");
    }
};
