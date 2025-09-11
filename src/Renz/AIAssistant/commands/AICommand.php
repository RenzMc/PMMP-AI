<?php

declare(strict_types=1);

namespace Renz\AIAssistant\commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\forms\MainForm;

class AICommand extends Command {
    /** @var Main */
    private Main $plugin;

    /**
     * AICommand constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $description = $plugin->getMessageManager()->getConfigurableMessage("ui.command_description");
        parent::__construct("ai", $description, "/ai", ["assistant"]);
        $this->setPermission("aiassistant.command.ai");
        $this->plugin = $plugin;
    }

    /**
     * Execute the command
     * 
     * @param CommandSender $sender
     * @param string $commandLabel
     * @param array $args
     * @return bool
     */
    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }

        if (!$sender instanceof Player) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($sender, "console.command_ingame_only");
            return false;
        }

        // Check if there are arguments
        if (count($args) > 0) {
            // Handle subcommands
            switch (strtolower($args[0])) {
                case "help":
                    return $this->showHelp($sender);
                
                case "clear":
                    return $this->clearConversation($sender);
                    
                case "setup":
                    return $this->handleSetupCommand($sender, array_slice($args, 1));
                    
                case "provider":
                    return $this->handleProviderCommand($sender, array_slice($args, 1));
                
                default:
                    // If no recognized subcommand, treat the entire args as a direct query
                    $query = implode(" ", $args);
                    return $this->processDirectQuery($sender, $query);
            }
        }

        // No arguments, open the main form
        $this->openMainForm($sender);
        return true;
    }

    /**
     * Show help information
     * 
     * @param Player $player
     * @return bool
     */
    private function showHelp(Player $player): bool {
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.header");
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.command_ai");
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.command_help");
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.command_clear");
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.command_question");
        
        if ($player->hasPermission("aiassistant.admin")) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.command_setup");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.command_provider_list");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.command_provider_set");
        }
        
        return true;
    }

    /**
     * Handle setup command
     * 
     * @param Player $player
     * @param array $args
     * @return bool
     */
    private function handleSetupCommand(Player $player, array $args): bool {
        if (!$player->hasPermission("aiassistant.admin")) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.admin_only");
            return false;
        }
        
        if (empty($args)) {
            // Show setup menu
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.welcome_header");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.choose_provider");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.provider_list");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.provider_openai");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.provider_openrouter");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.provider_anthropic");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.provider_google");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.provider_local");
            $player->sendMessage("");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.usage_format");
            return true;
        }
        
        if (count($args) < 2) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.missing_args");
            return false;
        }
        
        $providerName = strtolower($args[0]);
        $apiKey = $args[1];
        
        // Map provider names and models
        $providers = [
            "openai" => "gpt-3.5-turbo",
            "openrouter" => "openai/gpt-3.5-turbo", 
            "anthropic" => "claude-3-haiku-20240307",
            "google" => "gemini-pro",
            "local" => "local-model"
        ];
        
        if (!isset($providers[$providerName])) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.invalid_provider");
            return false;
        }
        
        // Configure provider
        $config = $this->plugin->getConfig();
        $config->setNested("api_providers.{$providerName}.enabled", true);
        $config->setNested("api_providers.{$providerName}.api_key", $apiKey);
        $config->setNested("api_providers.{$providerName}.model", $providers[$providerName]);
        $config->setNested("api_providers.default_provider", $providerName);
        $config->save();
        
        // Reload providers
        $this->plugin->getProviderManager()->reloadProviders();
        
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.setup_complete", ["provider" => $providerName]);
        return true;
    }

    /**
     * Clear the player's conversation history
     * 
     * @param Player $player
     * @return bool
     */
    private function clearConversation(Player $player): bool {
        $this->plugin->getConversationManager()->clearConversation($player->getName());
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.conversation_cleared");
        return true;
    }

    /**
     * Handle provider-related commands
     * 
     * @param Player $player
     * @param array $args
     * @return bool
     */
    private function handleProviderCommand(Player $player, array $args): bool {
        if (!$player->hasPermission("aiassistant.admin")) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.no_permission_providers");
            return false;
        }
        
        if (empty($args)) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.provider_usage");
            return false;
        }
        
        $providerManager = $this->plugin->getProviderManager();
        
        switch (strtolower($args[0])) {
            case "list":
                $providers = $providerManager->getAvailableProviders();
                $defaultProvider = $providerManager->getDefaultProvider();
                
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "providers.list_header");
                
                if (empty($providers)) {
                    $this->plugin->getMessageManager()->sendConfigurableMessage($player, "providers.no_providers");
                } else {
                    foreach ($providers as $provider) {
                        $isDefault = ($provider === $defaultProvider) ? $this->plugin->getMessageManager()->getConfigurableMessage("providers.default_marker") : "";
                        $providerMessage = "§b- " . $provider . $isDefault;
                        $player->sendMessage($providerMessage);
                    }
                }
                return true;
            
            case "set":
                if (!isset($args[1])) {
                    $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.provider_set_usage");
                    return false;
                }
                
                $providerName = strtolower($args[1]);
                
                if (!$providerManager->isProviderAvailable($providerName)) {
                    $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.provider_not_available", ["provider" => $providerName]);
                    return false;
                }
                
                $providerManager->setDefaultProvider($providerName);
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.provider_set_success", ["provider" => $providerName]);
                return true;
            
            default:
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.provider_usage");
                return false;
        }
    }

    /**
     * Process a direct query
     * 
     * @param Player $player
     * @param string $query
     * @return bool
     */
    private function processDirectQuery(Player $player, string $query): bool {
        // Check if token system is enabled and player has tokens
        $tokenManager = $this->plugin->getTokenManager();
        if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.no_tokens_left");
            return false;
        }
        
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.processing_query");
        
        // Use token if token system is enabled
        if ($tokenManager->isEnabled()) {
            $tokenManager->useToken($player);
        }
        
        // Process the query
        $response = $this->plugin->getProviderManager()->processQuery($player, $query);
        
        // Send the response
        $player->sendMessage($this->plugin->getMessageManager()->formatAIResponse($response));
        
        return true;
    }

    /**
     * Open the main form
     * 
     * @param Player $player
     */
    private function openMainForm(Player $player): void {
        $form = new MainForm($this->plugin);
        $form->sendTo($player);
    }
}