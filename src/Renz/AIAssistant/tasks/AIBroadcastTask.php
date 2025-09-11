<?php

declare(strict_types=1);

namespace Renz\AIAssistant\tasks;

use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;

class AIBroadcastTask extends Task {
    /** @var Main */
    private Main $plugin;
    
    /** @var array */
    private array $tips = [];
    
    /** @var string */
    private string $prefix = "";

    /**
     * AIBroadcastTask constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadTipsFromConfig();
    }

    /**
     * Load tips from the configuration file
     */
    private function loadTipsFromConfig(): void {
        $config = $this->plugin->getConfig();
        $this->tips = $config->getNested("messages.tips", [
            "Type /ai to open the AI Assistant interface.",
            "Need help with crafting? Ask the AI Assistant!",
            "Building a house? Use the AI Assistant's building calculator!",
            "Want to know server stats? Check the AI Assistant!",
            "The AI Assistant can help you with many Minecraft-related questions.",
            "Lost? Ask the AI Assistant for help with coordinates and navigation.",
            "Need to calculate materials for a build? The AI Assistant can help!",
            "Wondering what time it is in-game? Ask the AI Assistant!"
        ]);
        
        $this->prefix = $config->getNested("messages.ai_prefix", "[AI Assistant] ");
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Loaded " . count($this->tips) . " tips from configuration");
        }
    }

    /**
     * Execute the task
     */
    public function onRun(): void {
        // If no tips are available, don't broadcast anything
        if (empty($this->tips)) {
            return;
        }
        
        // Get a random tip
        $tip = $this->tips[array_rand($this->tips)];
        
        // Broadcast the tip to all players
        $this->plugin->getServer()->broadcastMessage(
            TextFormat::colorize("&b" . $this->prefix . "&e" . $tip)
        );
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Broadcasted tip: " . $tip);
        }
    }
}