<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Str;

class WhatsAppAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Usamos firstOrCreate para evitar duplicatas se o seeder for executado múltiplas vezes
        WhatsAppAccount::firstOrCreate(
            ['phone_number_id' => '614833181723737'], // Chave para verificar se o registro já existe
            [
                'name' => 'Principal',
                'business_account_id' => '1292640669109590',
                'access_token' => 'EAAUZBZB6rKHksBO6LVUaBMg0BgzoxoiZCOBGntA4mlLTp1OarTZCUXhlygNko7wj0sycTaut9ZAp84iZAG6CADb2urzcUYemYDRuFoDkYFq4tGPsc1PY4GIRis15MegadhRGhrb3inouzz0BNrAWSWlMxqyIzcNxGIIzyZAyqDOla6jK11j88SCKJd8X3T9CSEpdgZDZD',
                'webhook_verify_token' => Str::random(20), // Gera um token aleatório
                'app_secret' => Str::random(32), // Gera um segredo aleatório
                'phone_number' => '15556238061', // Número de telefone para exibição
                'display_phone_number' => '1 (555) 623-8061',
                'status' => 'active', // 'CONNECTED' mapeado para 'active'
                'verified_at' => now(),
                'is_default' => true,
            ]
        );
    }
}