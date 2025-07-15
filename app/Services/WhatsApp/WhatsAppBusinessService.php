<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use InvalidArgumentException;

class WhatsAppBusinessService
{
    protected string $baseUrl = 'https://graph.facebook.com/v20.0';
    protected ?WhatsAppAccount $account = null;

    public function __construct(?WhatsAppAccount $account = null)
    {
        $this->account = $account ?: WhatsAppAccount::default()->first();
    }

    /**
     * Set the WhatsApp account to use.
     */
    public function setAccount(WhatsAppAccount $account): self
    {
        $this->account = $account;
        return $this;
    }

    /**
     * Get media information, including the temporary download URL.
     * Este é o método que busca a URL na Meta.
     */
    public function getMediaInfo(string $mediaId): ?array
    {
        if (!$this->account) {
            Log::error('WhatsApp account not set for getMediaInfo.');
            return null;
        }

        $url = "{$this->baseUrl}/{$mediaId}";

        try {
            $response = Http::withToken($this->account->access_token)->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to get media info from Meta API.', [
                'media_id' => $mediaId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Exception while getting media info.', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send media message (image, video, document, audio).
     */
    public function sendMediaMessage(string $to, string $type, string $mediaUrl, ?string $caption = null): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhoneNumber($to),
            'type' => $type,
            $type => [
                'link' => $mediaUrl
            ]
        ];

        if ($caption && in_array($type, ['image', 'video', 'document'])) {
            $payload[$type]['caption'] = $caption;
        }

        return $this->sendMessagePayload($payload);
    }

