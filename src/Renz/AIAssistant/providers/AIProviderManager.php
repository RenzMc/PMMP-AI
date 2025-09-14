<?php  
  
declare(strict_types=1);  
  
namespace Renz\AIAssistant\providers;  
  
use pocketmine\player\Player;  
use pocketmine\utils\TextFormat;  
use Renz\AIAssistant\Main;  
use Renz\AIAssistant\providers\openai\OpenAIProvider;  
use Renz\AIAssistant\providers\anthropic\AnthropicProvider;  
use Renz\AIAssistant\providers\google\GoogleAIProvider;  
use Renz\AIAssistant\providers\local\LocalAIProvider;  
use Renz\AIAssistant\providers\openrouter\OpenRouterProvider;  
use Renz\AIAssistant\utils\ResponseCache;  
use Renz\AIAssistant\utils\MinecraftTextFormatter;  
use Renz\AIAssistant\utils\RequestManager;  
  
class AIProviderManager {  
    /** @var Main */  
    private Main $plugin;  
      
    /** @var array<string, AIProvider> */  
    private array $providers = [];  
      
    /** @var string */  
    private string $defaultProvider;  
      
    /** @var ResponseCache */  
    private ResponseCache $cache;  
      
    /** @var RequestManager */  
    private RequestManager $requestManager;  
      
    /** @var array */  
    private array $rateLimits = [];

    /** @var array<string, string> Provider name mapping for case-insensitive lookup */
    private array $providerNameMap = [];
  
    /**  
     * AIProviderManager constructor.  
     *   
     * @param Main $plugin  
     */  
    public function __construct(Main $plugin) {  
        $this->plugin = $plugin;  
        $this->cache = new ResponseCache($plugin);  
        $this->requestManager = $plugin->getRequestManager();  
        $this->loadProviders();  
    }  
  
    /**  
     * Load all configured AI providers  
     */  
    private function loadProviders(): void {  
        $config = $this->plugin->getConfig();  
          
        // Set default provider  
        $this->defaultProvider = strtolower($config->getNested("api_providers.default_provider", "openai"));  
          
        // Clear existing providers and name mapping  
        $this->providers = [];  
        $this->providerNameMap = [];
          
        // Initialize OpenAI provider if enabled  
        if ($config->getNested("api_providers.openai.enabled", false)) {  
            try {  
                $this->providers["openai"] = new OpenAIProvider($this->plugin);  
                $this->updateProviderNameMap("openai");
                if ($this->plugin->isDebugEnabled()) {  
                    $this->plugin->getLogger()->debug("OpenAI provider initialized");  
                }  
            } catch (\Throwable $e) {  
                $this->plugin->getLogger()->error("Failed to initialize OpenAI provider: " . $e->getMessage());  
            }  
        }  
          
        // Initialize OpenRouter provider if enabled  
        if ($config->getNested("api_providers.openrouter.enabled", false)) {  
            try {  
                $this->providers["openrouter"] = new OpenRouterProvider($this->plugin);  
                $this->updateProviderNameMap("openrouter");
                if ($this->plugin->isDebugEnabled()) {  
                    $this->plugin->getLogger()->debug("OpenRouter provider initialized");  
                }  
            } catch (\Throwable $e) {  
                $this->plugin->getLogger()->error("Failed to initialize OpenRouter provider: " . $e->getMessage());  
            }  
        }  
          
        // Initialize Anthropic provider if enabled  
        if ($config->getNested("api_providers.anthropic.enabled", false)) {  
            try {  
                $this->providers["anthropic"] = new AnthropicProvider($this->plugin);  
                $this->updateProviderNameMap("anthropic");
                if ($this->plugin->isDebugEnabled()) {  
                    $this->plugin->getLogger()->debug("Anthropic provider initialized");  
                }  
            } catch (\Throwable $e) {  
                $this->plugin->getLogger()->error("Failed to initialize Anthropic provider: " . $e->getMessage());  
            }  
        }  
          
        // Initialize Google AI provider if enabled  
        if ($config->getNested("api_providers.google.enabled", false)) {  
            try {  
                $this->providers["google"] = new GoogleAIProvider($this->plugin);  
                $this->updateProviderNameMap("google");
                if ($this->plugin->isDebugEnabled()) {  
                    $this->plugin->getLogger()->debug("Google AI provider initialized");  
                }  
            } catch (\Throwable $e) {  
                $this->plugin->getLogger()->error("Failed to initialize Google AI provider: " . $e->getMessage());  
            }  
        }  
          
        // Initialize Local AI provider if enabled  
        if ($config->getNested("api_providers.local.enabled", false)) {  
            try {  
                $this->providers["local"] = new LocalAIProvider($this->plugin);  
                $this->updateProviderNameMap("local");
                if ($this->plugin->isDebugEnabled()) {  
                    $this->plugin->getLogger()->debug("Local AI provider initialized");  
                }  
            } catch (\Throwable $e) {  
                $this->plugin->getLogger()->error("Failed to initialize Local AI provider: " . $e->getMessage());  
            }  
        }  
          
        // Check if we have at least one provider  
        if (empty($this->providers)) {  
            $this->plugin->getLogger()->warning("No AI providers are enabled! The plugin will use fallback responses.");  
        }  
          
        // Make sure the default provider exists, otherwise use the first available provider  
        $configuredDefaultProvider = strtolower($config->getNested("api_providers.default_provider", "openai"));  
        if (!isset($this->providers[$this->defaultProvider]) && !empty($this->providers)) {  
            $oldDefault = $this->defaultProvider;  
            $this->defaultProvider = array_key_first($this->providers);  
            $this->plugin->getLogger()->warning("Default provider '{$configuredDefaultProvider}' is not available or configured. Using '{$this->defaultProvider}' instead.");  
              
            if ($this->plugin->isDebugEnabled()) {  
                $this->plugin->getLogger()->debug("Available providers: " . implode(", ", array_keys($this->providers)));  
            }  
        } else if (isset($this->providers[$this->defaultProvider])) {  
            $this->plugin->getLogger()->info("Using '{$this->defaultProvider}' as the default AI provider.");  
        }  
          
        // Additional validation: check if the default provider is properly configured  
        if (isset($this->providers[$this->defaultProvider])) {  
            $provider = $this->providers[$this->defaultProvider];  
            if (!$provider->isConfigured()) {  
                $this->plugin->getLogger()->warning("Default provider '{$this->defaultProvider}' is not properly configured (missing API key or configuration).");  
            }  
        }  
    }

