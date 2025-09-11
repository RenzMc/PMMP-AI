<?php

declare(strict_types=1);

namespace Renz\AIAssistant\providers\google;

use Renz\AIAssistant\Main;
use Renz\AIAssistant\providers\AIProvider;
use Renz\AIAssistant\utils\MinecraftTextFormatter;

class GoogleAIProvider implements AIProvider {
    /** @var Main */
    private Main $plugin;
    
    /** @var string */
    private string $apiKey = "";
    
    /** @var string */
    private string $model = "gemini-pro";
    
    /** @var float */
    private float $temperature = 0.7;
    
    /** @var int */
    private int $maxTokens = 500;
    
    /** @var int */
    private int $timeout = 30;

    /**
     * GoogleAIProvider constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadConfig();
    }

    /**
     * Load configuration for this provider
     */
    private function loadConfig(): void {
        $config = $this->plugin->getConfig();
        
        // Only load config if the provider is enabled
        if ($config->getNested("api_providers.google.enabled", false)) {
            $this->apiKey = (string) $config->getNested("api_providers.google.api_key", "");
            $this->model = (string) $config->getNested("api_providers.google.model", "gemini-pro");
            $this->temperature = (float) $config->getNested("api_providers.google.temperature", 0.7);
            $this->maxTokens = (int) $config->getNested("api_providers.google.max_tokens", 500);
            $this->timeout = (int) $config->getNested("api_providers.google.timeout", 30);
        }
    }

    /**
     * Generate a response from Google AI
     * 
     * @param string $query The user's query
     * @param array $conversationHistory Previous conversation history
     * @param string $systemPrompt The system prompt to use
     * @param string $serverInfo Additional server information
     * @param string $serverFeatures Relevant server features for the query
     * @return string The AI's response
     */
    public function generateResponse(string $query, array $conversationHistory, string $systemPrompt, string $serverInfo, string $serverFeatures = ""): string {
        if (!$this->isConfigured()) {
            return MinecraftTextFormatter::COLOR_RED . "Google AI API is not properly configured. Please check your API key.";
        }
        
        try {
            // Check if the request has been cancelled
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            $playerName = $this->plugin->getServer()->getOnlinePlayers()[0]->getName() ?? "SYSTEM";
            $activeRequest = $requestManager->getActiveRequest($playerName);
            
            if ($activeRequest !== null && isset($activeRequest['id'])) {
                $requestId = $activeRequest['id'];
                if ($requestManager->isRequestCancelled($requestId)) {
                    return MinecraftTextFormatter::COLOR_YELLOW . "Request was cancelled.";
                }
            }
            
            // Prepare the messages array for Google AI
            $messages = [];
            
            // Add system prompt
            $fullSystemPrompt = $systemPrompt;
            if (!empty($serverInfo)) {
                $fullSystemPrompt .= "\n\nServer Information:\n" . $serverInfo;
            }
            if (!empty($serverFeatures)) {
                $fullSystemPrompt .= "\n\n" . $serverFeatures;
            }
            
            $messages[] = ["role" => "system", "parts" => [["text" => $fullSystemPrompt]]];
            
            // Add conversation history (limited to the configured maximum)
            $maxHistory = $this->plugin->getConfig()->getNested("prompts.max_conversation_history", 10);
            $historyCount = count($conversationHistory);
            
            if ($historyCount > 0) {
                $startIndex = max(0, $historyCount - $maxHistory);
                
                for ($i = $startIndex; $i < $historyCount; $i++) {
                    $messages[] = ["role" => "user", "parts" => [["text" => $conversationHistory[$i]["query"]]]];
                    $messages[] = ["role" => "model", "parts" => [["text" => $conversationHistory[$i]["response"]]]];
                }
            }
            
            // Add the current query
            $messages[] = ["role" => "user", "parts" => [["text" => $query]]];
            
            // Prepare the request data for Google AI
            $data = [
                "contents" => $messages,
                "generationConfig" => [
                    "temperature" => $this->temperature,
                    "maxOutputTokens" => $this->maxTokens,
                    "topP" => 0.95,
                    "topK" => 40
                ]
            ];
            
            // Make the API request
            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
            $response = $this->makeRequest($apiUrl, $data);
            
            // Parse the response
            $responseData = json_decode($response, true);
            
            if (isset($responseData["candidates"][0]["content"]["parts"][0]["text"])) {
                $aiResponse = $responseData["candidates"][0]["content"]["parts"][0]["text"];
                
                // Trim the response if it's too long
                $maxLength = $this->plugin->getConfig()->getNested("advanced.max_response_length", 1000);
                if (strlen($aiResponse) > $maxLength) {
                    $aiResponse = substr($aiResponse, 0, $maxLength) . "...";
                }
                
                // Check if the response already has Minecraft formatting
                if (!str_contains($aiResponse, "§")) {
                    // Convert any markdown formatting to Minecraft formatting
                    $aiResponse = MinecraftTextFormatter::formatText($aiResponse);
                }
                
                return $aiResponse;
            } else {
                if ($this->plugin->isDebugEnabled() && isset($responseData["error"])) {
                    $this->plugin->getLogger()->debug("Google AI API error: " . json_encode($responseData["error"]));
                }
                return MinecraftTextFormatter::COLOR_RED . "I couldn't generate a response. Please try again later.";
            }
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Google AI API error: " . $e->getMessage());
            return MinecraftTextFormatter::COLOR_RED . "An error occurred while communicating with the AI service. Please try again later.";
        }
    }

    /**
     * Make an HTTP request to the Google AI API
     * 
     * @param string $url
     * @param array $data
     * @return string
     * @throws \Exception
     */
    private function makeRequest(string $url, array $data): string {
        $jsonData = json_encode($data);
        
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \Exception("Failed to initialize cURL");
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Content-Length: " . strlen($jsonData)
        ]);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL error: " . $error);
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 400) {
            curl_close($ch);
            throw new \Exception("HTTP error: " . $httpCode . ", Response: " . $response);
        }
        
        curl_close($ch);
        
        return $response;
    }

    /**
     * Get the provider name
     * 
     * @return string
     */
    public function getName(): string {
        return "Google AI";
    }

    /**
     * Get the provider description
     * 
     * @return string
     */
    public function getDescription(): string {
        return "Google AI API provider using model: " . $this->model;
    }

    /**
     * Check if the provider is properly configured
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return !empty($this->apiKey) && $this->apiKey !== "YOUR_GOOGLE_API_KEY";
    }
}