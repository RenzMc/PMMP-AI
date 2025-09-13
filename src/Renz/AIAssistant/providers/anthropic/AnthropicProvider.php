<?php

declare(strict_types=1);

namespace Renz\AIAssistant\providers\anthropic;

use Renz\AIAssistant\Main;
use Renz\AIAssistant\providers\AIProvider;
use Renz\AIAssistant\utils\MinecraftTextFormatter;
use Renz\AIAssistant\tasks\HttpRequestTask;

class AnthropicProvider implements AIProvider {
    /** @var Main */
    private Main $plugin;
    
    /** @var string */
    private string $apiKey = "";
    
    /** @var string */
    private string $model = "claude-3-5-sonnet-20241022";
    
    /** @var float */
    private float $temperature = 0.7;
    
    /** @var int */
    private int $maxTokens = 500;
    
    /** @var int */
    private int $timeout = 30;

    /**
     * AnthropicProvider constructor.
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
            throw new \Exception("Anthropic API is not properly configured. Please check your API key.");
        }

        $messages = [];
        
        // Add conversation history (limited to the configured maximum)
        $maxHistory = $this->plugin->getConfig()->getNested("prompts.max_conversation_history", 10);
        $historyCount = count($conversationHistory);
        
        if ($historyCount > 0) {
            $startIndex = max(0, $historyCount - $maxHistory);
            
            for ($i = $startIndex; $i < $historyCount; $i++) {
                $messages[] = ["role" => "user", "content" => [["type" => "text", "text" => $conversationHistory[$i]["query"]]]];
                $messages[] = ["role" => "assistant", "content" => [["type" => "text", "text" => $conversationHistory[$i]["response"]]]];
            }
        }
        
        // Add the current query
        $messages[] = ["role" => "user", "content" => [["type" => "text", "text" => $query]]];
        
        // Prepare system prompt
        $fullSystemPrompt = $systemPrompt;
        if (!empty($serverInfo)) {
            $fullSystemPrompt .= "\n\nServer Information:\n" . $serverInfo;
        }
        if (!empty($serverFeatures)) {
            $fullSystemPrompt .= "\n\n" . $serverFeatures;
        }
        
        $requestData = [
            "model" => $this->model,
            "system" => $fullSystemPrompt,
            "messages" => $messages,
            "temperature" => $this->temperature,
            "max_tokens" => $this->maxTokens
        ];
        
        return [
            "url" => "https://api.anthropic.com/v1/messages",
            "method" => "POST",
            "headers" => [
                "X-API-Key: " . trim($this->apiKey),
                "Content-Type: application/json",
                "anthropic-version: 2023-06-01"
            ],
            "data" => $requestData,
            "timeout" => $this->timeout
        ];
    }

    /**
     * Parse HTTP response from Anthropic API
     */
    public function parseResponse(string $httpResponse, array $result = []): array {
        try {
            if (isset($result['error'])) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Anthropic API error: ' . $result['error']
                ];
            }
            