    /**
     * Update provider name mapping for case-insensitive lookup
     * 
     * @param string $providerName
     */
    private function updateProviderNameMap(string $providerName): void {
        $lowerName = strtolower($providerName);
        $this->providerNameMap[$lowerName] = $providerName;
        
        // Add common variations
        switch ($lowerName) {
            case "openrouter":
                $this->providerNameMap["openrouter"] = $providerName;
                $this->providerNameMap["open-router"] = $providerName;
                $this->providerNameMap["open_router"] = $providerName;
                break;
            case "openai":
                $this->providerNameMap["openai"] = $providerName;
                $this->providerNameMap["open-ai"] = $providerName;
                $this->providerNameMap["open_ai"] = $providerName;
                break;
        }
    }

    /**
     * Normalize provider name for case-insensitive matching
     * 
     * @param string $providerName
     * @return string|null
     */
    private function normalizeProviderName(string $providerName): ?string {
        $lowerName = strtolower(trim($providerName));
        return $this->providerNameMap[$lowerName] ?? null;
    }
  
    /**  
     * Process a query using the appropriate AI provider  
     *   
     * @param Player|null $player The player asking the question (null for system queries)  
     * @param string $query The question or request  
     * @param string|null $provider Specific provider to use (optional)  
     * @param bool $forceAI Force using AI even for system queries  
     * @return string The AI response  
     */  
    public function processQuery(?Player $player, string $query, ?string $provider = null, bool $forceAI = false): string {  
        $playerName = $player !== null ? $player->getName() : "SYSTEM";  
          
        // Generate a unique request ID  
        $requestId = $this->requestManager->generateRequestId();  
          
        // Track this request  
        $this->requestManager->trackRequest($playerName, $requestId, $query);  
          
        // Check rate limiting for players (not for system queries)  
        if ($player !== null && !$this->checkRateLimit($playerName)) {  
            return TextFormat::RED . "You are sending too many requests. Please wait a moment before trying again.";  
        }  
          
        // Check if we should use the cache  
        $config = $this->plugin->getConfig();  
        $useCaching = $config->getNested("advanced.cache_responses", true);  
          
        if ($useCaching && !$forceAI) {  
            $cachedResponse = $this->cache->getResponse($query);  
            if ($cachedResponse !== null) {  
                if ($this->plugin->isDebugEnabled()) {  
                    $this->plugin->getLogger()->debug("Using cached response for query: " . substr($query, 0, 30) . "...");  
                }  
                  
                // Mark the request as complete  
                $this->requestManager->completeRequest($playerName, $requestId);  
                  
                return $cachedResponse;  
            }  
        }  
          
        // Determine which provider to use with case-insensitive matching
        $providerName = $this->defaultProvider;
        if ($provider !== null) {
            $normalizedProvider = $this->normalizeProviderName($provider);
            if ($normalizedProvider !== null) {
                $providerName = $normalizedProvider;
            } else {
                $this->plugin->getLogger()->warning("Provider '{$provider}' not found or not available, falling back to default provider '{$this->defaultProvider}'");
                if ($this->plugin->isDebugEnabled()) {
                    $this->plugin->getLogger()->debug("Available providers: " . implode(", ", array_keys($this->providers)));
                    $this->plugin->getLogger()->debug("Available provider variations: " . implode(", ", array_keys($this->providerNameMap)));
                }
            }
        }
          
        // Double-check that the provider is properly configured  
        if (isset($this->providers[$providerName])) {  
            $selectedProvider = $this->providers[$providerName];  
            if (!$selectedProvider->isConfigured()) {  
                $this->plugin->getLogger()->warning("Provider '{$providerName}' is not properly configured. Trying to find an alternative...");  
                  
                // Try to find a properly configured provider  
                foreach ($this->providers as $name => $providerInstance) {  
                    if ($providerInstance->isConfigured()) {  
                        $providerName = $name;  
                        $this->plugin->getLogger()->info("Using provider '{$providerName}' as it is properly configured.");  
                        break;  
                    }  
                }  
            }  
        }  
          
        // If we have no providers at all, use fallback  
        if (empty($this->providers)) {  
            // Mark the request as complete  
            $this->requestManager->completeRequest($playerName, $requestId);  
              
            return $this->getFallbackResponse($query);  
        }  
          
        // Prepare common data needed for all provider attempts
        $conversationManager = $this->plugin->getConversationManager();  
        $conversationHistory = $player !== null ? $conversationManager->getConversation($playerName) : [];  
          
        // Get server info if enabled  
        $serverInfo = "";  
        if ($config->getNested("prompts.include_server_info", true)) {  
            $serverInfo = $player !== null ?   
                $this->plugin->getServerInfoProvider()->getServerInfoForPrompt($player) :   
                $this->plugin->getServerInfoProvider()->getServerInfoForPrompt();  
        }  
          
        // Get server features if enabled  
        $serverFeatures = "";  
        if ($config->getNested("prompts.include_server_features", true)) {  
            // Get relevant features based on the query - use getRelevantFeatures() which already returns formatted string  
            $serverFeatures = $this->plugin->getServerFeatureManager()->getRelevantFeatures($query);  
        }  
          
        // Get the system prompt  
        $systemPrompt = $this->getSystemPrompt();  
          
        // Add Minecraft formatting instructions to the system prompt  
        $systemPrompt .= "\n\nIMPORTANT: Format your responses using Minecraft color and formatting codes instead of Markdown. Use the following codes:  
- §0 to §f for colors (§6 for gold headers, §e for yellow subheadings, §f for white text)  
- §l for bold text  
- §o for italic text  
- §n for underlined text  
- §m for strikethrough text  
- §r to reset formatting  
  
Example formatting:  
§6§l[Header]§r  
§e[Subheading]§r  
§f[Regular text]  
  
DO NOT use Markdown formatting like #, ##, *, _, or `. Use ONLY Minecraft formatting codes.";

        // Create a list of providers to try, starting with the selected provider
        $providersToTry = [$providerName];
        
        // Add other available providers as fallbacks (excluding the already selected one)
        foreach (array_keys($this->providers) as $availableProvider) {
            if ($availableProvider !== $providerName && isset($this->providers[$availableProvider]) && $this->providers[$availableProvider]->isConfigured()) {
                $providersToTry[] = $availableProvider;
            }
        }
        
        // Track errors for debugging
        $errors = [];
        
        // Try each provider in sequence until one succeeds
        foreach ($providersToTry as $currentProviderName) {
            if (!isset($this->providers[$currentProviderName])) {
                continue; // Skip if provider doesn't exist
            }
            
            $provider = $this->providers[$currentProviderName];
            
            // Skip providers that aren't properly configured
            if (!$provider->isConfigured()) {
                continue;
            }
            
            try {
                // Log which provider we're trying
                if ($this->plugin->isDebugEnabled()) {
                    $this->plugin->getLogger()->debug("Attempting to use provider: {$currentProviderName}");
                }
                
                // Process the query with the current provider
                if (method_exists($provider, 'buildHttpRequest')) {
                    $response = $this->processAsyncRequest($provider, $player, $query, $conversationHistory, $systemPrompt, $serverInfo, $serverFeatures, $requestId);
                } else {
                    // Fallback to legacy synchronous method
                    $response = $provider->generateResponse($query, $conversationHistory, $systemPrompt, $serverInfo, $serverFeatures);
                }
                
                // Check if the request was cancelled
                if ($this->requestManager->isRequestCancelled($requestId)) {
                    if ($this->plugin->isDebugEnabled()) {
                        $this->plugin->getLogger()->debug("Request {$requestId} was cancelled, but response was already generated");
                    }
                    
                    // Return a cancelled message if the player is still online
                    if ($player !== null && $player->isOnline()) {
                        return TextFormat::YELLOW . "AI request was cancelled, but a response was already generated:\n\n" . $response;
                    }
                }
                
                // If we got here, the provider succeeded
                if ($currentProviderName !== $providerName) {
                    $this->plugin->getLogger()->info("Successfully switched from provider '{$providerName}' to '{$currentProviderName}' after failure");
                    
                    // Notify the player that we switched providers (only if it's not the original provider)
                    if ($player !== null && $player->isOnline()) {
                        $response = TextFormat::YELLOW . "[Note: Switched to {$provider->getName()} provider due to an issue with the primary provider]\n\n" . $response;
                    }
                }
                
                // Add the query and response to conversation history if player exists
                if ($player !== null) {
                    $conversationManager->addToConversation($playerName, $query, $response);
                }
                
                // Cache the response if caching is enabled
                if ($useCaching) {
                    $this->cache->cacheResponse($query, $response);
                }
                
                // Log the interaction if enabled
                if ($config->getNested("advanced.log_interactions", true)) {
                    $this->plugin->getLogger()->info("AI Interaction - Player: {$playerName}, Query: " . substr($query, 0, 30) . "...");
                }
                
                // Mark the request as complete
                $this->requestManager->completeRequest($playerName, $requestId);
                
                return $response;
                
            } catch (\Throwable $e) {
                // Log the error for this provider
                $errorMsg = "Error with provider '{$currentProviderName}': " . $e->getMessage();
                $this->plugin->getLogger()->warning($errorMsg);
                $errors[$currentProviderName] = $errorMsg;
                
                // Continue to the next provider
                continue;
            }
        }
        
        // If we get here, all providers failed
        $this->plugin->getLogger()->error("All providers failed for query. Errors: " . json_encode($errors));
        
        // Mark the request as complete
        $this->requestManager->completeRequest($playerName, $requestId);
        
        return TextFormat::RED . "All available AI providers failed to process your request. Please try again later.";  
    }  
  
