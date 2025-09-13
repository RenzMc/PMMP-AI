<?php

declare(strict_types=1);

namespace Renz\AIAssistant\providers\google;

use Renz\AIAssistant\Main;
use Renz\AIAssistant\providers\AIProvider;
use Renz\AIAssistant\utils\MinecraftTextFormatter;
use Renz\AIAssistant\tasks\HttpRequestTask;

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
     * Build HTTP request configuration for async processing
     * 
     * @param string $query The user's query
     * @param array $conversationHistory Previous conversation history
     * @param string $systemPrompt The system prompt to use
     * @param string $serverInfo Additional server information
     * @param string $serverFeatures Relevant server features for the query
     * @return array HTTP request configuration array
     */
    public function buildHttpRequest(string $query, array $conversationHistory, string $systemPrompt, string $serverInfo, string $serverFeatures = ""): array {
        if (!$this->isConfigured()) {
            throw new \Exception("Google AI API is not properly configured. Please check your API key.");
        }

        // Prepare system instruction for Google AI (separate from contents)
        $fullSystemPrompt = $systemPrompt;
        if (!empty($serverInfo)) {
            $fullSystemPrompt .= "\n\nServer Information:\n" . $serverInfo;
        }
        if (!empty($serverFeatures)) {
            $fullSystemPrompt .= "\n\n" . $serverFeatures;
        }
        
        // Prepare the contents array for Google AI (only user/model roles allowed)
        $contents = [];
        
        // Add conversation history (limited to the configured maximum)
        $maxHistory = $this->plugin->getConfig()->getNested("prompts.max_conversation_history", 10);
        $historyCount = count($conversationHistory);
        
        if ($historyCount > 0) {
            $startIndex = max(0, $historyCount - $maxHistory);
            
            for ($i = $startIndex; $i < $historyCount; $i++) {
                $contents[] = ["role" => "user", "parts" => [["text" => $conversationHistory[$i]["query"]]]];
                $contents[] = ["role" => "model", "parts" => [["text" => $conversationHistory[$i]["response"]]]];
            }
        }
        
        // Add the current query
        $contents[] = ["role" => "user", "parts" => [["text" => $query]]];
        
        // Prepare the request data for Google AI with correct structure
        $requestData = [
            "systemInstruction" => [
                "parts" => [["text" => $fullSystemPrompt]]
            ],
            "contents" => $contents,
            "generationConfig" => [
                "temperature" => $this->temperature,
                "maxOutputTokens" => $this->maxTokens,
                "topP" => 0.95,
                "topK" => 40
            ]
        ];
        
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . trim($this->apiKey);
        
        // Return complete HTTP request configuration
        return [
            "url" => $apiUrl,
            "method" => "POST",
            "headers" => [
                "Content-Type: application/json"
            ],
            "data" => $requestData,
            "timeout" => $this->timeout
        ];
    }

    /**
     * Load configuration for this provider
     */
    private function loadConfig(): void {
        $config = $this->plugin->getConfig();
        
        // Only load config if the provider is enabled
        if ($config->getNested("api_providers.google.enabled", false)) {
            $this->apiKey = (string) $config->getNested("api_providers.google.api_key", "");
            $this->model = (string) $config->getNested("api_providers.google.model", "gemini-1.5-flash");
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
    /**
     * Legacy generateResponse method for backwards compatibility
     * This method is deprecated and will return a processing message.
     * The actual HTTP submission is handled by ProviderManager.
     * 
     * @param string $query The user's query
     * @param array $conversationHistory Previous conversation history
     * @param string $systemPrompt The system prompt to use
     * @param string $serverInfo Additional server information
     * @param string $serverFeatures Relevant server features for the query
     * @return string Processing message
     */
    public function generateResponse(string $query, array $conversationHistory, string $systemPrompt, string $serverInfo, string $serverFeatures = ""): string {
        if (!$this->isConfigured()) {
            return MinecraftTextFormatter::COLOR_RED . "Google AI API is not properly configured. Please check your API key.";
        }
        
        // This is now just a processing message - actual HTTP submission handled by ProviderManager
        return MinecraftTextFormatter::COLOR_YELLOW . "Processing your Google AI request... Please wait.";
    }

    /**
     * Parse HTTP response from Google AI API into clean response text
     * 
     * @param string $httpResponse Raw HTTP response body
     * @param array $result Complete result array from HttpRequestTask
     * @return array Parsed response array with 'success', 'content', and 'error' keys
     */
    public function parseResponse(string $httpResponse, array $result = []): array {
        try {
            // Check for HTTP errors first
            if (isset($result['error'])) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Google AI API error: ' . $result['error']
                ];
            }
            
            if (empty($httpResponse)) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Empty response from Google AI API'
                ];
            }

            // Parse the JSON response
            $responseData = json_decode($httpResponse, true);

            if ($responseData === null) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Invalid JSON response from Google AI: ' . json_last_error_msg()
                ];
            }

            // Extract the response text from Google AI format
            if (isset($responseData["candidates"][0]["content"]["parts"][0]["text"])) {
                $aiResponse = trim($responseData["candidates"][0]["content"]["parts"][0]["text"]);
                
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
                
                return [
                    'success' => true,
                    'content' => $aiResponse,
                    'error' => ''
                ];
            }

            // Handle error responses from Google AI API
            if (isset($responseData["error"])) {
                $errorMsg = is_array($responseData["error"]) ? 
                    json_encode($responseData["error"]) : 
                    (string)$responseData["error"];
                
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Google AI error: ' . $errorMsg
                ];
            }

            // If no candidates or errors found, return generic error
            return [
                'success' => false,
                'content' => '',
                'error' => 'Google AI: Unexpected response format - no candidates found'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'content' => '',
                'error' => 'Google AI parsing error: ' . $e->getMessage()
            ];
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
        
        // PocketMine-optimized cURL settings (based on deep search)
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Short timeout for PocketMine
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Connection timeout
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300); // Cache DNS for 5 minutes
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Force IPv4 to avoid dual-stack issues
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Disable redirects to prevent hanging
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Secure SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // Verify hostname
        
        // Set CA certificate path if available (critical for SSL)
        if (isset($this->plugin->cainfo_path) && !empty($this->plugin->cainfo_path) && file_exists($this->plugin->cainfo_path)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->plugin->cainfo_path);
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
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