<?php

declare(strict_types=1);

namespace Renz\AIAssistant;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\scheduler\TaskScheduler;

use Renz\AIAssistant\commands\AICommand;
use Renz\AIAssistant\providers\AIProviderManager;
use Renz\AIAssistant\tasks\TipBroadcastTask;
use Renz\AIAssistant\utils\MessageManager;
use Renz\AIAssistant\utils\ConversationManager;
use Renz\AIAssistant\utils\ServerInfoProvider;
use Renz\AIAssistant\utils\ServerFeatureManager;
use Renz\AIAssistant\utils\TokenManager;
use Renz\AIAssistant\utils\RequestManager;
use Renz\AIAssistant\economy\EconomyManager;
use Renz\AIAssistant\forms\ResponseForm;

class Main extends PluginBase implements Listener {
    /** @var Config */
    private Config $config;
    
    /** @var Config */
    private Config $formsConfig;
    
    /** @var Config */
    private Config $serverFeaturesConfig;
    
    /** @var AIProviderManager */
    private AIProviderManager $providerManager;
    
    /** @var MessageManager */
    private MessageManager $messageManager;
    
    /** @var ConversationManager */
    private ConversationManager $conversationManager;
    
    /** @var ServerInfoProvider */
    private ServerInfoProvider $serverInfoProvider;
    
    /** @var ServerFeatureManager */
    private ServerFeatureManager $serverFeatureManager;
    
    /** @var TokenManager */
    private TokenManager $tokenManager;
    
    /** @var EconomyManager */
    private EconomyManager $economyManager;
    
    /** @var RequestManager */
    private RequestManager $requestManager;
    
    /** @var array */
    private array $playerData = [];
    
    /** @var bool */
    private bool $debug = false;
    
    /** @var string */
    public string $cainfo_path; // CA certificate path for SSL
    
    /** @var resource */
    private mixed $_cainfo_resource; // Stores CA cert during runtime

    /**
     * Called when the plugin is loaded
     */
    protected function onLoad(): void {
        // Save default resources
        $this->saveResource("config.yml");
        $this->saveResource("forms.yml");
        $this->saveResource("fiturserver.yml");
        $this->saveResource("cacert.pem");
        
        // Initialize configs
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->formsConfig = new Config($this->getDataFolder() . "forms.yml", Config::YAML);
        $this->serverFeaturesConfig = new Config($this->getDataFolder() . "fiturserver.yml", Config::YAML);
        
        // Set debug mode
        $this->debug = $this->config->getNested("advanced.debug", false);
        
        // Set up CA certificate for SSL connections
        $cainfo = $this->getDataFolder() . "cacert.pem";
        $this->cainfo_path = $cainfo;
        if (file_exists($cainfo)) {
            $this->_cainfo_resource = fopen($cainfo, "r");
            if ($this->_cainfo_resource) {
                if ($this->debug) {
                    $this->getLogger()->debug("Loaded CA certificate for SSL connections");
                }
            } else {
                $this->getLogger()->warning("Failed to open CA certificate file");
            }
        } else {
            $this->getLogger()->warning("CA certificate file not found");
        }
        
        // Load FormAPI compatibility layer - must be included before any form usage
        require_once __DIR__ . "/libs/FormAPI/FormAPI.php";
        
        if ($this->debug) {
            $this->getLogger()->debug("AI Assistant plugin is loading...");
        }
    }

    /**
     * Called when the plugin is enabled
     */
    protected function onEnable(): void {
        // FormAPI is now vendored - no external dependency needed
        
        // Initialize components
        $this->initializeComponents();
        
        // Register events
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        // Register commands with proper fallback prefix
        $this->getServer()->getCommandMap()->register("pmmp-ai", new AICommand($this));
        
        // Schedule tasks
        $this->scheduleTasks();
        
        // Log startup only if debug enabled
        if ($this->debug) {
            $this->getLogger()->debug("PMMP-AI Assistant enabled!");
            $this->getLogger()->debug("Using " . $this->providerManager->getDefaultProvider() . " as the default AI provider");
        }
    }

    /**
     * Called when the plugin is disabled
     */
    protected function onDisable(): void {
        // Save any pending data
        $this->conversationManager->saveConversations();
        
        // Clean up SSL certificate resource
        if (isset($this->_cainfo_resource)) {
            fclose($this->_cainfo_resource);
            unset($this->_cainfo_resource);
        }
        
        if ($this->debug) {
            $this->getLogger()->debug("AI Assistant plugin has been disabled!");
        }
    }

    /**
     * Initialize all plugin components
     */
    private function initializeComponents(): void {
        // Initialize message manager
        $this->messageManager = new MessageManager($this);
        
        // Initialize conversation manager
        $this->conversationManager = new ConversationManager($this);
        
        // Initialize server info provider
        $this->serverInfoProvider = new ServerInfoProvider($this);
        
        // Initialize server feature manager
        $this->serverFeatureManager = new ServerFeatureManager($this);
        
        // Initialize request manager
        $this->requestManager = new RequestManager($this);
        
        // Initialize provider manager
        $this->providerManager = new AIProviderManager($this);
        
        // Initialize token manager
        $this->tokenManager = new TokenManager($this);
        
        // Initialize economy manager
        $this->economyManager = new EconomyManager($this);
        
        // Log initialization if debug mode is enabled
        if ($this->debug) {
            $this->getLogger()->debug("All components initialized successfully");
        }
    }

