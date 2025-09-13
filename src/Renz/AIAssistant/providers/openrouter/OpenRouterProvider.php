<?php

declare(strict_types=1);

namespace Renz\AIAssistant\providers\openrouter;

use Renz\AIAssistant\Main;
use Renz\AIAssistant\providers\AIProvider;
use Renz\AIAssistant\utils\MinecraftTextFormatter;
use Renz\AIAssistant\tasks\HttpRequestTask;

class OpenRouterProvider implements AIProvider {
    /** @var Main */
    private Main $plugin;
    
    /** @var string */
    private string $apiKey = "";
    
    /** @var string */
    private string $model = "openai/gpt-3.5-turbo";
    
    /** @var float */
    private float $temperature = 0.7;
    
    /** @var int */
    private int $maxTokens = 500;
    
    /** @var int */
    private int $timeout = 30;

    /**
     * OpenRouterProvider constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadConfig();
    }

    /**
     * Build HTTP request configuration for async processing
     */
    public function buildHttpRequest(string $query, array $conversationHistory, string $systemPrompt, string $serverInfo, string $serverFeatures = ""): array {
        if (!$this->isConfigured()) {
            throw new \Exception("OpenRouter API is not properly configured. Please check your API key.");
        }

        $messages = [];
        
        // Add system prompt
        $fullSystemPrompt = $systemPrompt;
        if (!empty($serverInfo)) {
            $fullSystemPrompt .= "\n\nServer Information:\n" . $serverInfo;
        }
        if (!empty($serverFeatures)) {
            $fullSystemPrompt .= "\n\n" . $serverFeatures;
        }
        
        $messages[] = ["role" => "system", "content" => $fullSystemPrompt];
        
        // Add conversation history (limited to the configured maximum)
        $maxHistory = $this->plugin->getConfig()->getNested("prompts.max_conversation_history", 10);
        $historyCount = count($conversationHistory);
        
        if ($historyCount > 0) {
            $startIndex = max(0, $historyCount - $maxHistory);
            
            for ($i = $startIndex; $i < $historyCount; $i++) {
                $messages[] = ["role" => "user", "content" => $conversationHistory[$i]["query"]];
                $messages[] = ["role" => "assistant", "content" => $conversationHistory[$i]["response"]];
            }
        }
        
        // Add the current query
        $messages[] = ["role" => "user", "content" => $query];
        
        $requestData = [
            "model" => $this->model,
            "messages" => $messages,
            "temperature" => $this->temperature,
            "max_tokens" => $this->maxTokens
        ];
        
        return [
            "url" => "https://openrouter.ai/api/v1/chat/completions",
            "method" => "POST",
            "headers" => [
                "Authorization: Bearer " . trim($this->apiKey),
                "Content-Type: application/json",
                "HTTP-Referer: https://github.com/RenzMc/PMMP-AI",
                "X-Title: PocketMine AI Assistant"
            ],
            "data" => $requestData,
            "timeout" => $this->timeout
        ];
    }

    /**
     * Parse HTTP response from OpenRouter API
     */
    public function parseResponse(string $httpResponse, array $result = []): array {
        try {
            if (isset($result['error'])) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'OpenRouter API error: ' . $result['error']
                ];
            }
            
