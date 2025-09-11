<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;

class BuildingCalculatorForm {
    /** @var Main */
    private Main $plugin;

    /**
     * BuildingCalculatorForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the building calculator form to a player
     * 
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                return;
            }
            
            // Get form data
            $length = (int) ($data[0] ?? 10);
            $width = (int) ($data[1] ?? 10);
            $height = (int) ($data[2] ?? 5);
            
            // Get building styles from config
            $styles = $this->plugin->getFormSetting("building_calculator_form.fields.style.options", ["Modern", "Medieval", "Futuristic", "Rustic", "Industrial"]);
            $style = $styles[$data[3] ?? 0];
            
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
            
            // Create the query
            $query = "Calculate materials for a {$length}x{$width}x{$height} {$style} house in Minecraft";
            
            // Show loading form
            $loadingTitle = $this->plugin->getMessageManager()->getConfigurableMessage("loading.titles.building_calculation");
            $loadingForm = new LoadingForm($this->plugin, $player, $query, $loadingTitle);
            $loadingForm->show(function() use ($player) {
                // This is called when the loading form is cancelled
                $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.building_calc_cancelled");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_cancelled");
            });
            
            // Send toast notification
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "looking_up", ["item" => "materials for {$length}x{$width}x{$height} {$style} house"]);
            
            // Process the building calculation query in a separate task
            $this->plugin->getScheduler()->scheduleTask(new ClosureTask(
                function() use ($player, $query, $loadingForm, $length, $width, $height, $style): void {
                    // Check if the player is still online
                    if (!$player->isOnline()) {
                        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
                        return;
                    }
                    
                    // Check if the loading form was cancelled
                    if ($loadingForm->isCancelled()) {
                        return;
                    }
                    
                    // Process the query
                    $response = $this->plugin->getProviderManager()->processQuery($player, $query);
                    
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
                    $this->plugin->getMessageManager()->sendToastNotification($player, "success", $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.calculation_complete_title"), $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.calculation_complete_body"));
                    
                    // Show the response
                    $form = new ResponseForm($this->plugin);
                    $form->sendTo($player, $query, $response);
                }
            ));
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