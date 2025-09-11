<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;

class ServerInfoForm {
    /** @var Main */
    private Main $plugin;

    /**
     * ServerInfoForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the server info form to a player
     * 
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            // Return to main form
            $form = new MainForm($this->plugin);
            $form->sendTo($player);
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("server_info_form.title", "Server Information");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Set form title
        $form->setTitle($title);
        
        // Get server info
        $serverInfo = $this->getServerInfo($player);
        
        // Set form content
        $form->setContent($serverInfo);
        
        // Add back button from config
        $backText = $this->plugin->getFormSetting("server_info_form.buttons.back.text", "Back");
        $backColor = $this->plugin->getFormSetting("server_info_form.buttons.back.color", "&7");
        $backTexture = $this->plugin->getFormSetting("server_info_form.buttons.back.texture", "textures/ui/arrow_left");
        
        $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
        
        $form->sendToPlayer($player);
    }
    
    /**
     * Get formatted server information
     * 
     * @param Player $player
     * @return string
     */
    private function getServerInfo(Player $player): string {
        $serverInfoProvider = $this->plugin->getServerInfoProvider();
        $serverFeatureManager = $this->plugin->getServerFeatureManager();
        
        // Get section titles from config
        $generalTitle = $this->plugin->getFormSetting("server_info_form.sections.general.title", "General Information");
        $playersTitle = $this->plugin->getFormSetting("server_info_form.sections.players.title", "Player Information");
        $performanceTitle = $this->plugin->getFormSetting("server_info_form.sections.performance.title", "Server Performance");
        $rulesTitle = $this->plugin->getFormSetting("server_info_form.sections.rules.title", "Server Rules");
        
        // Format section titles
        $headingFormat = $this->plugin->getFormSetting("general.text_formatting.heading", "&l&6");
        $generalTitle = $this->plugin->formatFormText($headingFormat . $generalTitle);
        $playersTitle = $this->plugin->formatFormText($headingFormat . $playersTitle);
        $performanceTitle = $this->plugin->formatFormText($headingFormat . $performanceTitle);
        $rulesTitle = $this->plugin->formatFormText($headingFormat . $rulesTitle);
        
        // Format content
        $highlightFormat = $this->plugin->getFormSetting("general.text_formatting.highlight", "&e");
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&f");
        
        // Build server info content
        $info = "";
        
        // General information
        $info .= $generalTitle . "\n";
        $info .= TextFormat::colorize($highlightFormat . "Server Name: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getServerName()) . "\n";
        $info .= TextFormat::colorize($highlightFormat . "Description: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getServerDescription()) . "\n";
        $info .= TextFormat::colorize($highlightFormat . "MOTD: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getMotd()) . "\n";
        $info .= TextFormat::colorize($highlightFormat . "Server Software: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getServerSoftware()) . "\n";
        $info .= TextFormat::colorize($highlightFormat . "Minecraft Version: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getMinecraftVersion()) . "\n";
        $info .= TextFormat::colorize($highlightFormat . "Owner: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getServerOwner()) . "\n\n";
        
        // Player information
        $info .= $playersTitle . "\n";
        $info .= TextFormat::colorize($highlightFormat . "Online Players: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getOnlinePlayerCount() . "/" . $serverInfoProvider->getMaxPlayerCount()) . "\n";
        
        // Show player coordinates if enabled
        if ($this->plugin->getConfig()->getNested("server_info.show_coordinates", true)) {
            $pos = $player->getPosition();
            $world = $player->getWorld()->getFolderName();
            $info .= TextFormat::colorize($highlightFormat . "Your Position: ") . TextFormat::colorize($contentFormat . "X: " . floor($pos->getX()) . ", Y: " . floor($pos->getY()) . ", Z: " . floor($pos->getZ()) . " in " . $world) . "\n";
        }
        
        $info .= "\n";
        
        // Server performance
        if ($this->plugin->getConfig()->getNested("server_info.show_statistics", true)) {
            $info .= $performanceTitle . "\n";
            $info .= TextFormat::colorize($highlightFormat . "TPS: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getServerTPS()) . "\n";
            $info .= TextFormat::colorize($highlightFormat . "Memory Usage: ") . TextFormat::colorize($contentFormat . $serverInfoProvider->getMemoryUsage()) . "\n\n";
        }
        
        // Server rules
        $rules = $serverInfoProvider->getServerRules();
        if (!empty($rules)) {
            $info .= $rulesTitle . "\n";
            foreach ($rules as $index => $rule) {
                $info .= TextFormat::colorize($contentFormat . ($index + 1) . ". " . $rule) . "\n";
            }
        }
        
        return $info;
    }
}