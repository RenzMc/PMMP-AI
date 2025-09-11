<?php

declare(strict_types=1);

namespace Renz\AIAssistant\utils;

use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;

class MessageManager {
    /** @var Main */
    private Main $plugin;
    
    /** @var string */
    private string $aiPrefix;
    
    /** @var string */
    private string $welcomeMessage;
    
    /** @var array */
    private array $tips = [];

    /**
     * MessageManager constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadMessages();
    }

    /**
     * Load messages from config
     */
    private function loadMessages(): void {
        $config = $this->plugin->getConfig();
        
        // Load AI prefix
        $this->aiPrefix = TextFormat::colorize($config->getNested("messages.ai_prefix", "&b[AI Assistant] &f"));
        
        // Load welcome message
        $welcomeMessage = $config->getNested("messages.welcome", "Welcome to the server! Type /ai to use the AI Assistant.");
        $this->welcomeMessage = TextFormat::colorize($this->aiPrefix . $welcomeMessage);
        
        // Load tips
        $this->tips = $config->getNested("messages.tips", []);
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Loaded " . count($this->tips) . " tips");
        }
    }

    /**
     * Get the AI prefix
     * 
     * @return string
     */
    public function getAIPrefix(): string {
        return $this->aiPrefix;
    }

    /**
     * Get the welcome message
     * 
     * @return string
     */
    public function getWelcomeMessage(): string {
        return $this->welcomeMessage;
    }

    /**
     * Get a configurable message by key with optional replacements
     * 
     * @param string $messageKey Key in format "category.message_name" (e.g., "console.command_ingame_only")
     * @param array $replacements Optional replacements array (e.g., ["provider" => "openai"])
     * @return string
     */
    public function getConfigurableMessage(string $messageKey, array $replacements = []): string {
        $config = $this->plugin->getConfig();
        $message = $config->getNested("messages." . $messageKey, "Message not found: " . $messageKey);
        
        // Apply replacements
        foreach ($replacements as $key => $value) {
            $message = str_replace('{' . $key . '}', $value, $message);
        }
        
        return TextFormat::colorize($message);
    }
    
    /**
     * Send a configurable message to a player/sender
     * 
     * @param \pocketmine\command\CommandSender|\pocketmine\player\Player $sender
     * @param string $messageKey Key in format "category.message_name"
     * @param array $replacements Optional replacements array
     * @return void
     */
    public function sendConfigurableMessage($sender, string $messageKey, array $replacements = []): void {
        $message = $this->getConfigurableMessage($messageKey, $replacements);
        $sender->sendMessage($message);
    }

    /**
     * Get a random tip
     * 
     * @return string
     */
    public function getRandomTip(): string {
        if (empty($this->tips)) {
            return TextFormat::colorize($this->aiPrefix . "Type /ai to use the AI Assistant!");
        }
        
        $tip = $this->tips[array_rand($this->tips)];
        return TextFormat::colorize($this->aiPrefix . $tip);
    }

    /**
     * Format an AI response
     * 
     * @param string $response
     * @return string
     */
    public function formatAIResponse(string $response): string {
        return $this->aiPrefix . $response;
    }

    /**
     * Format an error message
     * 
     * @param string $message
     * @return string
     */
    public function formatErrorMessage(string $message): string {
        return TextFormat::colorize("&c" . $message);
    }

    /**
     * Format a success message
     * 
     * @param string $message
     * @return string
     */
    public function formatSuccessMessage(string $message): string {
        return TextFormat::colorize("&a" . $message);
    }

    /**
     * Format an info message
     * 
     * @param string $message
     * @return string
     */
    public function formatInfoMessage(string $message): string {
        return TextFormat::colorize("&e" . $message);
    }

    /**
     * Add a custom tip
     * 
     * @param string $tip
     */
    public function addTip(string $tip): void {
        $this->tips[] = $tip;
        
        $config = $this->plugin->getConfig();
        $config->setNested("messages.tips", $this->tips);
        $config->save();
    }

    /**
     * Remove a tip
     * 
     * @param int $index
     * @return bool
     */
    public function removeTip(int $index): bool {
        if (!isset($this->tips[$index])) {
            return false;
        }
        
        unset($this->tips[$index]);
        $this->tips = array_values($this->tips); // Reindex array
        
        $config = $this->plugin->getConfig();
        $config->setNested("messages.tips", $this->tips);
        $config->save();
        
        return true;
    }

    /**
     * Get all tips
     * 
     * @return array
     */
    public function getAllTips(): array {
        return $this->tips;
    }

    /**
     * Set the welcome message
     * 
     * @param string $message
     */
    public function setWelcomeMessage(string $message): void {
        $config = $this->plugin->getConfig();
        $config->setNested("messages.welcome", $message);
        $config->save();
        
        $this->welcomeMessage = TextFormat::colorize($this->aiPrefix . $message);
    }

    /**
     * Set the AI prefix
     * 
     * @param string $prefix
     */
    public function setAIPrefix(string $prefix): void {
        $config = $this->plugin->getConfig();
        $config->setNested("messages.ai_prefix", $prefix);
        $config->save();
        
        $this->aiPrefix = TextFormat::colorize($prefix);
        
        // Update welcome message with new prefix
        $welcomeMessage = $config->getNested("messages.welcome", "Welcome to the server! Type /ai to use the AI Assistant.");
        $this->welcomeMessage = TextFormat::colorize($this->aiPrefix . $welcomeMessage);
    }

    /**
     * Send a configurable toast notification to a player
     * 
     * @param Player $player
     * @param string $messageKey The toast message key (e.g., 'chat.welcome' for messages.toasts.chat.welcome_title/body)
     * @param string|null $customTitle Override title (optional)
     * @param string|null $customBody Override body (optional)
     */
    public function sendToastNotification(\pocketmine\player\Player $player, string $messageKey, ?string $customTitle = null, ?string $customBody = null): void {
        $config = $this->plugin->getConfig();
        
        // Check if notifications are enabled
        if (!$config->getNested("notifications.enabled", true)) {
            return;
        }
        
        // Get toast config from messages.toasts.*
        $defaultTitle = $config->getNested("messages.toasts.defaults.title", "§l§bNotification");
        $defaultBody = $config->getNested("messages.toasts.defaults.body", "Notification message");
        $title = $customTitle ?? $config->getNested("messages.toasts.{$messageKey}_title", $defaultTitle);
        $body = $customBody ?? $config->getNested("messages.toasts.{$messageKey}_body", $defaultBody);
        
        // Send the toast notification
        $player->sendToastNotification($title, $body);
    }
    
    /**
     * Send a specific toast notification type
     * 
     * @param Player $player
     * @param string $specificType The specific notification type (e.g., 'no_permission', 'no_tokens')
     * @param array $replacements Optional text replacements
     */
    public function sendSpecificToastNotification(\pocketmine\player\Player $player, string $specificType, array $replacements = []): void {
        $config = $this->plugin->getConfig();
        
        // Check if notifications are enabled
        if (!$config->getNested("notifications.enabled", true)) {
            return;
        }
        
        // Get notification config from messages.toasts.presets
        $presetTitle = $config->getNested("messages.toasts.presets.{$specificType}.title", "");
        $presetBody = $config->getNested("messages.toasts.presets.{$specificType}.body", "");
        
        // Use preset values if available, otherwise fallback to defaults
        $defaultTitle = $config->getNested("messages.toasts.defaults.title", "§l§bNotification");
        $defaultBody = $config->getNested("messages.toasts.defaults.body", "Notification message");
        $title = !empty($presetTitle) ? $presetTitle : $defaultTitle;
        $body = !empty($presetBody) ? $presetBody : $defaultBody;
        
        // Apply replacements
        foreach ($replacements as $key => $value) {
            $title = str_replace("{" . $key . "}", $value, $title);
            $body = str_replace("{" . $key . "}", $value, $body);
        }
        
        // Send the toast notification
        $player->sendToastNotification($title, $body);
    }

    /**
     * Send a direct message to a player (pass-through method to centralize message sending)
     * 
     * @param \pocketmine\command\CommandSender $sender
     * @param string $message The message to send
     */
    public function sendMessage(\pocketmine\command\CommandSender $sender, string $message): void {
        $sender->sendMessage($message);
    }

    /**
     * Reload messages from config
     */
    public function reloadMessages(): void {
        $this->loadMessages();
    }
}