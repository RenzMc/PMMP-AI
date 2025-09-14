<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;

class TokenShopForm {
    /** @var Main */
    private Main $plugin;

    /**
     * TokenShopForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the token shop form to a player
     * 
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        // Check if token system is enabled
        if (!$this->plugin->getConfig()->getNested("tokens.enabled", true)) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.system_disabled");
            $this->plugin->getMessageManager()->sendToastNotification($player, "error", null, $this->plugin->getMessageManager()->getConfigurableMessage("toasts.token_shop.system_disabled_body"));
            return;
        }
        
        // Check if economy plugin is available
        if (!$this->plugin->getEconomyManager()->isEconomyAvailable()) {
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.economy_not_found");
            $this->plugin->getMessageManager()->sendToastNotification($player, "error", null, $this->plugin->getMessageManager()->getConfigurableMessage("toasts.token_shop.economy_not_found_body"));
            return;
        }
        
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            if ($data === 0) {
                // Back button pressed
                $form = new MainForm($this->plugin);
                $form->sendTo($player);
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "main_menu");
                return;
            }
            
            // Adjust index to account for the back button
            $packageIndex = $data - 1;
            
            // Get token packages
            $packages = $this->getTokenPackages();
            if (!isset($packages[$packageIndex])) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.package_not_found");
                $this->plugin->getMessageManager()->sendToastNotification($player, "error", null, $this->plugin->getMessageManager()->getConfigurableMessage("toasts.token_shop.package_not_found_body"));
                return;
            }
            
            $package = $packages[$packageIndex];
            
            // Check if player has enough money
            $economyManager = $this->plugin->getEconomyManager();
            $playerMoney = $economyManager->getPlayerMoney($player);
            
            if ($playerMoney < $package['price']) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.insufficient_funds");
                $this->plugin->getMessageManager()->sendToastNotification($player, "error", $this->plugin->getMessageManager()->getConfigurableMessage("toasts.token_shop.insufficient_funds_title"), $this->plugin->getMessageManager()->getConfigurableMessage("toasts.token_shop.insufficient_funds_body"));
                return;
            }
            
            // Purchase tokens
            $economyManager->reduceMoney($player, $package['price']);
            $this->plugin->getTokenManager()->addTokens($player->getName(), $package['tokens']);
            
            $currencySymbol = $this->plugin->getConfig()->getNested("tokens.currency_symbol", "$");
            $this->plugin->getMessageManager()->sendConfigurableMessage($player, "tokens.purchase_success", [
                "tokens" => $package['tokens'],
                "currency" => $currencySymbol,
                "price" => $package['price']
            ]);
            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "purchase_complete", ["tokens" => $package['tokens']]);
            
            // Refresh token shop form
            $this->sendTo($player);
        });
        
        // Get form title and content
        $title = $this->plugin->getFormSetting("token_shop_form.title", "Token Shop");
        $content = $this->plugin->getFormSetting("token_shop_form.content", "Purchase AI tokens to use the AI Assistant:");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Set form title
        $form->setTitle($title);
        
        // Get player money
        $economyManager = $this->plugin->getEconomyManager();
        $playerMoney = $economyManager->getPlayerMoney($player);
        $currencySymbol = $this->plugin->getConfig()->getNested("tokens.currency_symbol", "$");
        
        // Get token status
        $tokenManager = $this->plugin->getTokenManager();
        $tokenStatus = $tokenManager->getTokenStatusMessage($player->getName());
        
        // Format content
        $highlightFormat = $this->plugin->getFormSetting("general.text_formatting.highlight", "&e");
        $balanceFormat = $this->plugin->getFormSetting("general.text_formatting.balance", "&b");
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
        
        $content = TextFormat::colorize($highlightFormat . $tokenStatus . "\n\n" . 
                  $balanceFormat . "Your Balance: " . $currencySymbol . $playerMoney . "\n\n" .
                  $contentFormat . $content);
        
        $form->setContent($content);
        
        // Add back button
        $backText = $this->plugin->getFormSetting("token_shop_form.buttons.back.text", "Back");
        $backColor = $this->plugin->getFormSetting("token_shop_form.buttons.back.color", "&7");
        $backTexture = $this->plugin->getFormSetting("token_shop_form.buttons.back.texture", "textures/ui/arrow_left");
        
        $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
        
        // Add package buttons
        $packages = $this->getTokenPackages();
        $buyText = $this->plugin->getFormSetting("token_shop_form.buttons.buy.text", "Buy");
        $buyColor = $this->plugin->getFormSetting("token_shop_form.buttons.buy.color", "&a");
        $buyTexture = $this->plugin->getFormSetting("token_shop_form.buttons.buy.texture", "textures/ui/check");
        
        foreach ($packages as $package) {
            $buttonText = $this->plugin->formatFormText($buyColor . $buyText . ": " . $package['name'] . " - " . $package['tokens'] . " tokens for " . $currencySymbol . $package['price']);
            $form->addButton($buttonText, 0, $buyTexture);
        }
        
        $form->sendToPlayer($player);
        
        // Send toast notification when token shop is opened
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.token_shop.welcome_title"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.token_shop.welcome_body")
        );
    }
    
    /**
     * Get token packages from config
     * 
     * @return array
     */
    private function getTokenPackages(): array {
        $packages = $this->plugin->getConfig()->getNested("tokens.token_packages", []);
        
        // Add single token package
        $singleTokenPrice = $this->plugin->getConfig()->getNested("tokens.token_price", 100);
        $packages = array_merge([
            [
                'name' => "Single Token",
                'tokens' => 1,
                'price' => $singleTokenPrice
            ]
        ], $packages);
        
        return $packages;
    }
}