    /**
     * Send interactive message (buttons, list).
     */
    public function sendInteractiveMessage(string $to, array $interactive): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhoneNumber($to),
            'type' => 'interactive',
            'interactive' => $interactive
        ];

        return $this->sendMessagePayload($payload);
    }

    /**
     * Mark message as read.
     */
    public function markAsRead(string $messageId): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }

        $url = "{$this->baseUrl}/{$this->account->phone_number_id}/messages";

        $response = Http::withToken($this->account->access_token)
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId
            ]);

        return $this->handleResponse($response);
    }

    /**
     * Get media URL.
     */
    public function getMediaUrl(string $mediaId): ?string
    {
        if (!$this->account) {
            return null;
        }

        $url = "{$this->baseUrl}/{$mediaId}";

        $response = Http::withToken($this->account->access_token)->get($url);

        if ($response->successful()) {
            $data = $response->json();
            return $data['url'] ?? null;
        }

        return null;
    }

    /**
     * Download media content.
     */
    public function downloadMedia(string $mediaUrl): ?string
    {
        if (!$this->account) {
            return null;
        }

        $response = Http::withToken($this->account->access_token)->get($mediaUrl);

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }

    /**
     * Get message templates from Meta API.
     */
    public function getTemplates(): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }

        $url = "{$this->baseUrl}/{$this->account->business_account_id}/message_templates";
        
        $response = Http::withToken($this->account->access_token)->get($url, [
            'fields' => 'name,status,category,components,language',
            'limit' => 100 // Aumente se tiver mais templates
        ]);

        $result = $this->handleResponse($response);

        if ($result['success']) {
            // Filtra para retornar apenas templates aprovados
            $approvedTemplates = array_filter($result['data']['data'] ?? [], function($template) {
                return $template['status'] === 'APPROVED';
            });
            $result['data'] = array_values($approvedTemplates);
        }
        
        return $result;
    }

    public function getAllTemplates(): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }


        try {
            // Adicionamos 'status' e 'category' aos fields para garantir que a API sempre os retorne.
            $url = "{$this->baseUrl}/{$this->account->business_account_id}/message_templates";
        
            $response = Http::withToken($this->account->access_token)->get($url, [
                'fields' => 'name,status,category,components,language',
                'limit' => 100 // Aumente se tiver mais templates
            ]);

            $result = $this->handleResponse($response);

            Log::info('Fetched templates from WhatsApp API', [
                'account_id' => $this->account->id,
                'count' => $result['data']['data']
            ]);

            return $result['data'];
        } catch (Exception $e) {
            Log::error('Error fetching templates from WhatsApp API: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An unexpected error occurred.'];
        }
    }

    /**
     * Create message template.
     */
    public function createTemplate(array $templateData): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }

        $url = "{$this->baseUrl}/{$this->account->business_account_id}/message_templates";

        $response = Http::withToken($this->account->access_token)
            ->post($url, $templateData);

        return $this->handleResponse($response);
    }

    /**
     * Delete message template.
     */
    public function deleteTemplate(string $templateName): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }

        $url = "{$this->baseUrl}/{$this->account->business_account_id}/message_templates";

        $response = Http::withToken($this->account->access_token)
            ->delete($url, [
                'name' => $templateName
            ]);

        return $this->handleResponse($response);
    }

    /**
     * Validate template before sending.
     */
    public function validateTemplate(string $templateName, array $parameters = []): bool
    {
        $template = WhatsAppTemplate::where('whatsapp_account_id', $this->account->id)
            ->where('name', $templateName)
            ->where('status', 'APPROVED')
            ->where('is_active', true)
            ->first();

        if (!$template) {
            return false;
        }

        // Validate parameters count and types
        $components = $template->components;
        $requiredParams = $this->extractRequiredParameters($components);

        return count($parameters) >= count($requiredParams);
    }

    /**
     * Método central para enviar qualquer tipo de mensagem.
     *
     * @param string $to O número do destinatário.
     * @param string $type O tipo de mensagem (text, image, audio, document, template, location, etc.).
     * @param array $data Os dados específicos para o tipo de mensagem.
     * @return array
     * @throws Exception
     */
    public function sendMessage(string $to, string $type, array $data): array
    {
        if (!$this->account) {
            throw new Exception('WhatsApp account not configured');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhoneNumber($to),
            'type' => $type,
        ];
        
        $messageBody = $this->buildMessageBody($type, $data);
        $payload[$type] = $messageBody;

        return $this->sendMessagePayload($payload);
    }

    /**
     * Constrói o corpo da mensagem com base no seu tipo.
     */
    private function buildMessageBody(string $type, array $data): array
    {
        return match ($type) {
            'text' => [
                'preview_url' => $data['preview_url'] ?? false,
                'body' => $data['content'] ?? throw new InvalidArgumentException('Content for text message is required.'),
            ],
            'image', 'video' => [
                'link' => $data['media_url'] ?? throw new InvalidArgumentException('Media URL is required for media messages.'),
                'caption' => $data['caption'] ?? null,
            ],
            'audio' => [ // Áudio foi separado e não tem 'caption'
                'link' => $data['media_url'] ?? throw new InvalidArgumentException('Media URL is required for audio messages.'),
            ],
            'document' => [
                'link' => $data['media_url'] ?? throw new InvalidArgumentException('Media URL is required for document messages.'),
                'caption' => $data['caption'] ?? null,
                'filename' => $data['filename'] ?? null,
            ],
            'sticker' => [
                'link' => $data['media_url'] ?? throw new InvalidArgumentException('Media URL is required for sticker messages.'),
            ],
            'location' => [
                'latitude' => $data['latitude'] ?? throw new InvalidArgumentException('Latitude is required for location messages.'),
                'longitude' => $data['longitude'] ?? throw new InvalidArgumentException('Longitude is required for location messages.'),
                'name' => $data['name'] ?? null,
                'address' => $data['address'] ?? null,
            ],
            'template' => [
                'name' => $data['template_name'] ?? throw new InvalidArgumentException('Template name is required.'),
                'language' => ['code' => $data['language_code'] ?? 'pt_BR'],
                'components' => $this->buildTemplateComponents($data['parameters'] ?? []),
            ],
            'interactive' => $data['interactive'] ?? throw new InvalidArgumentException('Interactive object is required.'),
            default => throw new InvalidArgumentException("Unsupported message type: {$type}"),
        };
    }
    
    /**
     * Método "atalho" para enviar texto.
     */
    public function sendTextMessage(string $to, string $message, bool $previewUrl = false): array
    {
        return $this->sendMessage($to, 'text', [
            'content' => $message,
            'preview_url' => $previewUrl,
        ]);
    }
    
    /**
     * **NOVO MÉTODO ADICIONADO**
     * "Atalho" para enviar uma mensagem de áudio.
     */
    public function sendAudioMessage(string $to, string $audioUrl): array
    {
        return $this->sendMessage($to, 'audio', [
            'media_url' => $audioUrl,
        ]);
    }

    /**
     * Método "atalho" para enviar template.
     */
    public function sendTemplateMessage(string $to, string $templateName, string $language = 'pt_BR', array $parameters = []): array
    {
        Log::info('Sending template message', [
            'to' => $to,
            'template_name' => $templateName,
            'language' => $language,
            'parameters' => $parameters
        ]);
        return $this->sendMessage($to, 'template', [
            'template_name' => $templateName,
            'language_code' => $language,
            'parameters' => $parameters,
        ]);
    }

    protected function sendMessagePayload(array $payload): array
    {
        $url = "{$this->baseUrl}/{$this->account->phone_number_id}/messages";

        try {
            $response = Http::withToken($this->account->access_token)
                ->timeout(30)
                ->post($url, $payload);

            $result = $this->handleResponse($response);

            if ($result['success']) {
                Log::info('WhatsApp message sent successfully', [
                    'account_id' => $this->account->id,
                    'to' => $payload['to'],
                    'type' => $payload['type'],
                    'message_id' => $result['data']['messages'][0]['id'] ?? null
                ]);
            } else {
                Log::error('WhatsApp message failed', [
                    'account_id' => $this->account->id,
                    'to' => $payload['to'],
                    'error' => $result['message'],
                    'payload' => $payload
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error('WhatsApp API error: ' . $e->getMessage(), [
                'account_id' => $this->account?->id,
                'payload' => $payload
            ]);

            return [
                'success' => false,
                'message' => 'Erro de conexão com WhatsApp API',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function handleResponse($response): array
    {
        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json()
            ];
        }

        $error = $response->json();
        $errorMessage = $error['error']['message'] ?? 'Erro desconhecido';

        return [
            'success' => false,
            'message' => $errorMessage,
            'error_code' => $error['error']['code'] ?? null,
            'error_details' => $error
        ];
    }

    protected function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        
        if (!str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }
        
        return $phone;
    }

    /**
     * --- NOVO MÉTODO ADICIONADO ---
     * "Atalho" específico para enviar um Template de Fluxo (Flow Template).
     *
     * @param string $to O número do destinatário.
     * @param string $templateName O nome do template.
     * @param string $language O código do idioma (ex: 'en_US').
     * @param string $flowToken O token específico do fluxo a ser iniciado.
     * @param array $bodyParameters Parâmetros para o corpo do template.
     * @param array|null $headerParameters Parâmetros para o cabeçalho, se houver.
     * @return array
     */
    public function sendFlowTemplateMessage(string $to, string $templateName, string $language, string $flowToken, array $bodyParameters = [], ?array $headerParameters = null): array
    {
        // Constrói a estrutura de parâmetros para o corpo e cabeçalho
        $parameters = [];
        if ($headerParameters) {
            $parameters['header'] = $headerParameters;
        }
        if ($bodyParameters) {
            $parameters['body'] = $bodyParameters;
        }

        // Adiciona a estrutura específica e obrigatória para o botão do fluxo
        $parameters['button'] = [
            'sub_type' => 'flow',
            'index' => '0',
            'parameters' => [
                [
                    'type' => 'action',
                    'action' => [
                        'flow_token' => $flowToken,
                    ],
                ],
            ],
        ];

        return $this->sendMessage($to, 'template', [
            'template_name' => $templateName,
            'language_code' => $language,
            'parameters' => $parameters,
        ]);
    }

    protected function buildTemplateComponents(array $parameters): array
    {
        $components = [];

        if (isset($parameters['header'])) {
            $components[] = [
                'type' => 'header',
                'parameters' => $parameters['header']
            ];
        }

        if (isset($parameters['body'])) {
            $components[] = [
                'type' => 'body',
                'parameters' => $parameters['body']
            ];
        }

        // Adicionado para suportar o botão do fluxo
        if (isset($parameters['button'])) {
            $components[] = [
                'type' => 'button',
                'sub_type' => $parameters['button']['sub_type'],
                'index' => $parameters['button']['index'],
                'parameters' => $parameters['button']['parameters']
            ];
        }

        if (isset($parameters['footer'])) {
            $components[] = [
                'type' => 'footer',
                'parameters' => $parameters['footer']
            ];
        }

        return $components;
    }

    protected function extractRequiredParameters(array $components): array
    {
        $parameters = [];

        foreach ($components as $component) {
            if (isset($component['text'])) {
                preg_match_all('/\{\{(\d+)\}\}/', $component['text'], $matches);
                $parameters = array_merge($parameters, $matches[1]);
            }
        }

        return array_unique($parameters);
    }

    public function getContactProfile(string $contactId): ?array
    {
        if (!$this->account) {
            Log::error('WhatsApp account not set for getContactProfile.');
            return null;
        }

        $url = "{$this->baseUrl}/{$contactId}?fields=name,profile_picture_url";

        try {
            $response = Http::withToken($this->account->access_token)->get($url);

            if ($response->successful()) {
                Log::info('Contact profile fetched successfully.', ['contact_id' => $contactId]);
                return $response->json();
            }

            Log::error('Failed to get contact profile from Meta API.', [
                'contact_id' => $contactId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('Exception while getting contact profile.', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getTemplateByName(string $templateName): ?array
    {
        $allTemplatesResponse = $this->getTemplates();

        if (!$allTemplatesResponse['success']) {
            Log::error(
                'Could not fetch template list, so cannot find template by name.',
                [
                    'account_id' => $this->account?->id,
                    'template_name' => $templateName,
                    'error' => $allTemplatesResponse['message'] ?? 'Unknown error.'
                ]
            );
            return null;
        }

        $templates = $allTemplatesResponse['data'] ?? [];
        foreach ($templates as $template) {
            if (isset($template['name']) && $template['name'] === $templateName) {
                Log::info('Template found successfully.', ['template' => $template]);
                return $template;
            }
        }

        Log::warning('Template not found in the list of approved templates.', [
            'account_id' => $this->account?->id,
            'template_name' => $templateName
        ]);

        return null;
    }

    /**
     * **MÉTODO CORRIGIDO**
     * Envia o indicador de "a escrever..." no contexto de uma mensagem recebida.
     *
     * @param string $to O número do destinatário.
     * @param string $replyingToMessageId O ID da mensagem à qual estamos a "responder".
     */
    public function sendTypingIndicator(string $to, string $replyingToMessageId): array
    {
        if (!$this->account) {
            throw new Exception('Conta do WhatsApp não configurada');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->formatPhoneNumber($to),
            "status" => "read",
            'typing_indicator' => [
                'type' => 'text'
            ],
            'message_id' => $replyingToMessageId
        ];

        Log::info('Sending typing indicator', [
            'to' => $to,
            'replying_to_message_id' => $replyingToMessageId
        ]);

        return $this->sendMessagePayload($payload);
    }

    
}