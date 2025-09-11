<?php

declare(strict_types=1);

namespace Renz\AIAssistant\tasks;

use pocketmine\scheduler\Task;
use Renz\AIAssistant\Main;

class TipBroadcastTask extends Task {
    /** @var Main */
    private Main $plugin;

    /**
     * TipBroadcastTask constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Execute the task
     */
    public function onRun(): void {
        // Get a random tip
        $tip = $this->plugin->getMessageManager()->getRandomTip();
        
        // Broadcast the tip to all players
        $this->plugin->getServer()->broadcastMessage($tip);
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Broadcasted tip: " . $tip);
        }
    }
}