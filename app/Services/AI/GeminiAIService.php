<?php

namespace App\Services\AI;

use App\Models\AITrainingData;
use App\Models\AIResponse;
use App\Models\AIFallback;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiAIService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    protected float $confidenceThreshold = 0.7;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    /**
     * Process incoming message and generate AI response.
     */
    public function processMessage(WhatsAppMessage $message): ?array
    {
        try {
            $userMessage = $message->content;
            
            // First, try to find exact match in training data
            $trainingMatch = $this->findTrainingDataMatch($userMessage);
            
            if ($trainingMatch && $trainingMatch['confidence'] >= $this->confidenceThreshold) {
                return $this->createResponseFromTraining($message, $trainingMatch);
            }

            // If no good training match, use Gemini AI
            $geminiResponse = $this->queryGemini($userMessage, $message->conversation);
            
            if ($geminiResponse['success'] && $geminiResponse['confidence'] >= $this->confidenceThreshold) {
                return $this->createResponseFromGemini($message, $geminiResponse);
            }

            // If confidence is too low, escalate to human
            $this->createFallback($message, $geminiResponse);
            
            return null;

        } catch (Exception $e) {
            Log::error('AI processing error: ' . $e->getMessage(), [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id
            ]);

            $this->createFallback($message, null, 'no_match');
            return null;
        }
    }

    /**
     * Find matching training data.
     */
    protected function findTrainingDataMatch(string $userMessage): ?array
    {
        // Simple keyword matching (can be enhanced with vector similarity)
        $trainingData = AITrainingData::where('is_active', true)
            ->where('is_approved', true)
            ->get();

        $bestMatch = null;
        $bestScore = 0;

        foreach ($trainingData as $data) {
            $score = $this->calculateSimilarity($userMessage, $data->question);
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'training_data' => $data,
                    'confidence' => $score,
                    'response' => $data->answer
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Calculate similarity between two texts.
     */
    protected function calculateSimilarity(string $text1, string $text2): float
    {
        // Simple word-based similarity (can be enhanced with more sophisticated algorithms)
        $words1 = array_unique(str_word_count(strtolower($text1), 1));
        $words2 = array_unique(str_word_count(strtolower($text2), 1));
        
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        
        if (empty($union)) {
            return 0;
        }
        
        return count($intersection) / count($union);
    }

    /**
     * Query Gemini AI.
     */
    protected function queryGemini(string $userMessage, WhatsAppConversation $conversation): array
    {
        try {
            // Build context from conversation history
            $context = $this->buildConversationContext($conversation);
            
            // Build prompt
            $prompt = $this->buildPrompt($userMessage, $context);
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/models/gemini-pro:generateContent?key={$this->apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 1024,
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $aiResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                
                // Calculate confidence based on response quality
                $confidence = $this->calculateResponseConfidence($aiResponse, $userMessage);
                
                return [
                    'success' => true,
                    'response' => trim($aiResponse),
                    'confidence' => $confidence,
                    'raw_response' => $data
                ];
            }

            return [
                'success' => false,
                'error' => 'Gemini API error: ' . $response->body(),
                'confidence' => 0
            ];

        } catch (Exception $e) {
            Log::error('Gemini API error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'confidence' => 0
            ];
        }
    }

    /**
     * Build conversation context.
     */
    protected function buildConversationContext(WhatsAppConversation $conversation): string
    {
        $recentMessages = $conversation->messages()
            ->where('direction', 'inbound')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->reverse();

        $context = "Histórico da conversa:\n";
        foreach ($recentMessages as $msg) {
            $context .= "Cliente: {$msg->content}\n";
        }

        return $context;
    }

    /**
     * Build AI prompt.
     */
    protected function buildPrompt(string $userMessage, string $context): string
    {
        $businessInfo = $this->getBusinessInfo();
        
        return "Você é um assistente virtual de atendimento ao cliente para {$businessInfo['name']}.

{$businessInfo['description']}

Diretrizes:
- Seja sempre educado, prestativo e profissional
- Responda em português brasileiro
- Mantenha respostas concisas e diretas
- Se não souber a resposta, seja honesto e ofereça transferir para um atendente humano
- Use informações do contexto da conversa quando relevante

{$context}

Pergunta atual do cliente: {$userMessage}

Resposta:";
    }

    /**
     * Get business information for AI context.
     */
    protected function getBusinessInfo(): array
    {
        return [
            'name' => config('app.name', 'Nossa Empresa'),
            'description' => 'Somos uma empresa focada em oferecer o melhor atendimento aos nossos clientes através do WhatsApp Business.'
        ];
    }

    /**
     * Calculate response confidence.
     */
    protected function calculateResponseConfidence(string $response, string $userMessage): float
    {
        // Simple confidence calculation based on response characteristics
        $confidence = 0.5; // Base confidence
        
        // Increase confidence if response is not too short or too long
        $responseLength = strlen($response);
        if ($responseLength >= 20 && $responseLength <= 500) {
            $confidence += 0.2;
        }
        
        // Increase confidence if response doesn't contain uncertainty phrases
        $uncertaintyPhrases = ['não sei', 'não tenho certeza', 'talvez', 'possivelmente'];
        $hasUncertainty = false;
        foreach ($uncertaintyPhrases as $phrase) {
            if (stripos($response, $phrase) !== false) {
                $hasUncertainty = true;
                break;
            }
        }
        
        if (!$hasUncertainty) {
            $confidence += 0.2;
        } else {
            $confidence -= 0.3;
        }
        
        // Ensure confidence is between 0 and 1
        return max(0, min(1, $confidence));
    }

    /**
     * Create response from training data.
     */
    protected function createResponseFromTraining(WhatsAppMessage $message, array $trainingMatch): array
    {
        $trainingData = $trainingMatch['training_data'];
        
        // Increment usage count
        $trainingData->increment('usage_count');
        
        // Create AI response record
        AIResponse::create([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'training_data_id' => $trainingData->id,
            'user_message' => $message->content,
            'ai_response' => $trainingMatch['response'],
            'confidence_score' => $trainingMatch['confidence'],
            'status' => 'sent',
            'responded_at' => now(),
        ]);

        return [
            'response' => $trainingMatch['response'],
            'confidence' => $trainingMatch['confidence'],
            'source' => 'training_data'
        ];
    }

    /**
     * Create response from Gemini.
     */
    protected function createResponseFromGemini(WhatsAppMessage $message, array $geminiResponse): array
    {
        // Create AI response record
        AIResponse::create([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'user_message' => $message->content,
            'ai_response' => $geminiResponse['response'],
            'confidence_score' => $geminiResponse['confidence'],
            'gemini_response' => $geminiResponse['raw_response'],
            'status' => 'sent',
            'responded_at' => now(),
        ]);

        return [
            'response' => $geminiResponse['response'],
            'confidence' => $geminiResponse['confidence'],
            'source' => 'gemini'
        ];
    }

    /**
     * Create fallback for human intervention.
     */
    protected function createFallback(WhatsAppMessage $message, ?array $aiResponse, string $reason = 'low_confidence'): void
    {
        AIFallback::create([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'user_message' => $message->content,
            'ai_attempted_response' => $aiResponse['response'] ?? null,
            'confidence_score' => $aiResponse['confidence'] ?? null,
            'reason' => $reason,
            'status' => 'pending',
            'escalated_at' => now(),
        ]);

        // Update conversation to indicate human intervention needed
        $message->conversation->update([
            'status' => 'pending',
            'is_ai_handled' => false,
        ]);
    }

    /**
     * Add training data from successful interactions.
     */
    public function addTrainingData(string $question, string $answer, array $context = []): AITrainingData
    {
        return AITrainingData::create([
            'user_id' => auth()->id(),
            'question' => $question,
            'answer' => $answer,
            'context' => $context,
            'keywords' => $this->extractKeywords($question),
            'is_active' => true,
            'is_approved' => false, // Requires manual approval
        ]);
    }

    /**
     * Extract keywords from text.
     */
    protected function extractKeywords(string $text): array
    {
        // Simple keyword extraction (can be enhanced with NLP)
        $words = str_word_count(strtolower($text), 1);
        $stopWords = ['o', 'a', 'de', 'para', 'com', 'em', 'por', 'do', 'da', 'dos', 'das', 'um', 'uma'];
        
        return array_values(array_diff(array_unique($words), $stopWords));
    }
}

