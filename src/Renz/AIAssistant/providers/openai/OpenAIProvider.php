<?php

declare(strict_types=1);

namespace Renz\AIAssistant\providers\openai;

use Renz\AIAssistant\Main;
use Renz\AIAssistant\providers\AIProvider;
use Renz\AIAssistant\utils\MinecraftTextFormatter;
use Renz\AIAssistant\tasks\HttpRequestTask;

class OpenAIProvider implements AIProvider {
    /** @var Main */
    private Main $plugin;
    
    /** @var string */
    private string $apiKey = "";
    
    /** @var string */
    private string $model = "gpt-3.5-turbo";
    
    /** @var float */
    private float $temperature = 0.7;
    
    /** @var int */
    private int $maxTokens = 500;
    
    /** @var int */
    private int $timeout = 30;

    /**
     * OpenAIProvider constructor.
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
        if ($config->getNested("api_providers.openai.enabled", false)) {
            $this->apiKey = (string) $config->getNested("api_providers.openai.api_key", "");
            $this->model = (string) $config->getNested("api_providers.openai.model", "gpt-3.5-turbo");
            $this->temperature = (float) $config->getNested("api_providers.openai.temperature", 0.7);
            $this->maxTokens = (int) $config->getNested("api_providers.openai.max_tokens", 500);
            $this->timeout = (int) $config->getNested("api_providers.openai.timeout", 30);
        }
    }

    /**
     * Generate a response from OpenAI - Pure HTTP payload builder
     * This method builds the HTTP request payload but does not submit it.
     * The ProviderManager handles all request lifecycle management.
     * 
     * @param string $query The user's query
     * @param array $conversationHistory Previous conversation history
     * @param string $systemPrompt The system prompt to use
     * @param string $serverInfo Additional server information
     * @param string $serverFeatures Relevant server features for the query
     * @return array HTTP request data array
     */
    public function buildHttpRequest(string $query, array $conversationHistory, string $systemPrompt, string $serverInfo, string $serverFeatures = ""): array {
        if (!$this->isConfigured()) {
            throw new \RuntimeException("OpenAI API is not properly configured. Please check your API key.");
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
        $requestData = [
            "model" => $this->model,
            "messages" => $messages,
            "temperature" => $this->temperature,
            "max_tokens" => $this->maxTokens
        ];
        
        // Return complete HTTP request configuration
        return [
            "url" => "https://api.openai.com/v1/chat/completions",
            "method" => "POST",
            "headers" => [
                "Authorization: Bearer " . trim($this->apiKey),
                "Content-Type: application/json"
            ],
            "data" => $requestData,
            "timeout" => $this->timeout
        ];
    }
    
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
            return MinecraftTextFormatter::COLOR_RED . "OpenAI API is not properly configured. Please check your API key.";
        }
        