    /**  
     * Get the system prompt for the AI  
     *   
     * @return string  
     */  
    public function getSystemPrompt(): string {  
        $config = $this->plugin->getConfig();  
        $customPrompt = $config->getNested("prompts.custom_system_prompt", "");  
          
        if (!empty($customPrompt)) {  
            return $customPrompt;  
        }  
          
        return $config->getNested("prompts.default_system_prompt", "You are an AI assistant for a Minecraft server.");  
    }  
  
    /**  
     * Set a custom system prompt  
     *   
     * @param string $prompt  
     */  
    public function setCustomSystemPrompt(string $prompt): void {  
        $config = $this->plugin->getConfig();  
        $config->setNested("prompts.custom_system_prompt", $prompt);  
        $config->save();  
    }  
      
    /**  
     * Process async HTTP request for modern providers  
     *   
     * @param AIProvider $provider  
     * @param Player|null $player  
     * @param string $query  
     * @param array $conversationHistory  
     * @param string $systemPrompt  
     * @param string $serverInfo  
     * @param string $serverFeatures  
     * @param string $requestId  
     * @return string  
     */  
    private function processAsyncRequest(AIProvider $provider, ?Player $player, string $query, array $conversationHistory, string $systemPrompt, string $serverInfo, string $serverFeatures, string $requestId): string {  
        $playerName = $player !== null ? $player->getName() : "SYSTEM";  
          
        try {  
            // Build HTTP request payload  
            $httpConfig = $provider->buildHttpRequest($query, $conversationHistory, $systemPrompt, $serverInfo, $serverFeatures);  
              
            // Store pending request data for async callback  
            $this->requestManager->setPendingRequest($requestId, [  
                'playerName' => $playerName,  
                'query' => $query,  
                'conversationHistory' => $conversationHistory,  
                'systemPrompt' => $systemPrompt,  
                'serverInfo' => $serverInfo,  
                'serverFeatures' => $serverFeatures,  
                'timestamp' => time(),  
                'provider' => $provider->getName()  
            ]);  
              
            // Prepare async HTTP task (HttpRequestTask handles its own submission via BulkCurlTask)  
            $task = new \Renz\AIAssistant\tasks\HttpRequestTask(  
                $httpConfig['url'],  
                $httpConfig['headers'],  
                $this,  
                'handleProviderAsyncResponse',  
                $httpConfig['method'],  
                json_encode($httpConfig['data']),  
                $requestId,  
                $httpConfig['timeout'],  
                $this->plugin->cainfo_path ?? ""  
            );  
              
            return \Renz\AIAssistant\utils\MinecraftTextFormatter::COLOR_YELLOW . "Processing your " . $provider->getName() . " request... Please wait.";  
              
        } catch (\Exception $e) {  
            $this->plugin->getLogger()->error("Error building async request: " . $e->getMessage());  
              
            // Cleanup on error  
            $this->requestManager->completeRequest($playerName, $requestId);  
              
            return \pocketmine\utils\TextFormat::RED . "An error occurred while processing your request: " . $e->getMessage();  
        }  
    }  
      
