<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;

class ChatForm {
    /** @var Main */
    private Main $plugin;

    /**
     * ChatForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the chat form to a player
     * 
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                return;
            }
            
            $question = trim($data[0] ?? "");
            if (empty($question)) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.enter_question_first");
                $this->plugin->getMessageManager()->sendToastNotification($player, "error", $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_title"), $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_body"));
                
                // Reopen the form so user can try again
                $this->sendTo($player);
                return;
            }
            
            // Check if player has enough tokens
            if ($this->plugin->getConfig()->getNested("tokens.enabled", true)) {
                $tokenManager = $this->plugin->getTokenManager();
                if (!$tokenManager->hasTokens($player->getName())) {
                    $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.no_tokens_purchase");
                    $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "no_tokens");
                    
                    // Open token shop form
                    $form = new TokenShopForm($this->plugin);
                    $form->sendTo($player);
                    return;
                }
            }
            
            // Check if player already has an active request
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            if ($requestManager->hasActiveRequest($player->getName())) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.request_already_active");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_active");
                return;
            }
            
            // Show loading form
            $loadingTitle = $this->plugin->getMessageManager()->getConfigurableMessage("loading.titles.chat_processing");
            $loadingForm = new LoadingForm($this->plugin, $player, $question, $loadingTitle);
            $loadingForm->show(function() use ($player) {
                // This is called when the loading form is cancelled
                $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.request_cancelled");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_cancelled");
            });
            
            // Send toast notification
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "processing");
            
            // Process the question in a separate task to avoid blocking the main thread
            $this->plugin->getScheduler()->scheduleTask(new ClosureTask(
                function() use ($player, $question, $loadingForm): void {
                    // Check if the player is still online
                    if (!$player->isOnline()) {
                        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
                        return;
                    }
                    
                    // Check if the loading form was cancelled
                    if ($loadingForm->isCancelled()) {
                        return;
                    }
                    
                    // Get the AI provider
                    $provider = $this->plugin->getProviderManager()->getDefaultProvider();
                    
                    // Process the query
                    $response = $this->plugin->getProviderManager()->processQuery($player, $question);
                    
                    // Check if the player is still online
                    if (!$player->isOnline()) {
                        return;
                    }
                    
                    // Check if the loading form was cancelled
                    if ($loadingForm->isCancelled()) {
                        return;
                    }
                    
                    // Deduct token if enabled
                    if ($this->plugin->getConfig()->getNested("tokens.enabled", true)) {
                        $this->plugin->getTokenManager()->useToken($player->getName());
                    }
                    
                    // Cancel the loading form
                    $loadingForm->cancel();
                    
                    // Send toast notification
                    $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "response_ready");
                    
                    // Show the response
                    $form = new ResponseForm($this->plugin);
                    $form->sendTo($player, $question, $response);
                }
            ));
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("chat_form.title", "Chat with AI");
        $content = $this->plugin->getFormSetting("chat_form.content", "What would you like to ask the AI Assistant?");
        $placeholder = $this->plugin->getFormSetting("chat_form.placeholder", "Type your question here...");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Set form title
        $form->setTitle($title);
        
        // Add token status if token system is enabled
        if ($this->plugin->getConfig()->getNested("tokens.enabled", true)) {
            $tokenManager = $this->plugin->getTokenManager();
            $tokenStatus = $tokenManager->getTokenStatusMessage($player->getName());
            
            $highlightFormat = $this->plugin->getFormSetting("general.text_formatting.highlight", "&e");
            $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
            
            $form->addLabel(TextFormat::colorize($highlightFormat . $tokenStatus . "\n\n") . 
                           TextFormat::colorize($contentFormat . $content));
        } else {
            $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
            $form->addLabel(TextFormat::colorize($contentFormat . $content));
        }
        
        // Add input field with better guidance
        $form->addInput("§fYour Question:", "Example: How do I craft a diamond sword?");
        
        $form->sendToPlayer($player);
        
        // Send toast notification when form is opened
        $this->plugin->getMessageManager()->sendToastNotification($player, "info", $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_title"), $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_body"));
    }
}