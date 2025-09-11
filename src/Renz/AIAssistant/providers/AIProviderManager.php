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

    /**
     * AIProviderManager constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->cache = new ResponseCache($plugin);
        $this->requestManager = new RequestManager($plugin);
        $this->loadProviders();
    }

    /**
     * Load all configured AI providers
     */
    private function loadProviders(): void {
        $config = $this->plugin->getConfig();
        
        // Set default provider
        $this->defaultProvider = $config->getNested("api_providers.default_provider", "openai");
        
        // Clear existing providers
        $this->providers = [];
        
        // Initialize OpenAI provider if enabled
        if ($config->getNested("api_providers.openai.enabled", false)) {
            try {
                $this->providers["openai"] = new OpenAIProvider($this->plugin);
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
        $configuredDefaultProvider = $config->getNested("api_providers.default_provider", "openai");
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
        
        // Determine which provider to use
        $providerName = $provider ?? $this->defaultProvider;
        
        // If the specified provider doesn't exist, use the default
        if (!isset($this->providers[$providerName])) {
            if ($provider !== null) {
                $this->plugin->getLogger()->warning("Provider '{$providerName}' not found or not available, falling back to default provider '{$this->defaultProvider}'");
                if ($this->plugin->isDebugEnabled()) {
                    $this->plugin->getLogger()->debug("Available providers: " . implode(", ", array_keys($this->providers)));
                }
            }
            $providerName = $this->defaultProvider;
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
        
        try {
            // Get conversation history
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
                // Get relevant features based on the query
                $relevantFeatures = $this->plugin->getServerFeatureManager()->getRelevantFeatures($query);
                $serverFeatures = $this->plugin->getServerFeatureManager()->formatFeaturesForPrompt($relevantFeatures);
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
            
            // Process the query with the selected provider
            $provider = $this->providers[$providerName];
            $response = $provider->generateResponse($query, $conversationHistory, $systemPrompt, $serverInfo, $serverFeatures);
            
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
            $this->plugin->getLogger()->error("Error processing AI query: " . $e->getMessage());
            
            // Mark the request as complete
            $this->requestManager->completeRequest($playerName, $requestId);
            
            return TextFormat::RED . "An error occurred while processing your request. Please try again later.";
        }
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
                "reset_time" => $currentTime + $timeWindow
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
     * Check if a provider is available
     * 
     * @param string $providerName
     * @return bool
     */
    public function isProviderAvailable(string $providerName): bool {
        return isset($this->providers[$providerName]);
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
     * Set the default provider
     * 
     * @param string $providerName
     * @return bool
     */
    public function setDefaultProvider(string $providerName): bool {
        if (!isset($this->providers[$providerName])) {
            return false;
        }
        
        $this->defaultProvider = $providerName;
        $config = $this->plugin->getConfig();
        $config->setNested("api_providers.default_provider", $providerName);
        $config->save();
        
        return true;
    }
    
    /**
     * Get a specific provider instance
     * 
     * @param string $providerName
     * @return AIProvider|null
     */
    public function getProvider(string $providerName): ?AIProvider {
        return $this->providers[$providerName] ?? null;
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
     * Reload all providers from config
     * This is useful when the config has been updated
     */
    public function reloadProviders(): void {
        $this->loadProviders();
    }
}