    /**  
     * Handle async HTTP response from providers  
     *   
     * @param array $result  
     */  
    public function handleProviderAsyncResponse(array $result): void {  
        $requestId = $result['requestId'] ?? '';  
        if (empty($requestId)) {  
            $this->plugin->getLogger()->error("Provider async response missing request ID");  
            return;  
        }  
          
        $pendingRequest = $this->requestManager->getPendingRequest($requestId);  
        if ($pendingRequest === null) {  
            $this->plugin->getLogger()->debug("Request {$requestId} not found in pending requests");  
            return;  
        }  
          
        $playerName = $pendingRequest['playerName'];  
        $query = $pendingRequest['query'];  
        $conversationHistory = $pendingRequest['conversationHistory'];  
        $providerName = $pendingRequest['provider'];  
          
        // Clean up pending request  
        $this->requestManager->removePendingRequest($requestId);  
          
        // Get player instance  
        $player = null;  
        if ($playerName !== "SYSTEM") {  
            $player = $this->plugin->getServer()->getPlayerExact($playerName);  
            if ($player === null) {  
                $this->plugin->getLogger()->debug("Player {$playerName} not found for provider response");  
                return;  
            }  
        }  
          
        // Get the provider that handled this request with case-insensitive matching
        $normalizedProviderName = $this->normalizeProviderName($providerName);
        if ($normalizedProviderName === null) {
            // Fallback to direct lookup for backward compatibility
            $normalizedProviderName = $providerName;
        }
        
        // Debug: Log available providers to help troubleshoot  
        $this->plugin->getLogger()->debug("Looking for provider '{$providerName}' (normalized: '{$normalizedProviderName}'). Available providers: " . implode(", ", array_keys($this->providers)));  
          
        $provider = $this->providers[$normalizedProviderName] ?? null;  
        if ($provider === null || !method_exists($provider, 'parseResponse')) {  
            if ($player !== null) {  
                $player->sendMessage($this->plugin->getMessageManager()->getConfigurableMessage("provider_errors.provider_not_available", ["provider" => $providerName, "available" => implode(", ", array_keys($this->providers))]));  
            }  
            $this->plugin->getLogger()->error("Provider '{$providerName}' not found or doesn't have parseResponse method. Available: " . implode(", ", array_keys($this->providers)));  
            return;  
        }  
          
        // Parse the response using the provider  
        $httpResponse = $result['response'] ?? '';  
        $parsedResponse = $provider->parseResponse($httpResponse, $result);  
          
        if (!$parsedResponse['success']) {
            // Provider failed to parse the response, try to find another provider
            $this->plugin->getLogger()->warning("Provider '{$providerName}' failed to parse response: " . ($parsedResponse['error'] ?? 'Unknown error'));
            
            // Get a list of alternative providers (excluding the one that just failed)
            $alternativeProviders = [];
            foreach ($this->providers as $name => $providerInstance) {
                if ($name !== $normalizedProviderName && $providerInstance->isConfigured() && method_exists($providerInstance, 'parseResponse')) {
                    $alternativeProviders[$name] = $providerInstance;
                }
            }
            
            // Try each alternative provider
            foreach ($alternativeProviders as $altName => $altProvider) {
                try {
                    $this->plugin->getLogger()->info("Attempting to parse response with alternative provider: {$altName}");
                    $altParsedResponse = $altProvider->parseResponse($httpResponse, $result);
                    
                    if ($altParsedResponse['success']) {
                        // Alternative provider successfully parsed the response
                        $this->plugin->getLogger()->info("Successfully parsed response with alternative provider: {$altName}");
                        $parsedResponse = $altParsedResponse;
                        $provider = $altProvider;
                        $providerName = $altName;
                        
                        // Break out of the loop since we found a working provider
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->plugin->getLogger()->warning("Alternative provider '{$altName}' also failed: " . $e->getMessage());
                    // Continue to the next alternative provider
                }
            }
            
            // If all providers failed, send error message and return
            if (!$parsedResponse['success']) {
                if ($player !== null) {  
                    $player->sendMessage($this->plugin->getMessageManager()->getConfigurableMessage("provider_errors.generic_error", ["error" => $parsedResponse['error']]));  
                }  
                  
                // Clear form context and complete request on error  
                $this->requestManager->clearFormContext($playerName);  
                $this->requestManager->completeRequest($playerName, $requestId);  
                return;
            }
        }  
          
        $aiResponse = $parsedResponse['content'];  
          
        // Save conversation  
        if ($player !== null) {  
            $this->plugin->getConversationManager()->addToConversation($playerName, $query, $aiResponse);  
        }  
          
        // Check if this is a form request and handle accordingly  
        $formContext = $this->requestManager->getFormContext($playerName);  
        if ($formContext !== null && $player !== null) {  
            // Route based on context type  
            $contextType = $formContext['type'] ?? 'unknown';  
              
            if ($contextType === 'direct_command') {  
                // Handle direct command response  
                $this->handleDirectCommandResponse($player, $query, $aiResponse);  
            } else {  
                // Handle form response (chat_form, crafting_form, etc.)  
                $this->handleFormResponse($player, $formContext, $query, $aiResponse);  
            }  
        } else if ($player !== null) {  
            // Fallback: Handle as direct response  
            $this->handleDirectCommandResponse($player, $query, $aiResponse);  
        }  
          
        // Complete request tracking  
        $this->requestManager->completeRequest($playerName, $requestId);  
    }  
      
    /**  
     * Handle form-specific response logic  
     *   
     * @param Player $player  
     * @param array $formContext  
     * @param string $query  
     * @param string $aiResponse  
     */  
    private function handleFormResponse(Player $player, array $formContext, string $query, string $aiResponse): void {  
        $tokenManager = $formContext['tokenManager'];  
        $playerName = $player->getName();  
          
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
        $this->requestManager->clearFormContext($playerName);  
          
        // Store ready response for "View Response" button functionality
        $this->requestManager->setReadyResponse($playerName, $query, $aiResponse);
        
        // Get configuration for auto-show behavior
        $config = $this->plugin->getConfig();
        $autoShowEnabled = $config->getNested("advanced.view_response_button.enabled", true);
        $autoShowDelay = (int) $config->getNested("advanced.view_response_button.auto_show_delay", 3);
        $showToastNotification = $config->getNested("advanced.view_response_button.show_toast_notification", true);
        
        // Send new toast notification if enabled
        if ($showToastNotification) {
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "view_response_ready");
        }
        
        // Show response form with configurable delay, but only if auto-show is enabled
        if ($autoShowEnabled) {
            $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(  
                function() use ($player, $query, $aiResponse): void {  
                    if ($player->isOnline()) {
                        // Check if the response is still available (player might have viewed it already)
                        if ($this->requestManager->hasReadyResponse($player->getName())) {
                            $form = new \Renz\AIAssistant\forms\ResponseForm($this->plugin);  
                            $form->sendTo($player, $query, $aiResponse);
                            // Clear the ready response since we're showing it now
                            $this->requestManager->clearReadyResponse($player->getName());
                        }
                    }  
                }  
            ), $autoShowDelay * 20); // Convert to ticks
        }  
    }  
  
    /**  
     * Handle direct command response from async AI providers  
     *   
     * @param Player $player  
     * @param string $query  
     * @param string $aiResponse  
     */  
    private function handleDirectCommandResponse(Player $player, string $query, string $aiResponse): void {  
        // Get the AI command instance and delegate to its response handler  
        $aiCommand = $this->plugin->getServer()->getCommandMap()->getCommand("ai");  
          
        if ($aiCommand instanceof \Renz\AIAssistant\commands\AICommand) {  
            // Get token manager from form context if available  
            $formContext = $this->requestManager->getFormContext($player->getName());  
            $tokenManager = $formContext['tokenManager'] ?? $this->plugin->getTokenManager();  
              
            // Clear any form context since this is a direct command  
            $this->requestManager->clearFormContext($player->getName());  
              
            // Delegate to the command's response handler  
            $aiCommand->handleDirectQueryResponse($player, $query, $aiResponse, $tokenManager);  
        } else {  
            // Fallback: send response directly to player  
            $formattedResponse = $this->plugin->getMessageManager()->formatAIResponse($aiResponse);  
            $player->sendMessage($this->plugin->getMessageManager()->formatAIResponse($formattedResponse));  
              
            // Clear form context  
            $this->requestManager->clearFormContext($player->getName());  
        }  
    }  
  
    /**  
     * Get a fallback response when no providers are available  
     *   
     * @param string $query  
     * @return string  
     */  
    private function getFallbackResponse(string $query): string {  
        // Simple keyword-based fallback responses  
        $query = strtolower($query);  
          
        if (strpos($query, "craft") !== false || strpos($query, "make") !== false || strpos($query, "recipe") !== false) {  
            return MinecraftTextFormatter::COLOR_RED . "I'm sorry, I don't have access to crafting recipes at the moment. Please check the Minecraft Wiki for crafting information.";  
        }  
          
        if (strpos($query, "build") !== false || strpos($query, "house") !== false || strpos($query, "material") !== false) {  
            return MinecraftTextFormatter::COLOR_RED . "I'm sorry, I can't calculate building materials at the moment. As a general rule, a small house might need around 200-500 blocks depending on the design.";  
        }  
          
        if (strpos($query, "server") !== false || strpos($query, "tps") !== false || strpos($query, "stat") !== false) {  
            $server = $this->plugin->getServer();  
            $tps = $server->getTicksPerSecond();  
            $players = count($server->getOnlinePlayers());  
            $maxPlayers = $server->getMaxPlayers();  
              
            return MinecraftTextFormatter::formatTitle("Server Statistics") . "\n\n" .  
                   MinecraftTextFormatter::COLOR_YELLOW . "TPS: " . MinecraftTextFormatter::COLOR_WHITE . "{$tps}\n" .  
                   MinecraftTextFormatter::COLOR_YELLOW . "Players: " . MinecraftTextFormatter::COLOR_WHITE . "{$players}/{$maxPlayers}";  
        }  
          
        // Default fallback response  
        return MinecraftTextFormatter::COLOR_RED . "I'm sorry, I can't process your request at the moment. Please try again later or contact a server administrator.";  
    }  
  
    /**  
     * Check if a player is within rate limits  
     *   
     * @param string $playerName  
     * @return bool  
     */  
    private function checkRateLimit(string $playerName): bool {  
        $config = $this->plugin->getConfig();  
        $rateLimitEnabled = $config->getNested("advanced.rate_limit.enabled", true);  
          
        if (!$rateLimitEnabled) {  
            return true;  
        }  
          
        $maxRequests = $config->getNested("advanced.rate_limit.max_requests", 10);  
        $timeWindow = $config->getNested("advanced.rate_limit.time_window", 60);  
        $currentTime = time();  
          
        // Initialize rate limit data for this player if it doesn't exist  
        if (!isset($this->rateLimits[$playerName])) {  
            $this->rateLimits[$playerName] = [  
                "requests" => 0,  
                "reset_time" => $currentTime + $timeWindow  
            ];  
        }  
          
        // Check if we need to reset the rate limit  
        if ($currentTime > $this->rateLimits[$playerName]["reset_time"]) {  
            $this->rateLimits[$playerName] = [  
                "requests" => 0,  
                "reset_time" => $currentTime
            ];  
        }  
          
        // Check if the player has exceeded the rate limit  
        if ($this->rateLimits[$playerName]["requests"] >= $maxRequests) {  
            return false;  
        }  
          
        // Increment the request count  
        $this->rateLimits[$playerName]["requests"]++;  
        return true;  
    }  
  
    /**  
     * Get all available provider names  
     *   
     * @return array  
     */  
    public function getAvailableProviders(): array {  
        return array_keys($this->providers);  
    }  
  
    /**  
     * Check if a provider is available (case-insensitive)
     *   
     * @param string $providerName  
     * @return bool  
     */  
    public function isProviderAvailable(string $providerName): bool {  
        $normalizedName = $this->normalizeProviderName($providerName);
        return $normalizedName !== null && isset($this->providers[$normalizedName]);
    }  
  
    /**  
     * Get the default provider name  
     *   
     * @return string  
     */  
    public function getDefaultProvider(): string {  
        return $this->defaultProvider;  
    }  
  
    /**  
     * Set the default provider (case-insensitive)
     *   
     * @param string $providerName  
     * @return bool  
     */  
    public function setDefaultProvider(string $providerName): bool {  
        $normalizedName = $this->normalizeProviderName($providerName);
        if ($normalizedName === null || !isset($this->providers[$normalizedName])) {  
            return false;  
        }  
          
        $this->defaultProvider = $normalizedName;  
        $config = $this->plugin->getConfig();  
        $config->setNested("api_providers.default_provider", $normalizedName);  
        $config->save();  
          
        return true;  
    }  
      
    /**  
     * Get a specific provider instance (case-insensitive)
     *   
     * @param string $providerName  
     * @return AIProvider|null  
     */  
    public function getProvider(string $providerName): ?AIProvider {  
        $normalizedName = $this->normalizeProviderName($providerName);
        return $normalizedName !== null ? ($this->providers[$normalizedName] ?? null) : null;
    }  
      
    /**  
     * Reload all providers (useful after configuration changes)  
     */  
    public function reloadProviders(): void {  
        $this->loadProviders();  
        $this->plugin->getLogger()->info("AI providers reloaded successfully.");  
    }  
      
    /**  
     * Get the request manager  
     *   
     * @return RequestManager  
     */  
    public function getRequestManager(): RequestManager {  
        return $this->requestManager;  
    }  
      
    /**  
     * Cancel all active requests for a player  
     *   
     * @param string $playerName  
     * @return bool  
     */  
    public function cancelPlayerRequests(string $playerName): bool {  
        return $this->requestManager->cancelRequest($playerName);  
    }

    /**
     * Get all provider name variations for debugging
     * 
     * @return array
     */
    public function getProviderNameVariations(): array {
        return $this->providerNameMap;
    }

    /**
     * Add custom provider name alias
     * 
     * @param string $alias
     * @param string $actualProviderName
     * @return bool
     */
    public function addProviderAlias(string $alias, string $actualProviderName): bool {
        if (!isset($this->providers[$actualProviderName])) {
            return false;
        }
        
        $this->providerNameMap[strtolower($alias)] = $actualProviderName;
        return true;
    }

    /**
     * Remove provider name alias
     * 
     * @param string $alias
     * @return bool
     */
    public function removeProviderAlias(string $alias): bool {
        $lowerAlias = strtolower($alias);
        if (isset($this->providerNameMap[$lowerAlias])) {
            unset($this->providerNameMap[$lowerAlias]);
            return true;
        }
        return false;
    }

    /**
     * Get provider statistics
     * 
     * @return array
     */
    public function getProviderStats(): array {
        $stats = [];
        foreach ($this->providers as $name => $provider) {
            $stats[$name] = [
                'name' => $name,
                'configured' => $provider->isConfigured(),
                'class' => get_class($provider),
                'aliases' => array_keys(array_filter($this->providerNameMap, function($v) use ($name) {
                    return $v === $name;
                }))
            ];
        }
        return $stats;
    }
      
}