    /**
     * Schedule plugin tasks
     */
    private function scheduleTasks(): void {
        // Schedule tip broadcast task if enabled
        $tipInterval = $this->config->getNested("messages.tips_interval", 900);
        if ($tipInterval > 0 && $this->config->getNested("messages.tips_enabled", true)) {
            $this->getScheduler()->scheduleRepeatingTask(new TipBroadcastTask($this), $tipInterval * 20);
        }
        
        // Schedule cleanup task for cancelled requests
        $this->getScheduler()->scheduleRepeatingTask(new \pocketmine\scheduler\ClosureTask(
            function(): void {
                $this->requestManager->cleanupCancelledRequests();
            }
        ), 1200); // Run every 1200 ticks (1 minute)
    }

    /**
     * Handle player join event
     * 
     * @param PlayerJoinEvent $event
     * @priority NORMAL
     * @ignoreCancelled true
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        // Load player conversation history
        $this->conversationManager->loadSessionsMetadata($playerName);
        
        // Send welcome message if enabled
        $welcomeMessage = $this->messageManager->getWelcomeMessage();
        if (!empty($welcomeMessage)) {
            $player->sendMessage($welcomeMessage);
        }
        
        if ($this->debug) {
            $this->getLogger()->debug("Player {$playerName} joined, conversation history loaded");
        }
    }

    /**
     * Handle player quit event
     * 
     * @param PlayerQuitEvent $event
     * @priority NORMAL
     * @ignoreCancelled true
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $playerName = $event->getPlayer()->getName();
        
        // Save player conversation history
        $currentSessionId = $this->conversationManager->getCurrentSessionId($playerName);
        if ($currentSessionId !== null) {
            $this->conversationManager->saveConversation($playerName, $currentSessionId);
        }
        
        // Cancel any active AI requests
        $this->providerManager->cancelPlayerRequests($playerName);
        
        if ($this->debug) {
            $this->getLogger()->debug("Player {$playerName} quit, conversation history saved");
        }
    }

    /**
     * Get the plugin configuration
     * 
     * @return Config
     */
    public function getPluginConfig(): Config {
        return $this->config;
    }

    /**
     * Get the forms configuration
     * 
     * @return Config
     */
    public function getFormsConfig(): Config {
        return $this->formsConfig;
    }

    /**
     * Get the server features configuration
     * 
     * @return Config
     */
    public function getServerFeaturesConfig(): Config {
        return $this->serverFeaturesConfig;
    }

    /**
     * Get the AI provider manager
     * 
     * @return AIProviderManager
     */
    public function getProviderManager(): AIProviderManager {
        return $this->providerManager;
    }

    /**
     * Get the conversation manager
     * 
     * @return ConversationManager
     */
    public function getConversationManager(): ConversationManager {
        return $this->conversationManager;
    }

    /**
     * Get the message manager
     * 
     * @return MessageManager
     */
    public function getMessageManager(): MessageManager {
        return $this->messageManager;
    }

    /**
     * Get the server info provider
     * 
     * @return ServerInfoProvider
     */
    public function getServerInfoProvider(): ServerInfoProvider {
        return $this->serverInfoProvider;
    }

    /**
     * Get the server feature manager
     * 
     * @return ServerFeatureManager
     */
    public function getServerFeatureManager(): ServerFeatureManager {
        return $this->serverFeatureManager;
    }

    /**
     * Get the token manager
     * 
     * @return TokenManager
     */
    public function getTokenManager(): TokenManager {
        return $this->tokenManager;
    }

    /**
     * Get the economy manager
     * 
     * @return EconomyManager
     */
    public function getEconomyManager(): EconomyManager {
        return $this->economyManager;
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
     * Check if debug mode is enabled
     * 
     * @return bool
     */
    public function isDebugEnabled(): bool {
        return $this->debug;
    }

    /**
     * Get the CA certificate resource
     * 
     * @return resource|null
     */
    public function getCAInfoResource() {
        return $this->_cainfo_resource;
    }
    
    /**
     * Get a form setting from the forms config
     * 
     * @param string $key The setting key in dot notation
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed
     */
    public function getFormSetting(string $key, $default = null) {
        return $this->formsConfig->getNested($key, $default);
    }
    
    /**
     * Format form text with color codes
     * 
     * @param string $text
     * @return string
     */
    public function formatFormText(string $text): string {
        // Replace color placeholders with actual color codes
        $buttonColors = $this->formsConfig->getNested("general.button_colors", []);
        foreach ($buttonColors as $colorName => $colorCode) {
            $text = str_replace("{{$colorName}}", (string)$colorCode, $text);
        }
        
        return TextFormat::colorize($text);
    }
    
    /**
     * Check if the global view response button should be shown
     * 
     * @param Player $player
     * @return bool
     */
    public function shouldShowGlobalViewResponseButton(\pocketmine\player\Player $player): bool {
        $requestManager = $this->getRequestManager();
        $hasReadyResponse = $requestManager->hasReadyResponse($player->getName());
        $viewResponseEnabled = $this->getConfig()->getNested("advanced.view_response_button.enabled", true);
        return $hasReadyResponse && $viewResponseEnabled;
    }
    
    /**
     * Handle global view response button click
     * 
     * @param Player $player
     */
    public function handleGlobalViewResponse(\pocketmine\player\Player $player): void {
        $requestManager = $this->getRequestManager();
        $readyResponse = $requestManager->consumeReadyResponse($player->getName());
        
        if ($readyResponse !== null) {
            $question = $readyResponse['question'];
            $response = $readyResponse['response'];
            
            // Show the response in a ResponseForm
            $responseForm = new ResponseForm($this);
            $responseForm->sendTo($player, $question, $response);
            
            // Send toast notification
            $this->getMessageManager()->sendSpecificToastNotification($player, "response_ready");
        } else {
            // No ready response found - this shouldn't happen but handle gracefully
            $this->getMessageManager()->sendToastNotification(
                $player,
                "error",
                $this->getMessageManager()->getConfigurableMessage("toasts.defaults.title"),
                "No response available to view."
            );
        }
    }
}