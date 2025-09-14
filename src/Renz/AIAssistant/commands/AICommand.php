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
            // Handle subcommands using match
            return match (strtolower($args[0])) {
                "help" => $this->showHelp($sender),
                "clear" => $this->clearConversation($sender),
                "setup" => $this->handleSetupCommand($sender, array_slice($args, 1)),
                "provider" => $this->handleProviderCommand($sender, array_slice($args, 1)),
                default => $this->processDirectQuery($sender, implode(" ", $args))
            };
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
     * Handle setup command with 3 arguments: provider, apikey, model
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
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "help.separator");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.usage_format");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.usage_example");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.note_anthropic_local");
            return true;
        }
        
        if (count($args) < 3) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.missing_arguments");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.usage_example");
            return false;
        }
        
        $providerName = strtolower($args[0]);
        $apiKey = $args[1];
        $modelName = $args[2];
        
        // Supported providers
        $supportedProviders = ["openai", "openrouter", "anthropic", "google", "local"];
        
        if (!in_array($providerName, $supportedProviders)) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.invalid_provider_list", ["providers" => implode(", ", $supportedProviders)]);
            return false;
        }
        
        // Skip all validation - direct setup to config
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.setting_up");
        $this->setupProviderDirectly($player, $providerName, $apiKey, $modelName);
        return true;
    }
    
    /**
     * Setup provider directly without validation (for Anthropic and Local)
     * 
     * @param Player $player
     * @param string $providerName
     * @param string $apiKey
     * @param string $modelName
     */
    private function setupProviderDirectly(Player $player, string $providerName, string $apiKey, string $modelName): void {
        // Save configuration directly
        $config = $this->plugin->getConfig();
        $config->setNested("api_providers.{$providerName}.enabled", true);
        $config->setNested("api_providers.{$providerName}.api_key", $apiKey);
        $config->setNested("api_providers.{$providerName}.model", $modelName);
        $config->setNested("api_providers.default_provider", $providerName);
        $config->save();
        
        // Reload all configurations - Fixed: Must reload PocketMine's config first!
        $this->plugin->getConfig()->reload();
        $this->plugin->getPluginConfig()->reload();
        $this->plugin->getFormsConfig()->reload();
        $this->plugin->getServerFeaturesConfig()->reload();
        $this->plugin->getMessageManager()->reloadMessages();
        $this->plugin->getProviderManager()->reloadProviders();
        
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.config_success", ["provider" => $providerName, "model" => $modelName]);
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.provider_set_default");
        
        // Add usage hints for each provider using match
        match ($providerName) {
            'anthropic' => $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.note_anthropic"),
            'local' => $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.note_local"),
            'openai' => $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.note_openai"),
            'openrouter' => $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.note_openrouter"),
            'google' => $this->plugin->getMessageManager()->sendConfigurableMessage($player, "setup.note_google"),
            default => null
        };
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
        
        return match (strtolower($args[0])) {
            "list" => (function() use ($player, $providerManager) {
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
            })(),
            
            "set" => (function() use ($player, $args, $providerManager) {
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
            })(),
            
            default => (function() use ($player) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.provider_usage");
                return false;
            })()
        };
    }
    
    /**
     * Process a direct query
     * 
     * @param Player $player
     * @param string $query
     * @return bool
     */
    private function processDirectQuery(Player $player, string $query): bool {
        // Validate query
        $query = trim($query);
        if (empty($query)) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.enter_question_generic");
            return false;
        }
        
        // Check if token system is enabled and player has tokens
        $tokenManager = $this->plugin->getTokenManager();
        if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.no_tokens_left");
            return false;
        }
        
        // Check if player already has an active request
        $requestManager = $this->plugin->getProviderManager()->getRequestManager();
        if ($requestManager->hasActiveRequest($player->getName())) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.request_already_active");
            return false;
        }
        
        // First cancel any existing requests to ensure clean state
        // FIX: Use cancelRequest instead of cancelPlayerRequests
        $requestManager->cancelRequest($player->getName());
        
        $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.processing_query");
        
        try {
            // Store command context so async callbacks know this came from a command
            $requestManager->setFormContext($player->getName(), [
                'type' => 'direct_command',
                'query' => $query,
                'tokenManager' => $tokenManager,
                'player' => $player->getName() // Store player name for safety
            ]);
            
            // Process the query asynchronously with a delay to ensure proper setup
            $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
                function() use ($player, $query, $tokenManager, $requestManager): void {
                    if (!$player->isOnline()) return;
                    
                    try {
                        // Process the query asynchronously
                        $response = $this->plugin->getProviderManager()->processQuery($player, $query);
                        
                        // Check if this is a processing message (async) or actual response (sync/cached)
                        $isProcessingMessage = strpos($response, 'Processing your') !== false || 
                                            strpos($response, 'Processing') !== false || 
                                            strpos($response, 'Please wait') !== false;
                        
                        if (!$isProcessingMessage) {
                            // This is a synchronous response (cached or fallback) - send it immediately
                            $this->handleDirectQueryResponse($player, $query, $response, $tokenManager);
                        }
                    } catch (\Throwable $e) {
                        $this->plugin->getLogger()->error("Error in processDirectQuery for player " . $player->getName() . ": " . $e->getMessage());
                        
                        // Send error message using configurable messages
                        $errorMessage = $this->getErrorMessage($e);
                        $player->sendMessage($errorMessage);
                        
                        // Clean up request
                        $requestManager->completeRequest($player->getName(), '');
                    }
                }
            ), 5); // Short delay to ensure proper setup
            
        } catch (\Throwable $e) {
            $this->plugin->getLogger()->error("Error in processDirectQuery for player " . $player->getName() . ": " . $e->getMessage());
            $this->plugin->getLogger()->error("Stack trace: " . $e->getTraceAsString());
            
            // Send error message using configurable messages
            $errorMessage = $this->getErrorMessage($e);
            $player->sendMessage($errorMessage);
            
            // Clean up request
            $requestManager->completeRequest($player->getName(), '');
            return false;
        }
        
        return true;
    }
    
    /**
     * Handle direct query response (for both sync and async responses)
     * 
     * @param Player $player
     * @param string $query
     * @param string $response
     * @param TokenManager $tokenManager
     */
    public function handleDirectQueryResponse(Player $player, string $query, string $response, $tokenManager): void {
        // Ensure we have a valid response
        if (empty($response) || trim($response) === "") {
            $player->sendMessage($this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed"));
            $this->plugin->getLogger()->warning("Empty AI response for player " . $player->getName() . " with query: " . $query);
            return;
        }
        
        // Format and send the response to chat
        $formattedResponse = $this->plugin->getMessageManager()->formatAIResponse($response);
        
        // Send response with proper formatting
        if (!empty($formattedResponse)) {
            $player->sendMessage($formattedResponse);
            
            // Use token if token system is enabled (only after successful response)
            if ($tokenManager->isEnabled()) {
                $tokenManager->useToken($player);
            }
            
            // Also send success notification
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.response_sent");
            
            // Add to conversation history
            $this->plugin->getConversationManager()->addToConversation($player->getName(), $query, $response);
        } else {
            $player->sendMessage($this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed"));
        }
    }
    
    /**
     * Get appropriate error message based on exception type
     * 
     * @param \Throwable $e
     * @return string
     */
    private function getErrorMessage(\Throwable $e): string {
        $errorMsg = $e->getMessage();
        
        // Check for specific error types and return appropriate configured messages using match
        return match (true) {
            strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, 'timed out') !== false 
                => $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_timeout_error"),
            
            strpos($errorMsg, 'connection') !== false || strpos($errorMsg, 'connect') !== false 
                => $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_connection_error"),
            
            strpos($errorMsg, 'rate limit') !== false || strpos($errorMsg, 'too many') !== false 
                => $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_rate_limit_error"),
            
            strpos($errorMsg, 'quota') !== false || strpos($errorMsg, 'exceeded') !== false 
                => $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_quota_exceeded"),
            
            strpos($errorMsg, 'invalid') !== false || strpos($errorMsg, 'parse') !== false 
                => $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_invalid_response"),
            
            strpos($errorMsg, 'unavailable') !== false || strpos($errorMsg, 'service') !== false 
                => $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_service_unavailable"),
            
            default => $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_unknown_error")
        };
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