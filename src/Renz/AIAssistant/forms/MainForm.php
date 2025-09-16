<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use Renz\AIAssistant\libs\FormAPI\SimpleForm;
use Renz\AIAssistant\libs\FormAPI\CustomForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;

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
        // Check if "View Response" button should be shown and build dynamic button mapping
        $requestManager = $this->plugin->getRequestManager();
        $hasReadyResponse = $requestManager->hasReadyResponse($player->getName());
        $viewResponseEnabled = $this->plugin->getConfig()->getNested("advanced.view_response_button.enabled", true);
        $showViewResponseButton = $hasReadyResponse && $viewResponseEnabled;
        
        // Build button mapping - adjust indices based on whether View Response is shown
        $buttonMap = [];
        $buttonIndex = 0;
        
        // View Response button (if enabled and has ready response) - show first for visibility
        if ($showViewResponseButton) {
            $buttonMap[$buttonIndex++] = 'view_response';
        }
        
        // Standard buttons
        $buttonMap[$buttonIndex++] = 'chat';          // Chat with AI
        $buttonMap[$buttonIndex++] = 'history';       // View Chat History  
        $buttonMap[$buttonIndex++] = 'crafting';      // Crafting Helper
        $buttonMap[$buttonIndex++] = 'building';      // Building Calculator
        $buttonMap[$buttonIndex++] = 'token_shop';    // Token Shop
        $buttonMap[$buttonIndex++] = 'server_info';   // Server Info

        $form = new SimpleForm(function(Player $player, ?int $data) use (&$buttonMap) {
            if ($data === null) {
                return;
            }

            $action = $buttonMap[$data] ?? 'unknown';
            
            switch ($action) {
                case 'view_response':
                    $this->viewReadyResponse($player);
                    break;
                    
                case 'chat':
                    $this->openChatForm($player);
                    break;

                case 'history':
                    $historyForm = new HistoryForm($this->plugin);
                    $historyForm->sendTo($player);
                    break;

                case 'crafting':
                    $this->openCraftingForm($player);
                    break;

                case 'building':
                    $this->openBuildingForm($player);
                    break;

                case 'token_shop':
                    $tokenShopForm = new TokenShopForm($this->plugin);
                    $tokenShopForm->sendTo($player);
                    break;

                case 'server_info':
                    $serverInfoForm = new ServerInfoForm($this->plugin);
                    $serverInfoForm->sendTo($player);
                    break;
            }
        });

        // Get form settings from config
        $title = $this->plugin->getFormSetting("main_form.title", "AI Assistant");
        $content = $this->plugin->getFormSetting("main_form.content", "Welcome to the AI Assistant! What would you like to do?");

        // Format title with proper colors
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);

        $form->setTitle($title);
        $form->setContent($content);

        // Add buttons dynamically based on mapping
        // View Response button (if applicable)
        if ($showViewResponseButton) {
            $viewResponseText = $this->plugin->getFormSetting("main_form.buttons.view_response.text", "View Response");
            $viewResponseColor = $this->plugin->getFormSetting("main_form.buttons.view_response.color", "&d");
            $viewResponseTexture = $this->plugin->getFormSetting("main_form.buttons.view_response.texture", "textures/ui/check");
            $form->addButton($this->plugin->formatFormText($viewResponseColor . $viewResponseText), 0, $viewResponseTexture);
            
            // Send toast notification for view response
            $this->plugin->getMessageManager()->sendToastNotification(
                $player,
                "info",
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.main_menu.view_response_title"),
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.main_menu.view_response_body")
            );
        }

        // Chat with AI button
        $chatText = $this->plugin->getFormSetting("main_form.buttons.chat.text", "Chat with AI");
        $chatColor = $this->plugin->getFormSetting("main_form.buttons.chat.color", "&a");
        $chatTexture = $this->plugin->getFormSetting("main_form.buttons.chat.texture", "textures/ui/chat_icon");
        $form->addButton($this->plugin->formatFormText($chatColor . $chatText), 0, $chatTexture);

        // History button
        $historyText = $this->plugin->getFormSetting("main_form.buttons.history.text", "View Chat History");
        $historyColor = $this->plugin->getFormSetting("main_form.buttons.history.color", "&d");
        $historyTexture = $this->plugin->getFormSetting("main_form.buttons.history.texture", "textures/ui/history_icon");
        $form->addButton($this->plugin->formatFormText($historyColor . $historyText), 0, $historyTexture);

        // Crafting Helper button
        $craftingText = $this->plugin->getFormSetting("main_form.buttons.crafting.text", "Crafting Helper");
        $craftingColor = $this->plugin->getFormSetting("main_form.buttons.crafting.color", "&6");
        $craftingTexture = $this->plugin->getFormSetting("main_form.buttons.crafting.texture", "textures/ui/crafting_icon");
        $form->addButton($this->plugin->formatFormText($craftingColor . $craftingText), 0, $craftingTexture);

        // Building Calculator button
        $buildingText = $this->plugin->getFormSetting("main_form.buttons.building.text", "Building Calculator");
        $buildingColor = $this->plugin->getFormSetting("main_form.buttons.building.color", "&e");
        $buildingTexture = $this->plugin->getFormSetting("main_form.buttons.building.texture", "textures/ui/building_icon");
        $form->addButton($this->plugin->formatFormText($buildingColor . $buildingText), 0, $buildingTexture);

        // Token Shop button
        $tokenShopText = $this->plugin->getFormSetting("main_form.buttons.token_shop.text", "Token Shop");
        $tokenShopColor = $this->plugin->getFormSetting("main_form.buttons.token_shop.color", "&6");
        $tokenShopTexture = $this->plugin->getFormSetting("main_form.buttons.token_shop.texture", "textures/ui/token_icon");
        $form->addButton($this->plugin->formatFormText($tokenShopColor . $tokenShopText), 0, $tokenShopTexture);

        // Server Info button
        $serverInfoText = $this->plugin->getFormSetting("main_form.buttons.server_info.text", "Server Info");
        $serverInfoColor = $this->plugin->getFormSetting("main_form.buttons.server_info.color", "&9");
        $serverInfoTexture = $this->plugin->getFormSetting("main_form.buttons.server_info.texture", "textures/ui/info_icon");
        $form->addButton($this->plugin->formatFormText($serverInfoColor . $serverInfoText), 0, $serverInfoTexture);

        $form->sendToPlayer($player);
        
        // Send toast notification
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.main_menu.title"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.main_menu.body")
        );
    }

    /**
     * Open the chat form for a player
     * 
     * @param Player $player
     */
    public function openChatForm(Player $player): void {
        // Check if token system is enabled and player has tokens
        $tokenManager = $this->plugin->getTokenManager();
        if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
            $this->plugin->getMessageManager()->sendToastNotification(
                $player,
                "error",
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.no_tokens.title"),
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.no_tokens.body")
            );
            return;
        }

        // Check if player already has an active request
        $requestManager = $this->plugin->getProviderManager()->getRequestManager();
        if ($requestManager->hasActiveRequest($player->getName())) {
            $this->plugin->getMessageManager()->sendToastNotification(
                $player,
                "error",
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.request_active.title"),
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.request_active.body")
            );
            return;
        }

        // Create the chat form
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                // Form closed
                return;
            }

            // Get the question from the form data - always the first element
            $question = trim($data[0] ?? "");
            if (empty($question)) {
                $this->plugin->getMessageManager()->sendToastNotification(
                    $player,
                    "error",
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_title"),
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_body")
                );
                return;
            }

            // Check if token system is enabled and player has tokens
            $tokenManager = $this->plugin->getTokenManager();
            if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.no_tokens_purchase");
                return;
            }

            // Check if player already has an active request
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            if ($requestManager->hasActiveRequest($player->getName())) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.request_already_active");
                return;
            }

            // Set form context for the request
            $requestManager->setFormContext($player->getName(), [
                'type' => 'chat_form',
                'question' => $question,
                'tokenManager' => $tokenManager
            ]);

            // Process the query
            try {
                $this->plugin->getProviderManager()->processQuery($player, $question);
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("Error in openChatForm for player " . $player->getName() . ": " . $e->getMessage());
                $errorMessage = $this->getErrorMessage($e);
                $player->sendMessage($errorMessage);
            }
        });

        // Get form settings from config
        $title = $this->plugin->getFormSetting("chat_form.title", "Chat with AI");
        $content = $this->plugin->getFormSetting("chat_form.content", "What would you like to ask the AI Assistant?");
        $placeholder = $this->plugin->getFormSetting("chat_form.placeholder", "Type your question here...");

        // Format title with proper colors
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);

        $form->setTitle($title);
        $form->addInput($content, $placeholder);
        
        $form->sendToPlayer($player);

        // Send toast notification
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_title"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_body")
        );
    }

    /**
 * Open the crafting form for a player
 * 
 * @param Player $player
 */
    private function openCraftingForm(Player $player): void {
    // Check if token system is enabled and player has tokens
    $tokenManager = $this->plugin->getTokenManager();
    if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "error",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.no_tokens.title"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.no_tokens.body")
        );
        return;
    }

    // Create the crafting form
    $form = new CustomForm(function(Player $player, ?array $data) {
        if ($data === null) {
            // Form closed
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.crafting_lookup_cancelled");
            return;
        }

        // Get the item name from the form data
        $itemName = trim($data[0] ?? "");
        if (empty($itemName)) {
            $this->plugin->getMessageManager()->sendToastNotification(
                $player,
                "error",
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.crafting.no_item_title"),
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.crafting.no_item_body")
            );
            return;
        }

        // Check if token system is enabled and player has tokens
        $tokenManager = $this->plugin->getTokenManager();
        if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.no_tokens_purchase");
            return;
        }

        // Construct the crafting query
        $query = "How do I craft a {$itemName} in Minecraft? Please provide the exact recipe with ingredients and pattern.";

        // Set form context for the request
        $requestManager = $this->plugin->getProviderManager()->getRequestManager();
        $requestManager->setFormContext($player->getName(), [
            'type' => 'crafting_form',
            'item' => $itemName,
            'question' => $query,
            'tokenManager' => $tokenManager
        ]);

        // --- SAFELY RESOLVE "looking up" TOAST (use notifications path + fallback) ---
        $mm = $this->plugin->getMessageManager();

        // Try preferred path first (matches your YAML: notifications.specific.looking_up)
        $lookingUpTitle = $mm->getConfigurableMessage("notifications.specific.looking_up.title");
        $lookingUpBody  = $mm->getConfigurableMessage("notifications.specific.looking_up.body", ["item" => $itemName]);

        // Fallbacks: older config might use toasts.specific.*
        if (empty($lookingUpTitle)) {
            $lookingUpTitle = $mm->getConfigurableMessage("toasts.specific.looking_up.title");
        }
        if (empty($lookingUpBody)) {
            $lookingUpBody = $mm->getConfigurableMessage("toasts.specific.looking_up.body", ["item" => $itemName]);
        }

        // Final fallback values to avoid undefined
        if (empty($lookingUpTitle)) {
            $lookingUpTitle = "§l§bLooking Up";
        }
        if (empty($lookingUpBody)) {
            $lookingUpBody = "§fFinding crafting recipe for {$itemName}";
        }

        // Send looking up notification
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $lookingUpTitle,
            $lookingUpBody
        );

        // Process the query
        try {
            $this->plugin->getProviderManager()->processQuery($player, $query);
        } catch (\Throwable $e) {
            $this->plugin->getLogger()->error("Error in openCraftingForm for player " . $player->getName() . ": " . $e->getMessage());
            $errorMessage = $this->getErrorMessage($e);
            $player->sendMessage($errorMessage);
        }
    });

    // Get form settings
    $title = $this->plugin->getFormSetting("crafting_form.title", "Crafting Helper");
    $content = $this->plugin->getFormSetting("crafting_form.content", "Enter the name of the item you want to craft:");
    $placeholder = $this->plugin->getFormSetting("crafting_form.placeholder", "e.g., diamond sword, crafting table, etc.");

    // Format title with proper colors
    $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
    $title = $this->plugin->formatFormText($titleFormat . $title);

    $form->setTitle($title);
    $form->addInput($content, $placeholder);
    
    $form->sendToPlayer($player);

    // Send toast notification (welcome)
    $this->plugin->getMessageManager()->sendToastNotification(
        $player,
        "info",
        $this->plugin->getMessageManager()->getConfigurableMessage("toasts.crafting.welcome_title"),
        $this->plugin->getMessageManager()->getConfigurableMessage("toasts.crafting.welcome_body")
    );
}

    /**
     * Open the building calculator form for a player
     * 
     * @param Player $player
     */
    private function openBuildingForm(Player $player): void {
        // Check if token system is enabled and player has tokens
        $tokenManager = $this->plugin->getTokenManager();
        if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
            $this->plugin->getMessageManager()->sendToastNotification(
                $player,
                "error",
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.no_tokens.title"),
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.presets.no_tokens.body")
            );
            return;
        }

        // Create the building calculator form
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                // Form closed
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.building_calc_cancelled");
                return;
            }

            // Get the dimensions from the form data
            $width = (int)($data[0] ?? 0);
            $height = (int)($data[1] ?? 0);
            $depth = (int)($data[2] ?? 0);
            $material = trim($data[3] ?? "");
            $themeIndex = (int)($data[4] ?? 0);
            
            // Get the selected theme
            $themes = [
                "Modern", "Cyberpunk Japanese", "Medieval", "Fantasy", "Rustic", "Industrial", "Futuristic", "None"
            ];
            $selectedTheme = $themes[$themeIndex] ?? "None";

            if ($width <= 0 || $height <= 0 || $depth <= 0 || empty($material)) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.invalid_dimensions");
                return;
            }

            // Check if token system is enabled and player has tokens
            $tokenManager = $this->plugin->getTokenManager();
            if ($tokenManager->isEnabled() && !$tokenManager->canUseToken($player)) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.no_tokens_purchase");
                return;
            }

            // Construct the building query with theme
            $query = "I want to build a structure in Minecraft with these dimensions: width = {$width}, height = {$height}, depth = {$depth}. " .
                     "I want to use {$material} as the main material. ";
                     
            // Add theme-specific instructions
            if ($selectedTheme !== "None") {
                $query .= "I want to build in the {$selectedTheme} style/theme. ";
                
                // Send theme selected notification
                $this->plugin->getMessageManager()->sendToastNotification(
                    $player,
                    "info",
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.theme_selected_title"),
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.theme_selected_body", ["theme" => $selectedTheme])
                );
            }
            
            // Complete the query
            $query .= "How many blocks do I need? " .
                     "Please calculate the total blocks needed for walls, floor, and ceiling. " .
                     "Also suggest specific blocks and decorations that would fit the " . ($selectedTheme !== "None" ? $selectedTheme . " theme" : "build") . ".";

            // Set form context for the request
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            $requestManager->setFormContext($player->getName(), [
                'type' => 'building_form',
                'dimensions' => [
                    'width' => $width,
                    'height' => $height,
                    'depth' => $depth
                ],
                'material' => $material,
                'theme' => $selectedTheme,
                'question' => $query,
                'tokenManager' => $tokenManager
            ]);

            // Process the query
            try {
                $this->plugin->getProviderManager()->processQuery($player, $query);
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("Error in openBuildingForm for player " . $player->getName() . ": " . $e->getMessage());
                $errorMessage = $this->getErrorMessage($e);
                $player->sendMessage($errorMessage);
            }
        });

        // Get form settings
        $title = $this->plugin->getFormSetting("building_form.title", "Building Calculator");
        $widthLabel = $this->plugin->getFormSetting("building_form.width_label", "Width (blocks):");
        $heightLabel = $this->plugin->getFormSetting("building_form.height_label", "Height (blocks):");
        $depthLabel = $this->plugin->getFormSetting("building_form.depth_label", "Depth (blocks):");
        $materialLabel = $this->plugin->getFormSetting("building_form.material_label", "Main Material:");
        $themeLabel = $this->plugin->getFormSetting("building_form.theme_label", "Building Theme:");
        
        $widthPlaceholder = $this->plugin->getFormSetting("building_form.width_placeholder", "e.g., 10");
        $heightPlaceholder = $this->plugin->getFormSetting("building_form.height_placeholder", "e.g., 5");
        $depthPlaceholder = $this->plugin->getFormSetting("building_form.depth_placeholder", "e.g., 8");
        $materialPlaceholder = $this->plugin->getFormSetting("building_form.material_placeholder", "e.g., stone, wood, etc.");

        // Format title with proper colors
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);

        // Set form title and content
        $form->setTitle($title);
        $form->addInput($widthLabel, $widthPlaceholder);
        $form->addInput($heightLabel, $heightPlaceholder);
        $form->addInput($depthLabel, $depthPlaceholder);
        $form->addInput($materialLabel, $materialPlaceholder);
        
        // Add theme dropdown
        $themes = [
            "Modern", "Cyberpunk Japanese", "Medieval", "Fantasy", "Rustic", "Industrial", "Futuristic", "None"
        ];
        $form->addDropdown($themeLabel, $themes);
        
        $form->sendToPlayer($player);

        // Send toast notification
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.welcome_title"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.building.welcome_body")
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

    /**
     * View a ready AI response for the player
     * 
     * @param Player $player
     */
    private function viewReadyResponse(Player $player): void {
        $requestManager = $this->plugin->getRequestManager();
        $readyResponse = $requestManager->consumeReadyResponse($player->getName());
        
        if ($readyResponse !== null) {
            $question = $readyResponse['question'];
            $response = $readyResponse['response'];
            
            // Show the response in a ResponseForm
            $responseForm = new ResponseForm($this->plugin);
            $responseForm->sendTo($player, $question, $response);
            
            // Send toast notification
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "response_ready");
        } else {
            // No ready response found - this shouldn't happen but handle gracefully
            $this->plugin->getMessageManager()->sendToastNotification(
                $player,
                "error",
                $this->plugin->getMessageManager()->getConfigurableMessage("toasts.defaults.title"),
                "No response available to view."
            );
            
            // Return to main menu
            $this->sendTo($player);
        }
    }
}
