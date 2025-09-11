<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;

class MainForm {
    /** @var Main */
    private Main $plugin;

    /**
     * MainForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the main form to a player
     * 
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            switch ($data) {
                case 0: // Chat with AI
                    $form = new ChatForm($this->plugin);
                    $form->sendTo($player);
                    break;
                    
                case 1: // View Chat History
                    $form = new HistoryForm($this->plugin);
                    $form->sendTo($player);
                    break;
                    
                case 2: // Crafting Helper
                    $form = new CraftingForm($this->plugin);
                    $form->sendTo($player);
                    break;
                    
                case 3: // Building Calculator
                    $form = new BuildingCalculatorForm($this->plugin);
                    $form->sendTo($player);
                    break;
                    
                case 4: // Token Shop
                    $form = new TokenShopForm($this->plugin);
                    $form->sendTo($player);
                    break;
                    
                case 5: // Server Info
                    $form = new ServerInfoForm($this->plugin);
                    $form->sendTo($player);
                    break;
                    
            }
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("main_form.title", "AI Assistant");
        $content = $this->plugin->getFormSetting("main_form.content", "Welcome to the AI Assistant! What would you like to do?");
        
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
            
            $content = TextFormat::colorize($highlightFormat . $tokenStatus . "\n\n") . 
                      TextFormat::colorize($contentFormat . $content);
        } else {
            $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
            $content = TextFormat::colorize($contentFormat . $content);
        }
        
        $form->setContent($content);
        
        // Add buttons from config
        $this->addButtonFromConfig($form, "main_form.buttons.chat", "Chat with AI", 0);
        $this->addButtonFromConfig($form, "main_form.buttons.history", "View Chat History", 1);
        $this->addButtonFromConfig($form, "main_form.buttons.crafting", "Crafting Helper", 2);
        $this->addButtonFromConfig($form, "main_form.buttons.building", "Building Calculator", 3);
        
        if ($this->plugin->getConfig()->getNested("tokens.enabled", true)) {
            $this->addButtonFromConfig($form, "main_form.buttons.token_shop", "Token Shop", 4);
        }
        
        $this->addButtonFromConfig($form, "main_form.buttons.server_info", "Server Info", 5);
        
        
        $form->sendToPlayer($player);
    }
    
    /**
     * Add a button to the form using configuration
     * 
     * @param SimpleForm $form The form to add the button to
     * @param string $configPath The path to the button configuration
     * @param string $defaultText The default button text
     * @param int $index The button index for debugging
     */
    private function addButtonFromConfig(SimpleForm $form, string $configPath, string $defaultText, int $index): void {
        // Get button text from config
        $text = $this->plugin->getFormSetting("$configPath.text", $defaultText);
        
        // Get button color from config
        $color = $this->plugin->getFormSetting("$configPath.color", "&a&l");
        
        // Format button text
        $buttonText = $this->plugin->formatFormText($color . $text);
        
        // Get button texture from config
        $texture = $this->plugin->getFormSetting("$configPath.texture", "");
        
        // Add button to form
        $form->addButton($buttonText, 0, $texture);
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Added button '$text' with texture '$texture' at index $index");
        }
    }
}