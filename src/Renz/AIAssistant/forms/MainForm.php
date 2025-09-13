<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;
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
                    $this->openCraftingHelperForm($player);
                    break;
                    
                case 3: // Building Calculator
                    $this->openBuildingCalculatorForm($player);
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

    /**
     * Open the crafting helper form
     * 
     * @param Player $player
     */
    private function openCraftingHelperForm(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                return;
            }
            
            $item = trim($data[1] ?? "");
            if (empty($item)) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.enter_item_generic");
                return;
            }
            
            // Check if player has enough tokens
            $tokenManager = $this->plugin->getTokenManager();
            if ($tokenManager->isEnabled()) {
                if (!$tokenManager->canUseToken($player)) {
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
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.request_already_active");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_active");
                return;
            }
            
            // Create the query
            $query = "How to craft " . $item . " in Minecraft";
            
            // Send processing notification
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "processing");
            
            // Store form context for async response
            $requestManager->setFormContext($player->getName(), [
                'type' => 'crafting_form',
                'question' => $query,
                'item' => $item,
                'tokenManager' => $this->plugin->getTokenManager()
            ]);
            
            // Process the query with async handling
            try {
                $this->plugin->getProviderManager()->processQuery($player, $query);
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("Crafting Helper processQuery error: " . $e->getMessage());
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.generation_failed");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_cancelled");
                return;
            }
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("crafting_form.title", "Crafting Helper");
        $content = $this->plugin->getFormSetting("crafting_form.content", "Enter the name of the item you want to craft:");
        $placeholder = $this->plugin->getFormSetting("crafting_form.placeholder", "Diamond Pickaxe");
        
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
        $form->addInput("§fItem Name:", "Example: diamond sword, iron pickaxe, crafting table");
        
        $form->sendToPlayer($player);
        
        // Send toast notification when form is opened
        $this->plugin->getMessageManager()->sendToastNotification($player, "info", $this->plugin->getMessageManager()->getConfigurableMessage("toasts.crafting.welcome_title"), $this->plugin->getMessageManager()->getConfigurableMessage("toasts.crafting.welcome_body"));
    }

    /**
     * Open the building calculator form
     * 
     * @param Player $player
     */
    private function openBuildingCalculatorForm(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                return;
            }
            
            // Get form data
            $length = (int) ($data[1] ?? 10);
            $width = (int) ($data[2] ?? 10);
            $height = (int) ($data[3] ?? 5);
            
            // Get building styles from config
            $styles = $this->plugin->getFormSetting("building_calculator_form.fields.style.options", ["Modern", "Medieval", "Futuristic", "Rustic", "Industrial"]);
            $style = $styles[$data[4] ?? 0];
            
            // Check if player has enough tokens
            $tokenManager = $this->plugin->getTokenManager();
            if ($tokenManager->isEnabled()) {
                if (!$tokenManager->canUseToken($player)) {
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
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.request_already_active");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_active");
                return;
            }
            
            // Create the query
            $query = "Calculate materials for a {$length}x{$width}x{$height} {$style} house in Minecraft";
            
            // Send processing notification  
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "processing");
            
            // Store form context for async response
            $requestManager->setFormContext($player->getName(), [
                'type' => 'building_form',
                'question' => $query,
                'dimensions' => "{$length}x{$width}x{$height}",
                'style' => $style,
                'tokenManager' => $this->plugin->getTokenManager()
            ]);
            
            // Process the query with async handling
            try {
                $this->plugin->getProviderManager()->processQuery($player, $query);
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("Building Calculator processQuery error: " . $e->getMessage());
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.generation_failed");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_cancelled");
                return;
            }
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("building_calculator_form.title", "Building Calculator");
        $content = $this->plugin->getFormSetting("building_calculator_form.content", "Calculate materials needed for your building project:");
        
        // Get field labels and defaults from config
        $lengthLabel = $this->plugin->getFormSetting("building_calculator_form.fields.length.label", "Length");
        $lengthDefault = $this->plugin->getFormSetting("building_calculator_form.fields.length.default", "10");
        $lengthPlaceholder = $this->plugin->getFormSetting("building_calculator_form.fields.length.placeholder", "10");
        
        $widthLabel = $this->plugin->getFormSetting("building_calculator_form.fields.width.label", "Width");
        $widthDefault = $this->plugin->getFormSetting("building_calculator_form.fields.width.default", "10");
        $widthPlaceholder = $this->plugin->getFormSetting("building_calculator_form.fields.width.placeholder", "10");
        
        $heightLabel = $this->plugin->getFormSetting("building_calculator_form.fields.height.label", "Height");
        $heightDefault = $this->plugin->getFormSetting("building_calculator_form.fields.height.default", "5");
        $heightPlaceholder = $this->plugin->getFormSetting("building_calculator_form.fields.height.placeholder", "5");
        
        $styleLabel = $this->plugin->getFormSetting("building_calculator_form.fields.style.label", "Building Style");
        $styleOptions = $this->plugin->getFormSetting("building_calculator_form.fields.style.options", ["Modern", "Medieval", "Futuristic", "Rustic", "Industrial"]);
        
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
        
        // Add input fields
        $form->addInput(TextFormat::colorize("&f" . $lengthLabel . ":"), $lengthPlaceholder, $lengthDefault);
        $form->addInput(TextFormat::colorize("&f" . $widthLabel . ":"), $widthPlaceholder, $widthDefault);
        $form->addInput(TextFormat::colorize("&f" . $heightLabel . ":"), $heightPlaceholder, $heightDefault);
        $form->addDropdown(TextFormat::colorize("&f" . $styleLabel . ":"), $styleOptions);
        
        $form->sendToPlayer($player);
        
        // Send toast notification when form is opened
        $this->plugin->getMessageManager()->sendToastNotification($player, "info", $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.welcome_title"), $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.welcome_body"));
    }
}