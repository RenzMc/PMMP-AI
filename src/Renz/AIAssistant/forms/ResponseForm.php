<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;

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
        $form = new SimpleForm(function(Player $player, ?int $data) use ($question) {
            if ($data === null) {
                return;
            }
            
            switch ($data) {
                case 0: // Back to main menu
                    $form = new MainForm($this->plugin);
                    $form->sendTo($player);
                    $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "main_menu");
                    break;
                    
                case 1: // Ask another question
                    $form = new ChatForm($this->plugin);
                    $form->sendTo($player);
                    $this->plugin->getMessageManager()->sendToastNotification($player, "info", $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_title"), $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_body"));
                    break;
            }
        });
        
        // Get form title from forms config
        $title = $this->plugin->getFormSetting("response_form.title", "AI Response");
        
        // Get question and response prefixes from forms config
        $questionPrefix = $this->plugin->getFormSetting("response_form.question_prefix", "Question: ");
        $responsePrefix = $this->plugin->getFormSetting("response_form.response_prefix", "Response: ");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Format content with Minecraft text formatting
        $content = MinecraftTextFormatter::COLOR_YELLOW . $questionPrefix . MinecraftTextFormatter::COLOR_WHITE . $question . "\n\n" .
                  MinecraftTextFormatter::COLOR_YELLOW . $responsePrefix . "\n" . $response;
        
        // Ensure the response has proper Minecraft formatting
        if (!str_contains($response, "§")) {
            // If the response doesn't contain any Minecraft formatting codes, apply default formatting
            $content = MinecraftTextFormatter::COLOR_YELLOW . $questionPrefix . MinecraftTextFormatter::COLOR_WHITE . $question . "\n\n" .
                      MinecraftTextFormatter::COLOR_YELLOW . $responsePrefix . "\n" . MinecraftTextFormatter::formatText($response);
        }
        
        $form->setTitle($title);
        $form->setContent($content);
        
        // Add back button from config
        $backText = $this->plugin->getFormSetting("response_form.buttons.back.text", "Back to Main Menu");
        $backColor = $this->plugin->getFormSetting("response_form.buttons.back.color", "&9");
        $backTexture = $this->plugin->getFormSetting("response_form.buttons.back.texture", "textures/ui/arrow_left");
        
        $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
        
        // Add new question button from config
        $newQuestionText = $this->plugin->getFormSetting("response_form.buttons.new_question.text", "Ask Another Question");
        $newQuestionColor = $this->plugin->getFormSetting("response_form.buttons.new_question.color", "&a");
        $newQuestionTexture = $this->plugin->getFormSetting("response_form.buttons.new_question.texture", "textures/ui/chat_icon");
        
        $form->addButton($this->plugin->formatFormText($newQuestionColor . $newQuestionText), 0, $newQuestionTexture);
        
        // Save conversation to history
        $this->plugin->getConversationManager()->addMessage($player->getName(), $question, $response);
        
        $form->sendToPlayer($player);
        
        // Send toast notification when response is shown
        $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "response_ready");
    }
}