        // This is now just a processing message - actual HTTP submission handled by ProviderManager
        return MinecraftTextFormatter::COLOR_YELLOW . "Processing your OpenAI request... Please wait.";
    }

    /**
     * Async HTTP request callback - handles response from HttpRequestTask
     * 
     * @param array $result
     */
    /**
     * Parse HTTP response from OpenAI API into clean response text
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
                    'error' => 'OpenAI API error: ' . $result['error']
                ];
            }
            
            if (empty($httpResponse)) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Empty response from OpenAI API'
                ];
            }
            
            // Parse the JSON response
            $responseData = json_decode($httpResponse, true);
            if ($responseData === null) {
                return [
                    'success' => false,
                    'content' => '',
                    'error' => 'Invalid JSON response from OpenAI API'
                ];
            }
            
            // Extract the AI response content
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
                
                return [
                    'success' => true,
                    'content' => $aiResponse,
                    'error' => ''
                ];
            } else {
                $errorMsg = 'No content in OpenAI response';
                if (isset($responseData["error"])) {
                    $errorMsg .= ': ' . json_encode($responseData["error"]);
                    if ($this->plugin->isDebugEnabled()) {
                        $this->plugin->getLogger()->debug("OpenAI API error: " . json_encode($responseData["error"]));
                    }
                }
                
                return [
                    'success' => false,
                    'content' => '',
                    'error' => $errorMsg
                ];
            }
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("OpenAI response parsing error: " . $e->getMessage());
            return [
                'success' => false,
                'content' => '',
                'error' => 'Error parsing OpenAI response: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Legacy async HTTP request callback - DEPRECATED
     * This method is kept for backwards compatibility but should not be used.
     * Response handling is now done by ProviderManager via parseResponse method.
     * 
     * @param array $result
     */
    public function handleAsyncResponse(array $result): void {
        $requestId = $result['requestId'] ?? '';
        if (empty($requestId)) {
            $this->plugin->getLogger()->error("OpenAI async response missing request ID");
            return;
        }
        
        $requestManager = $this->plugin->getProviderManager()->getRequestManager();
        $pendingRequest = $requestManager->getPendingRequest($requestId);
        
        if ($pendingRequest === null) {
            $this->plugin->getLogger()->debug("OpenAI request {$requestId} not found in pending requests");
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
            $this->plugin->getLogger()->debug("Player {$playerName} not found for OpenAI response");
            return;
        }
        
        // Check for HTTP errors
        if (isset($result['error'])) {
            $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: OpenAI API error: " . $result['error']);
            return;
        }
        
        $response = $result['response'] ?? '';
        if (empty($response)) {
            $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: Empty response from OpenAI API");
            return;
        }
        
        try {
            // Parse the response
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
                
                // Save conversation
                $this->plugin->getConversationManager()->addConversation($playerName, $query, $aiResponse);
                
                // Check if this is a form request
                $formContext = $requestManager->getFormContext($playerName);
                if ($formContext !== null) {
                    // Handle form response
                    $question = $formContext['question'];
                    $tokenManager = $formContext['tokenManager'];
                    
                    // Deduct token if enabled (only after successful response)
                    if ($tokenManager->isEnabled()) {
                        $tokenManager->useToken($player);
                    }
                    
                    // Send appropriate success notification based on form type
                    switch ($formContext['type']) {
                        case 'crafting_form':
                            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "recipe_found");
                            break;
                        case 'building_form':
                            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "calculation_complete");
                            break;
                        default:
                            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "response_ready");
                            break;
                    }
                    
                    // Clear form context
                    $requestManager->clearFormContext($playerName);
                    
                    // Show response form with small delay
                    $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
                        function() use ($player, $question, $aiResponse): void {
                            if ($player->isOnline()) {
                                $form = new \Renz\AIAssistant\forms\ResponseForm($this->plugin);
                                $form->sendTo($player, $question, $aiResponse);
                            }
                        }
                    ), 3);
                } else {
                    // Send response to player (command usage) 
                    $player->sendMessage(MinecraftTextFormatter::COLOR_GREEN . "OpenAI: " . MinecraftTextFormatter::COLOR_WHITE . $aiResponse);
                    
                    // Complete request tracking
                    $requestManager->completeRequest($playerName, $requestId ?? '');
                }
                
            } else {
                if ($this->plugin->isDebugEnabled() && isset($responseData["error"])) {
                    $this->plugin->getLogger()->debug("OpenAI API error: " . json_encode($responseData["error"]));
                }
                $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: I couldn't generate a response. Please try again later.");
                
                // Clear form context on error
                $requestManager->clearFormContext($playerName);
                $requestManager->completeRequest($playerName, $requestId ?? '');
            }
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error("OpenAI response parsing error: " . $e->getMessage());
            $player->sendMessage(MinecraftTextFormatter::COLOR_RED . "ERROR: Error processing OpenAI response.");
            
            // Clear form context on error
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            $requestManager->clearFormContext($playerName);
            $requestManager->completeRequest($playerName, $requestId ?? '');
        }
    }

    /**
     * Get the provider name
     * 
     * @return string
     */
    public function getName(): string {
        return "OpenAI";
    }

    /**
     * Get the provider description
     * 
     * @return string
     */
    public function getDescription(): string {
        return "OpenAI API provider using model: " . $this->model;
    }

    /**
     * Check if the provider is properly configured
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return !empty($this->apiKey) && $this->apiKey !== "YOUR_OPENAI_API_KEY";
    }
    
    /**
     * Test API connection by making a simple request
     * 
     * @return array Test result with success/error info
     */
    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'OpenAI API is not properly configured. Please check your API key.'
            ];
        }
        
        try {
            // Build simple test request
            $testRequest = $this->buildHttpRequest(
                "Hello, can you respond with just 'Test successful'?",
                [],
                "You are a test assistant. Respond only with the exact phrase: Test successful",
                "",
                ""
            );
            
            // Execute curl request directly for testing
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $testRequest['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testRequest['data']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $testRequest['headers']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                return [
                    'success' => false,
                    'error' => 'Curl error: ' . $error
                ];
            }
            
            if ($httpCode !== 200) {
                return [
                    'success' => false,
                    'error' => 'HTTP error: ' . $httpCode . ' - ' . $response
                ];
            }
            
            // Parse response
            $parseResult = $this->parseResponse($response);
            if ($parseResult['success']) {
                return [
                    'success' => true,
                    'response' => $parseResult['content'],
                    'message' => 'OpenAI API connection successful!'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'API response parsing failed: ' . $parseResult['error']
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Test exception: ' . $e->getMessage()
            ];
        }
    }
}