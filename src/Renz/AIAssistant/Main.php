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

class Main extends PluginBase implements Listener {
    /** @var Config */
    private Config $config;
    
    /** @var Config */
    private Config $formsConfig;
    
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

    /**
     * Called when the plugin is loaded
     */
    protected function onLoad(): void {
        $this->getLogger()->info(TextFormat::WHITE . "AI Assistant plugin is loading...");
    }

    /**
     * Called when the plugin is enabled
     */
    protected function onEnable(): void {
        // Check for FormAPI dependency
        if (!$this->getServer()->getPluginManager()->getPlugin("FormAPI")) {
            $this->getLogger()->error("FormAPI not found! Please install FormAPI by jojoe77777.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        
        // Create default config if it doesn't exist
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        
        // Save default forms config if it doesn't exist
        $this->saveResource("forms.yml");
        $this->formsConfig = new Config($this->getDataFolder() . "forms.yml", Config::YAML);
        
        // Ensure forms.yml exists and is properly loaded
        if (!file_exists($this->getDataFolder() . "forms.yml")) {
            $this->getLogger()->warning("forms.yml not found, creating default configuration");
            $this->saveResource("forms.yml", true);
        }
        
        // Load debug mode setting
        $this->debug = (bool) $this->config->getNested("advanced.debug", false);
        
        // Initialize components
        $this->initializeComponents();
        
        // Register events
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        // Register commands
        $this->getServer()->getCommandMap()->register("aiassistant", new AICommand($this));
        
        // Schedule tasks
        $this->scheduleTasks();
        
        $this->getLogger()->info(TextFormat::GREEN . "AI Assistant plugin has been enabled!");
    }

    /**
     * Called when the plugin is disabled
     */
    protected function onDisable(): void {
        // Save any pending data
        $this->conversationManager->saveAllConversations();
        
        $this->getLogger()->info(TextFormat::RED . "AI Assistant plugin has been disabled!");
    }

    /**
     * Initialize all plugin components
     */
    private function initializeComponents(): void {
        // Initialize request manager
        $this->requestManager = new RequestManager($this);
        
        // Initialize economy manager
        $this->economyManager = new EconomyManager($this);
        
        // Initialize token manager
        $this->tokenManager = new TokenManager($this);
        
        // Initialize provider manager
        $this->providerManager = new AIProviderManager($this);
        
        // Initialize message manager
        $this->messageManager = new MessageManager($this);
        
        // Initialize conversation manager
        $this->conversationManager = new ConversationManager($this);
        
        // Initialize server info provider
        $this->serverInfoProvider = new ServerInfoProvider($this);
        
        // Initialize server feature manager
        $this->serverFeatureManager = new ServerFeatureManager($this);
        
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
        if ($this->config->getNested("messages.tips_enabled", true)) {
            $interval = (int) $this->config->getNested("messages.tips_interval", 900);
            $this->getScheduler()->scheduleRepeatingTask(new TipBroadcastTask($this), $interval * 20); // Convert to ticks
            
            if ($this->debug) {
                $this->getLogger()->debug("Scheduled tip broadcast task with interval: {$interval} seconds");
            }
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
     * Get the AI Provider Manager instance
     * 
     * @return AIProviderManager
     */
    public function getProviderManager(): AIProviderManager {
        return $this->providerManager;
    }

    /**
     * Get the Message Manager instance
     * 
     * @return MessageManager
     */
    public function getMessageManager(): MessageManager {
        return $this->messageManager;
    }

    /**
     * Get the Conversation Manager instance
     * 
     * @return ConversationManager
     */
    public function getConversationManager(): ConversationManager {
        return $this->conversationManager;
    }

    /**
     * Get the Server Info Provider instance
     * 
     * @return ServerInfoProvider
     */
    public function getServerInfoProvider(): ServerInfoProvider {
        return $this->serverInfoProvider;
    }

    /**
     * Get the Server Feature Manager instance
     * 
     * @return ServerFeatureManager
     */
    public function getServerFeatureManager(): ServerFeatureManager {
        return $this->serverFeatureManager;
    }

    /**
     * Get the Token Manager instance
     * 
     * @return TokenManager
     */
    public function getTokenManager(): TokenManager {
        return $this->tokenManager;
    }

    /**
     * Get the Economy Manager instance
     * 
     * @return EconomyManager
     */
    public function getEconomyManager(): EconomyManager {
        return $this->economyManager;
    }
    
    /**
     * Get the Request Manager instance
     * 
     * @return RequestManager
     */
    public function getRequestManager(): RequestManager {
        return $this->requestManager;
    }

    /**
     * Get the Forms Config instance
     * 
     * @return Config
     */
    public function getFormsConfig(): Config {
        return $this->formsConfig;
    }
    
    /**
     * Save the Forms Config
     * 
     * @return void
     */
    public function saveFormsConfig(): void {
        if ($this->formsConfig instanceof Config) {
            $this->formsConfig->save();
            if ($this->debug) {
                $this->getLogger()->debug("Forms configuration saved successfully");
            }
        } else {
            $this->getLogger()->warning("Failed to save forms configuration: Config instance not found");
        }
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
     * Save the plugin configuration
     * 
     * @return void
     */
    public function saveConfig(): void {
        parent::saveConfig();
        
        if ($this->debug) {
            $this->getLogger()->debug("Plugin configuration saved successfully");
        }
    }
    
    /**
     * Get a form setting from the forms configuration
     * 
     * @param string $path The path to the setting
     * @param mixed $default The default value if the setting doesn't exist
     * @return mixed The setting value
     */
    public function getFormSetting(string $path, $default = null) {
        return $this->formsConfig->getNested($path, $default);
    }
    
    /**
     * Process text formatting using the forms configuration
     * 
     * @param string $text The text to format
     * @return string The formatted text
     */
    public function formatFormText(string $text): string {
        // Replace color placeholders with actual color codes
        $buttonColors = $this->formsConfig->getNested("general.button_colors", []);
        foreach ($buttonColors as $colorName => $colorCode) {
            $text = str_replace("{{$colorName}}", $colorCode, $text);
        }
        
        return TextFormat::colorize($text);
    }
}