<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\forms\ResponseForm;
use Renz\AIAssistant\forms\TokenShopForm;

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
        // Build the form. We explicitly give the input a label 'question'
        // so CustomForm->processData will map the value to $data['question']
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                // Player closed the form
                return;
            }

            // Extract the question from form data - typically the first input element
            $question = "";
            foreach ($data as $v) {
                if (is_string($v) && trim($v) !== "") {
                    $question = trim($v);
                    break;
                }
            }

            if ($question === "") {
                // No question provided - notify player and reopen the form
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.enter_question_first");
                $this->plugin->getMessageManager()->sendToastNotification(
                    $player,
                    "error",
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_title"),
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_body")
                );

                // Reopen the form so user can try again
                $this->sendTo($player);
                return;
            }

            // Token handling using TokenManager methods (as provided)
            $tokenManager = $this->plugin->getTokenManager();
            if ($tokenManager->isEnabled()) {
                // Check if player can use a token (purchased or free daily)
                if (!$tokenManager->canUseToken($player)) {
                    // Notify player and open token shop
                    $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.no_tokens_purchase");
                    $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "no_tokens");

                    $shopForm = new TokenShopForm($this->plugin);
                    $shopForm->sendTo($player);
                    return;
                }
            }

            // Check if player already has an active request
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            if ($requestManager->hasActiveRequest($player->getName())) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.request_already_active");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_active");
                return;
            }

            // Send processing notification first
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "processing");

            // Store form context so providers know this is a form request
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            $requestManager->setFormContext($player->getName(), [
                'type' => 'chat_form',
                'question' => $question,
                'tokenManager' => $tokenManager
            ]);

            // Immediately open ResponseForm with loading message
            $responseForm = new ResponseForm($this->plugin);
            $loadingMessage = "⏳ " . $this->plugin->getMessageManager()->getConfigurableMessage("loading.content.waiting_message");
            
            // Show loading response form immediately
            $responseForm->sendTo($player, $question, $loadingMessage);

            // Process the query with async handling
            try {
                $this->plugin->getProviderManager()->processQuery($player, $question);
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("ChatForm processQuery error: " . $e->getMessage());
                
                // Show error in response form
                $errorMessage = $this->getErrorMessage($e);
                $responseForm->sendTo($player, $question, $errorMessage);
                
                // Also send notification
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_cancelled");
                return;
            }
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

        // Add token status if token system is enabled (use TokenManager API)
        $tokenManager = $this->plugin->getTokenManager();
        if ($tokenManager->isEnabled()) {
            $tokenStatus = $tokenManager->getTokenStatusMessage($player->getName());

            $highlightFormat = $this->plugin->getFormSetting("general.text_formatting.highlight", "&e");
            $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");

            $form->addLabel(TextFormat::colorize($highlightFormat . $tokenStatus . "\n\n") .
                           TextFormat::colorize($contentFormat . $content));
        } else {
            $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
            $form->addLabel(TextFormat::colorize($contentFormat . $content));
        }

        // Add input field with explicit label 'question' to ensure returned data can be read reliably
        $form->addInput("§fYour Question:", $placeholder);

        $form->sendToPlayer($player);

        // Send toast notification when form is opened
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_title"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_body")
        );
    }
    
    /**
     * Get appropriate error message based on exception type
     * 
     * @param \Throwable $e
     * @return string
     */
    private function getErrorMessage(\Throwable $e): string {
        $errorMsg = $e->getMessage();
        
        // Check for specific error types and return appropriate configured messages
        if (strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, 'timed out') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_timeout_error");
        }
        
        if (strpos($errorMsg, 'connection') !== false || strpos($errorMsg, 'connect') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_connection_error");
        }
        
        if (strpos($errorMsg, 'rate limit') !== false || strpos($errorMsg, 'too many') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_rate_limit_error");
        }
        
        if (strpos($errorMsg, 'quota') !== false || strpos($errorMsg, 'exceeded') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_quota_exceeded");
        }
        
        if (strpos($errorMsg, 'invalid') !== false || strpos($errorMsg, 'parse') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_invalid_response");
        }
        
        if (strpos($errorMsg, 'unavailable') !== false || strpos($errorMsg, 'service') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_service_unavailable");
        }
        
        // Default error message
        return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_unknown_error");
    }
}