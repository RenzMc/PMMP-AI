<?php

declare(strict_types=1);

namespace Renz\AIAssistant\providers\local;

use Renz\AIAssistant\Main;
use Renz\AIAssistant\providers\AIProvider;
use Renz\AIAssistant\utils\MinecraftTextFormatter;

class LocalAIProvider implements AIProvider {
    /** @var Main */
    private Main $plugin;
    
    /** @var string */
    private string $endpoint = "http://localhost:8080/v1/completions";
    
    /** @var string */
    private string $model = "local-model";
    
    /** @var float */
    private float $temperature = 0.7;
    
    /** @var int */
    private int $maxTokens = 500;
    
    /** @var int */
    private int $timeout = 30;

    /**
     * LocalAIProvider constructor.
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
            throw new \Exception("Local AI is not properly configured. Please check your endpoint.");
        }

        // Prepare the prompt for local AI
        $fullPrompt = $systemPrompt;
        if (!empty($serverInfo)) {
            $fullPrompt .= "\n\nServer Information:\n" . $serverInfo;
        }
        if (!empty($serverFeatures)) {
            $fullPrompt .= "\n\n" . $serverFeatures;
        }
        
        // Add conversation history (limited to the configured maximum)
        $maxHistory = $this->plugin->getConfig()->getNested("prompts.max_conversation_history", 10);
        $historyCount = count($conversationHistory);
        
        if ($historyCount > 0) {
            $startIndex = max(0, $historyCount - $maxHistory);
            
            $fullPrompt .= "\n\nConversation History:";
            for ($i = $startIndex; $i < $historyCount; $i++) {
                $fullPrompt .= "\nUser: " . $conversationHistory[$i]["query"];
                $fullPrompt .= "\nAssistant: " . $conversationHistory[$i]["response"];
            }
        }
        
        // Add the current query
        $fullPrompt .= "\n\nUser: " . $query;
        $fullPrompt .= "\nAssistant:";
        
        // Prepare the request data for Local AI
        $requestData = [
            "prompt" => $fullPrompt,
            "model" => $this->model,
            "temperature" => $this->temperature,
            "max_tokens" => $this->maxTokens,
            "stop" => ["User:", "\nUser:"]
        ];
        
        // Return complete HTTP request configuration
        return [
            "url" => $this->endpoint,
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
        if ($config->getNested("api_providers.local.enabled", false)) {
            $this->endpoint = (string) $config->getNested("api_providers.local.endpoint", "http://localhost:8080/v1/completions");
            $this->model = (string) $config->getNested("api_providers.local.model", "local-model");
            $this->temperature = (float) $config->getNested("api_providers.local.temperature", 0.7);
            $this->maxTokens = (int) $config->getNested("api_providers.local.max_tokens", 500);
            $this->timeout = (int) $config->getNested("api_providers.local.timeout", 30);
        }
    }

    /**
     * Generate a response from Local AI
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
            return MinecraftTextFormatter::COLOR_RED . "Local AI is not properly configured. Please check your endpoint.";
        }
        
        try {
            // Check if the request has been cancelled
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            // Get player name from current context - will be passed properly in future versions
            $onlinePlayers = $this->plugin->getServer()->getOnlinePlayers();
            $playerName = count($onlinePlayers) > 0 ? array_values($onlinePlayers)[0]->getName() : "SYSTEM";
            $activeRequest = $requestManager->getActiveRequest($playerName);
            
            if ($activeRequest !== null && isset($activeRequest['id'])) {
                $requestId = $activeRequest['id'];
                if ($requestManager->isRequestCancelled($requestId)) {
                    return MinecraftTextFormatter::COLOR_YELLOW . "Request was cancelled.";
                }
            }
            
            // Prepare the prompt for local AI
            $fullPrompt = $systemPrompt;
            if (!empty($serverInfo)) {
                $fullPrompt .= "\n\nServer Information:\n" . $serverInfo;
            }
            if (!empty($serverFeatures)) {
                $fullPrompt .= "\n\n" . $serverFeatures;
            }
            
            // Add conversation history (limited to the configured maximum)
            $maxHistory = $this->plugin->getConfig()->getNested("prompts.max_conversation_history", 10);
            $historyCount = count($conversationHistory);
            
            if ($historyCount > 0) {
                $startIndex = max(0, $historyCount - $maxHistory);
                
                $fullPrompt .= "\n\nConversation History:";
                for ($i = $startIndex; $i < $historyCount; $i++) {
                    $fullPrompt .= "\nUser: " . $conversationHistory[$i]["query"];
                    $fullPrompt .= "\nAssistant: " . $conversationHistory[$i]["response"];
                }
            }
            
            // Add the current query
            $fullPrompt .= "\n\nUser: " . $query;
            $fullPrompt .= "\nAssistant:";
            
            // Prepare the request data for Local AI
            $data = [
                "prompt" => $fullPrompt,
                "model" => $this->model,
                "temperature" => $this->temperature,
                "max_tokens" => $this->maxTokens,
                "stop" => ["User:", "\nUser:"]
            ];
            
            // Make the API request
            $response = $this->makeRequest($this->endpoint, $data);
            
            // Parse the response
            $responseData = json_decode($response, true);
            
            if (isset($responseData["choices"][0]["text"])) {
                $aiResponse = $responseData["choices"][0]["text"];
                
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
                    $this->plugin->getLogger()->debug("Local AI error: " . json_encode($responseData["error"]));
                }
                return MinecraftTextFormatter::COLOR_RED . "I couldn't generate a response. Please try again later.";
            }
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Local AI error: " . $e->getMessage());
            return MinecraftTextFormatter::COLOR_RED . "An error occurred while communicating with the AI service. Please try again later.";
        }
    }

    /**
     * Parse HTTP response from Local AI API into clean response text
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
                    'error' => 'Local AI API error: ' . $result['error']
                ];
            }
            
            if (empty($httpResponse)) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Empty response from Local AI API'
                ];
            }

            // Parse the JSON response
            $responseData = json_decode($httpResponse, true);

            if ($responseData === null) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Invalid JSON response from Local AI: ' . json_last_error_msg()
                ];
            }

            // Extract the response text from Local AI completion format
            if (isset($responseData["choices"][0]["text"])) {
                $aiResponse = trim($responseData["choices"][0]["text"]);
                
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

            // Handle error responses from Local AI API
            if (isset($responseData["error"])) {
                $errorMsg = is_array($responseData["error"]) ? 
                    json_encode($responseData["error"]) : 
                    (string)$responseData["error"];
                
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Local AI error: ' . $errorMsg
                ];
            }

            // If no choices or errors found, return generic error
            return [
                'success' => false,
                'content' => '',
                'error' => 'Local AI: Unexpected response format - no choices found'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'content' => '',
                'error' => 'Local AI parsing error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Make an HTTP request to the Local AI endpoint
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
        return "Local AI";
    }

    /**
     * Get the provider description
     * 
     * @return string
     */
    public function getDescription(): string {
        return "Local AI provider using model: " . $this->model;
    }

    /**
     * Check if the provider is properly configured
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return !empty($this->endpoint) && $this->endpoint !== "http://localhost:8080/v1/completions";
    }
}