            if (empty($httpResponse)) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Empty response from Anthropic API'
                ];
            }

            $responseData = json_decode($httpResponse, true);
            if ($responseData === null) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Invalid JSON response from Anthropic: ' . json_last_error_msg()
                ];
            }

            if (isset($responseData["content"][0]["text"])) {
                $aiResponse = trim($responseData["content"][0]["text"]);
                
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
                    'error' => 'Anthropic error: ' . $errorMsg
                ];
            }

            return [
                'success' => false,
                'content' => '',
                'error' => 'Anthropic: Unexpected response format'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'content' => '',
                'error' => 'Anthropic parsing error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Load configuration for this provider
     */
    private function loadConfig(): void {
        $config = $this->plugin->getConfig();
        
        // Only load config if the provider is enabled
        if ($config->getNested("api_providers.anthropic.enabled", false)) {
            $this->apiKey = (string) $config->getNested("api_providers.anthropic.api_key", "");
            $this->model = (string) $config->getNested("api_providers.anthropic.model", "claude-3-5-sonnet-20241022");
            $this->temperature = (float) $config->getNested("api_providers.anthropic.temperature", 0.7);
            $this->maxTokens = (int) $config->getNested("api_providers.anthropic.max_tokens", 500);
            $this->timeout = (int) $config->getNested("api_providers.anthropic.timeout", 30);
        }
    }

    /**
     * Generate a response from Anthropic Claude
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
            return MinecraftTextFormatter::COLOR_RED . "Anthropic API is not properly configured. Please check your API key.";
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
            
            // Prepare the messages array for modern Claude API
            $messages = [];
            
            // Add system prompt
            $fullSystemPrompt = $systemPrompt;
            if (!empty($serverInfo)) {
                $fullSystemPrompt .= "\n\nServer Information:\n" . $serverInfo;
            }
            if (!empty($serverFeatures)) {
                $fullSystemPrompt .= "\n\n" . $serverFeatures;
            }
            
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
            
            // Prepare the request data for modern Claude Messages API
            $data = [
                "model" => $this->model,
                "system" => $fullSystemPrompt,
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
                    "X-API-Key: " . $this->apiKey,
                    "Content-Type: application/json",
                    "anthropic-version: 2023-06-01"
                ];
                
                $jsonData = json_encode($data);
                
                // Submit async HTTP request with SSL support (HttpRequestTask handles its own submission)
                $task = new HttpRequestTask(
                    "https://api.anthropic.com/v1/messages",
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
            $this->plugin->getLogger()->error("Anthropic API error: " . $e->getMessage());
            return MinecraftTextFormatter::COLOR_RED . "An error occurred while communicating with the AI service. Please try again later.";
        }
    }

    /**
     * Async HTTP request callback - handles response from HttpRequestTask
     * 
     * @param array $result
     */
    public function handleAsyncResponse(array $result): void {
        $requestId = $result['requestId'] ?? '';
        if (empty($requestId)) {
            $this->plugin->getLogger()->error("Anthropic async response missing request ID");
            return;
        }
        
        $requestManager = $this->plugin->getProviderManager()->getRequestManager();
        $pendingRequest = $requestManager->getPendingRequest($requestId);
        
        if ($pendingRequest === null) {
            $this->plugin->getLogger()->debug("Anthropic request {$requestId} not found in pending requests");
            return;
        }
        
        $playerName = $pendingRequest['playerName'];
        $query = $pendingRequest['query'];
        $conversationHistory = $pendingRequest['conversationHistory'];
        
        // Clean up pending request
        $requestManager->removePendingRequest($requestId);
        
        // Get player instance
        $player = $this->plugin->getServer()->getPlayerExact($playerName);
        if ($player === null) {
            $this->plugin->getLogger()->debug("Player {$playerName} not found for Anthropic response");
            return;
        }
        
        // Check for HTTP errors
        if (isset($result['error'])) {
            $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: Anthropic API error: " . $result['error']);
            return;
        }
        
        $response = $result['response'] ?? '';
        if (empty($response)) {
            $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: Empty response from Anthropic API");
            return;
        }
        
        try {
            // Parse the response
            $responseData = json_decode($response, true);
            
            if (isset($responseData["content"][0]["text"])) {
                $aiResponse = $responseData["content"][0]["text"];
                
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
                
                // Save conversation
                $this->plugin->getConversationManager()->addConversation($playerName, $query, $aiResponse);
                
                // Send response to player
                $player->sendMessage(MinecraftTextFormatter::COLOR_GREEN . "Claude: " . MinecraftTextFormatter::COLOR_WHITE . $aiResponse);
                
            } else {
                if ($this->plugin->isDebugEnabled() && isset($responseData["error"])) {
                    $this->plugin->getLogger()->debug("Anthropic API error: " . json_encode($responseData["error"]));
                }
                $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: I couldn't generate a response. Please try again later.");
            }
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("Anthropic response parsing error: " . $e->getMessage());
            $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: Error processing Anthropic response.");
        }
    }

    /**
     * Get the provider name
     * 
     * @return string
     */
    public function getName(): string {
        return "Anthropic Claude";
    }

    /**
     * Get the provider description
     * 
     * @return string
     */
    public function getDescription(): string {
        return "Anthropic Claude API provider using model: " . $this->model;
    }

    /**
     * Check if the provider is properly configured
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return !empty($this->apiKey) && $this->apiKey !== "YOUR_ANTHROPIC_API_KEY";
    }
}