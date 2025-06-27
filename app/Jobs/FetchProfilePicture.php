<?php

namespace App\Jobs;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppBusinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchProfilePicture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $contactId;

    public function __construct(int $contactId)
    {
        $this->contactId = $contactId;
    }

    public function handle(WhatsAppBusinessService $whatsappService): void
    {
        $contact = WhatsAppContact::find($this->contactId);
        if (!$contact) {
            Log::warning('Contact not found for profile picture fetch.', ['contact_id' => $this->contactId]);
            return;
        }

        // Assume o uso da conta padrão, ou adicione lógica para buscar a conta associada.
        $defaultAccount = WhatsAppAccount::where('is_default', true)->first();
        if (!$defaultAccount) {
            Log::error('No default WhatsApp Account found to fetch profile picture.');
            return;
        }

        $whatsappService->setAccount($defaultAccount);
        $profileData = $whatsappService->getContactProfile($contact->whatsapp_id);

        if ($profileData && isset($profileData['profile_picture_url'])) {
            $contact->update([
                'profile_picture' => ['url' => $profileData['profile_picture_url']],
                // Também atualizamos o nome do perfil, se ele tiver mudado
                'profile_name' => $profileData['name'] ?? $contact->profile_name
            ]);

            Log::info('Profile picture URL updated for contact.', ['contact_id' => $this->contactId]);
        } else {
            Log::warning('Could not retrieve profile picture for contact.', ['contact_id' => $this->contactId]);
        }
    }
}