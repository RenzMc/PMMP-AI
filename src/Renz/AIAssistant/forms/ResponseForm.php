<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;
use Renz\AIAssistant\forms\MainForm;
use Renz\AIAssistant\forms\ChatForm;

class ResponseForm {
    /** @var Main */
    private Main $plugin;

    /**
     * ResponseForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the response form to a player
     * 
     * @param Player $player
     * @param string $question
     * @param string $response
     */
    public function sendTo(Player $player, string $question, string $response): void {
        // Handle loading state
        $isLoading = strpos($response, "⏳") === 0 || strpos($response, "Processing") !== false || strpos($response, "Please wait") !== false;
        
        // Handle error states
        $isError = strpos($response, "§c") === 0 || strpos($response, "error") !== false || strpos($response, "failed") !== false;
        
        // Ensure response content is properly formatted and not empty
        if (empty(trim($response))) {
            $response = $this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed");
            $isError = true;
        }
        
        // Only cancel pending requests if this is an error or final response (not loading)
        if (!$isLoading || $isError) {
            $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
        }
        
        $form = new SimpleForm(function(Player $player, ?int $data) use ($question, $response) {
            if ($data === null) {
                return;
            }
            
            // Determine if this is an error or loading state to handle button indices correctly
            $isLoading = strpos($response, "⏳") === 0 || strpos($response, "Processing") !== false;
            $isError = strpos($response, "§c") === 0 || strpos($response, "error") !== false || strpos($response, "failed") !== false;
            
            switch ($data) {
                case 0: // Back to main menu (always first button)
                    $form = new MainForm($this->plugin);
                    $form->sendTo($player);
                    $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "main_menu");
                    break;
                    
                case 1: // Ask another question OR Retry (second button when available)
                    // Ensure request is completely cleaned up before opening new chat form
                    $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
                    
                    // Add small delay to ensure proper cleanup before opening new form
                    $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
                        function() use ($player): void {
                            if ($player->isOnline()) {
                                $form = new ChatForm($this->plugin);
                                $form->sendTo($player);
                                $this->plugin->getMessageManager()->sendToastNotification(
                                    $player,
                                    "info",
                                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_title"),
                                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_body")
                                );
                            }
                        }
                    ), 5); // 5 ticks delay (0.25 seconds)
                    break;
                    
                case 2: // Retry button (only available for errors, third button)
                    if ($isError) {
                        // Retry the same question
                        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
                        
                        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
                            function() use ($player, $question): void {
                                if ($player->isOnline()) {
                                    // Simulate clicking submit with the same question
                                    $requestManager = $this->plugin->getProviderManager()->getRequestManager();
                                    $requestManager->setFormContext($player->getName(), [
                                        'type' => 'chat_form',
                                        'question' => $question,
                                        'tokenManager' => $this->plugin->getTokenManager()
                                    ]);
                                    
                                    // Show loading response form immediately
                                    $responseForm = new ResponseForm($this->plugin);
                                    $loadingMessage = "⏳ " . $this->plugin->getMessageManager()->getConfigurableMessage("loading.content.waiting_message");
                                    $responseForm->sendTo($player, $question, $loadingMessage);
                                    
                                    // Process the query
                                    try {
                                        $this->plugin->getProviderManager()->processQuery($player, $question);
                                    } catch (\Throwable $e) {
                                        $errorMessage = $this->getErrorMessage($e);
                                        $responseForm->sendTo($player, $question, $errorMessage);
                                    }
                                }
                            }
                        ), 5); // 5 ticks delay (0.25 seconds)
                    }
                    break;
            }
        });
        
        $title = $this->plugin->getFormSetting("response_form.title", "AI Response");
        $questionPrefix = $this->plugin->getFormSetting("response_form.question_prefix", "Question: ");
        $responsePrefix = $this->plugin->getFormSetting("response_form.response_prefix", "Response: ");
        
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Ensure response content is properly formatted and visible
        $formattedResponse = trim($response);
        if (empty($formattedResponse)) {
            $formattedResponse = $this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed");
            $isError = true;
        }
        
        // Apply text formatting if needed
        if (!str_contains($formattedResponse, "§")) {
            $formattedResponse = MinecraftTextFormatter::formatText($formattedResponse);
        }
        
        // Format content based on state
        $questionColor = MinecraftTextFormatter::COLOR_YELLOW;
        $responseColor = $isError ? MinecraftTextFormatter::COLOR_RED : ($isLoading ? MinecraftTextFormatter::COLOR_AQUA : MinecraftTextFormatter::COLOR_GREEN);
        
        $content = $questionColor . $questionPrefix . MinecraftTextFormatter::COLOR_WHITE . $question . "\n\n" .
                  $responseColor . $responsePrefix . "\n" . $formattedResponse;
        
        $form->setTitle($title);
        $form->setContent($content);
        
        $backText = $this->plugin->getFormSetting("response_form.buttons.back.text", "Back to Main Menu");
        $backColor = $this->plugin->getFormSetting("response_form.buttons.back.color", "&9");
        $backTexture = $this->plugin->getFormSetting("response_form.buttons.back.texture", "textures/ui/arrow_left");
        $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
        
        // Only show "Ask Another Question" button if not in loading state
        if (!$isLoading) {
            $newQuestionText = $this->plugin->getFormSetting("response_form.buttons.new_question.text", "Ask Another Question");
            $newQuestionColor = $this->plugin->getFormSetting("response_form.buttons.new_question.color", "&a");
            $newQuestionTexture = $this->plugin->getFormSetting("response_form.buttons.new_question.texture", "textures/ui/chat_icon");
            $form->addButton($this->plugin->formatFormText($newQuestionColor . $newQuestionText), 0, $newQuestionTexture);
        }
        
        // Add retry button for errors
        if ($isError && !$isLoading) {
            $retryText = "§6Retry Question";
            $form->addButton($retryText, 0, "textures/ui/refresh");
        }
        
        $this->plugin->getConversationManager()->addToConversation($player->getName(), $question, $response);
        
        // Add small delay to ensure form is properly created before sending
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
            function() use ($form, $player): void {
                if ($player->isOnline()) {
                    $form->sendToPlayer($player);
                }
            }
        ), 3); // 3 ticks delay (0.15 seconds)
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