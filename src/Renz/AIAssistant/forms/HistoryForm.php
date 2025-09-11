<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;

class HistoryForm {
    /** @var Main */
    private Main $plugin;

    /**
     * HistoryForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the history form to a player
     * 
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            if ($data === 0) {
                // Back button pressed
                $form = new MainForm($this->plugin);
                $form->sendTo($player);
                return;
            }
            
            // Adjust index to account for the back button
            $sessionIndex = $data - 1;
            
            // Get session ID
            $sessions = $this->plugin->getConversationManager()->getSessionsMetadata($player->getName());
            if (!isset($sessions[$sessionIndex])) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "history.session_not_found");
                return;
            }
            
            $sessionId = $sessions[$sessionIndex]['id'];
            
            // Show session details
            $this->showSessionDetails($player, $sessionId);
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("history_form.title", "Chat History");
        $content = $this->plugin->getFormSetting("history_form.content", "Your previous conversations with the AI Assistant:");
        $emptyMessage = $this->plugin->getFormSetting("history_form.empty_message", "You don't have any chat history yet.");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Set form title
        $form->setTitle($title);
        
        // Get sessions
        $sessions = $this->plugin->getConversationManager()->getSessionsMetadata($player->getName());
        
        // Format content with text formatting from config
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
        
        if (empty($sessions)) {
            $form->setContent(TextFormat::colorize($contentFormat . $emptyMessage));
        } else {
            $form->setContent(TextFormat::colorize($contentFormat . $content));
        }
        
        // Add back button from config
        $backText = $this->plugin->getFormSetting("history_form.buttons.back.text", "Back");
        $backColor = $this->plugin->getFormSetting("history_form.buttons.back.color", "&7");
        $backTexture = $this->plugin->getFormSetting("history_form.buttons.back.texture", "textures/ui/arrow_left");
        
        $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
        
        // Add session buttons
        if (!empty($sessions)) {
            $viewText = $this->plugin->getFormSetting("history_form.buttons.view.text", "View");
            $viewColor = $this->plugin->getFormSetting("history_form.buttons.view.color", "&a");
            $viewTexture = $this->plugin->getFormSetting("history_form.buttons.view.texture", "textures/ui/check");
            
            foreach ($sessions as $session) {
                $timestamp = date('Y-m-d H:i:s', $session['timestamp']);
                $firstMessage = $session['first_message'] ?? 'No message';
                $buttonText = $this->plugin->formatFormText($viewColor . $viewText . ": " . substr($firstMessage, 0, 20) . "... (" . $timestamp . ")");
                $form->addButton($buttonText, 0, $viewTexture);
            }
        }
        
        $form->sendToPlayer($player);
    }
    
    /**
     * Show session details
     * 
     * @param Player $player
     * @param string $sessionId
     */
    private function showSessionDetails(Player $player, string $sessionId): void {
        $form = new SimpleForm(function(Player $player, ?int $data) use ($sessionId) {
            if ($data === null) {
                return;
            }
            
            if ($data === 0) {
                // Back button pressed
                $this->sendTo($player);
                return;
            }
            
            if ($data === 1) {
                // Delete button pressed
                $this->plugin->getConversationManager()->deleteSession($player->getName(), $sessionId);
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "history.conversation_deleted");
                $this->sendTo($player);
                return;
            }
        });
        
        // Get session messages
        $messages = $this->plugin->getConversationManager()->getSessionMessages($player->getName(), $sessionId);
        
        // Get form title from forms config
        $title = $this->plugin->getFormSetting("history_form.title", "Chat History");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Set form title
        $form->setTitle($title);
        
        // Format content
        $highlightFormat = $this->plugin->getFormSetting("general.text_formatting.highlight", "&e");
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&f");
        
        $content = "";
        foreach ($messages as $message) {
            $content .= TextFormat::colorize($highlightFormat . "Question: ") . 
                       TextFormat::colorize($contentFormat . $message['question'] . "\n\n") .
                       TextFormat::colorize($highlightFormat . "Response: ") . 
                       TextFormat::colorize($contentFormat . $message['response'] . "\n\n") .
                       "----------------------------------------\n\n";
        }
        
        $form->setContent($content);
        
        // Add back button from config
        $backText = $this->plugin->getFormSetting("history_form.buttons.back.text", "Back");
        $backColor = $this->plugin->getFormSetting("history_form.buttons.back.color", "&7");
        $backTexture = $this->plugin->getFormSetting("history_form.buttons.back.texture", "textures/ui/arrow_left");
        
        $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
        
        // Add delete button from config
        $deleteText = $this->plugin->getFormSetting("history_form.buttons.delete.text", "Delete");
        $deleteColor = $this->plugin->getFormSetting("history_form.buttons.delete.color", "&c");
        $deleteTexture = $this->plugin->getFormSetting("history_form.buttons.delete.texture", "textures/ui/trash");
        
        $form->addButton($this->plugin->formatFormText($deleteColor . $deleteText), 0, $deleteTexture);
        
        $form->sendToPlayer($player);
    }
}