            if (empty($httpResponse)) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Empty response from OpenRouter API'
                ];
            }

            $responseData = json_decode($httpResponse, true);
            if ($responseData === null) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Invalid JSON response from OpenRouter: ' . json_last_error_msg()
                ];
            }

            if (isset($responseData["choices"][0]["message"]["content"])) {
                $aiResponse = trim($responseData["choices"][0]["message"]["content"]);
                
                $maxLength = $this->plugin->getConfig()->getNested("advanced.max_response_length", 1000);
                if (strlen($aiResponse) > $maxLength) {
                    $aiResponse = substr($aiResponse, 0, $maxLength) . "...";
                }
                
                if (!str_contains($aiResponse, "§")) {
                    $aiResponse = MinecraftTextFormatter::formatText($aiResponse);
                }
                
                return [
                    'success' => true,
                    'content' => $aiResponse,
                    'error' => ''
                ];
            }

            if (isset($responseData["error"])) {
                $errorMsg = is_array($responseData["error"]) ? 
                    json_encode($responseData["error"]) : 
                    (string)$responseData["error"];
                
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'OpenRouter error: ' . $errorMsg
                ];
            }

            return [
                'success' => false,
                'content' => '',
                'error' => 'OpenRouter: Unexpected response format'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'content' => '',
                'error' => 'OpenRouter parsing error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Load configuration for this provider
     */
    private function loadConfig(): void {
        $config = $this->plugin->getConfig();
        
        // Only load config if the provider is enabled
        if ($config->getNested("api_providers.openrouter.enabled", false)) {
            $this->apiKey = (string) $config->getNested("api_providers.openrouter.api_key", "");
            $this->model = (string) $config->getNested("api_providers.openrouter.model", "openai/gpt-3.5-turbo");
            $this->temperature = (float) $config->getNested("api_providers.openrouter.temperature", 0.7);
            $this->maxTokens = (int) $config->getNested("api_providers.openrouter.max_tokens", 500);
            $this->timeout = (int) $config->getNested("api_providers.openrouter.timeout", 30);
        }
    }

    /**
     * Generate a response from OpenRouter
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
            return MinecraftTextFormatter::COLOR_RED . "OpenRouter API is not properly configured. Please check your API key.";
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
            
            // Prepare the messages array
            $messages = [];
            
            // Add system prompt
            $fullSystemPrompt = $systemPrompt;
            if (!empty($serverInfo)) {
                $fullSystemPrompt .= "\n\nServer Information:\n" . $serverInfo;
            }
            if (!empty($serverFeatures)) {
                $fullSystemPrompt .= "\n\n" . $serverFeatures;
            }
            $messages[] = ["role" => "system", "content" => $fullSystemPrompt];
            
            // Add conversation history (limited to the configured maximum)
            $maxHistory = $this->plugin->getConfig()->getNested("prompts.max_conversation_history", 10);
            $historyCount = count($conversationHistory);
            
            if ($historyCount > 0) {
                $startIndex = max(0, $historyCount - $maxHistory);
                
                for ($i = $startIndex; $i < $historyCount; $i++) {
                    $messages[] = ["role" => "user", "content" => $conversationHistory[$i]["query"]];
                    $messages[] = ["role" => "assistant", "content" => $conversationHistory[$i]["response"]];
                }
            }
            
            // Add the current query
            $messages[] = ["role" => "user", "content" => $query];
            
            // Prepare the request data
            $data = [
                "model" => $this->model,
                "messages" => $messages,
                "temperature" => $this->temperature,
                "max_tokens" => $this->maxTokens
            ];
            
            // Get request context for async processing
            if ($activeRequest !== null && isset($activeRequest['id'])) {
                $requestId = $activeRequest['id'];
                
                // Store request data for async callback
                $requestManager->setPendingRequest($requestId, [
                    'playerName' => $playerName,
                    'query' => $query,
                    'conversationHistory' => $conversationHistory,
                    'systemPrompt' => $systemPrompt,
                    'serverInfo' => $serverInfo,
                    'serverFeatures' => $serverFeatures,
                    'timestamp' => time()
                ]);
                
                // Prepare headers for HTTP request
                $headers = [
                    "Authorization: Bearer " . trim($this->apiKey),
                    "Content-Type: application/json",
                    "HTTP-Referer: https://github.com/RenzMc/PMMP-AI",
                    "X-Title: PocketMine AI Assistant"
                ];
                
                $jsonData = json_encode($data);
                
                // Submit async HTTP request with SSL support (HttpRequestTask handles its own submission)
                $task = new HttpRequestTask(
                    "https://openrouter.ai/api/v1/chat/completions",
                    $headers,
                    $this,
                    "handleAsyncResponse",
                    "POST",
                    $jsonData,
                    $requestId,
                    $this->timeout,
                    $this->plugin->cainfo_path ?? ""
                );
                
                return MinecraftTextFormatter::COLOR_YELLOW . "Processing your request... Please wait.";
            } else {
                return MinecraftTextFormatter::COLOR_RED . "Unable to process request - no active request ID.";
            }
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("OpenRouter API error: " . $e->getMessage());
            return MinecraftTextFormatter::COLOR_RED . "An error occurred while communicating with the AI service. Please try again later.";
        }
    }

    /**
     * Async HTTP request callback - handles response from HttpRequestTask
     * 
     * @param array $result
     * @return void
     */
    public function handleAsyncResponse(array $result): void {
        $requestId = $result['requestId'] ?? '';
        $requestManager = $this->plugin->getProviderManager()->getRequestManager();
        
        // Get the pending request data
        $pendingRequest = $requestManager->getPendingRequest($requestId);
        if ($pendingRequest === null) {
            if ($this->plugin->isDebugEnabled()) {
                $this->plugin->getLogger()->debug("No pending request found for ID: {$requestId}");
            }
            return;
        }
        
        $playerName = $pendingRequest['playerName'];
        $player = $this->plugin->getServer()->getPlayerExact($playerName);
        
        try {
            // Check if request was cancelled
            if ($requestManager->isRequestCancelled($requestId)) {
                if ($player !== null && $player->isOnline()) {
                    $player->sendMessage(MinecraftTextFormatter::COLOR_YELLOW . "AI request was cancelled.");
                }
                return;
            }
            
            if (isset($result['error'])) {
                // Log the detailed error for debugging
                if ($this->plugin->isDebugEnabled()) {
                    $this->plugin->getLogger()->debug("OpenRouter API error details: " . json_encode($result));
                }
                throw new \Exception($result['error']);
            }
            
            if (!isset($result['response'])) {
                // Log the full result for debugging
                if ($this->plugin->isDebugEnabled()) {
                    $this->plugin->getLogger()->debug("Missing response data: " . json_encode($result));
                }
                throw new \Exception("No response data received");
            }
            
            $response = $result['response'];
            $responseData = json_decode($response, true);
            
            if (isset($responseData["choices"][0]["message"]["content"])) {
                $aiResponse = $responseData["choices"][0]["message"]["content"];
                
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
                
                // Send response to player
                if ($player !== null && $player->isOnline()) {
                    $player->sendMessage($aiResponse);
                    
                    // Add to conversation history
                    $conversationManager = $this->plugin->getConversationManager();
                    $conversationManager->addToConversation($playerName, $pendingRequest['query'], $aiResponse);
                    
                    // Log the interaction if enabled
                    if ($this->plugin->getConfig()->getNested("advanced.log_interactions", true)) {
                        $this->plugin->getLogger()->info("AI Interaction - Player: {$playerName}, Query: " . substr($pendingRequest['query'], 0, 30) . "...");
                    }
                }
            } else {
                throw new \Exception("Invalid API response format");
            }
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            $this->plugin->getLogger()->error("OpenRouter API error: " . $errorMsg);
            
            // Log the stack trace in debug mode
            if ($this->plugin->isDebugEnabled()) {
                $this->plugin->getLogger()->debug("Stack trace: " . $e->getTraceAsString());
            }
            
            if ($player !== null && $player->isOnline()) {
                // Send a more informative error message to the player
                $friendlyError = "I couldn't generate a response. ";
                
                // Add more context based on the error type
                if (strpos($errorMsg, "Unexpected result format") !== false) {
                    $friendlyError .= "There was an issue with the API connection. ";
                } elseif (strpos($errorMsg, "Internet request failed") !== false) {
                    $friendlyError .= "There was a network issue. ";
                } elseif (strpos($errorMsg, "Invalid API response") !== false) {
                    $friendlyError .= "The AI service returned an invalid response. ";
                }
                
                $friendlyError .= "Please try again later.";
                $player->sendMessage(MinecraftTextFormatter::COLOR_RED . $friendlyError);
            }
        } finally {
            // Clean up pending request and mark as complete
            $requestManager->removePendingRequest($requestId);
            $requestManager->completeRequest($playerName, $requestId);
        }
    }

    /**
     * Get the provider name
     * 
     * @return string
     */
    public function getName(): string {
        return "OpenRouter";
    }

    /**
     * Get the provider description
     * 
     * @return string
     */
    public function getDescription(): string {
        return "OpenRouter API provider using model: " . $this->model;
    }

    /**
     * Check if the provider is properly configured
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return !empty(trim($this->apiKey)) && trim($this->apiKey) !== "YOUR_OPENROUTER_API_KEY";
    }
}