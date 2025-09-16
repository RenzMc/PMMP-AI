<?php  
  
declare(strict_types=1);  
  
namespace Renz\AIAssistant\economy;  
  
use pocketmine\player\Player;  
use pocketmine\plugin\Plugin;  
use Renz\AIAssistant\Main;  
use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;  
use cooldogedev\BedrockEconomy\api\type\ClosureAPI;  
use onebone\coinapi\CoinAPI;  
  
class EconomyManager {  
    /** @var Main */  
    private Main $plugin;  
      
    /** @var string */  
    private string $provider;  
      
    /** @var Plugin|null */  
    private ?Plugin $economyPlugin = null;  
      
    /** @var string */  
    private string $currencySymbol;  
  
    /**  
     * EconomyManager constructor.  
     *   
     * @param Main $plugin  
     */  
    public function __construct(Main $plugin) {  
        $this->plugin = $plugin;  
        $config = $plugin->getConfig();  
        $this->provider = strtolower($config->getNested("tokens.economy_provider", "economyapi"));  
        $this->currencySymbol = $config->getNested("tokens.currency_symbol", "$");  
          
        $this->initializeEconomyProvider();  
    }  
  
    /**  
     * Initialize the economy provider  
     */  
    private function initializeEconomyProvider(): void {  
        $server = $this->plugin->getServer();  
        $pluginManager = $server->getPluginManager();  
          
        switch ($this->provider) {  
            case "economyapi":  
                $this->economyPlugin = $pluginManager->getPlugin("EconomyAPI");  
                if ($this->economyPlugin === null) {  
                    $this->plugin->getLogger()->warning("EconomyAPI plugin not found. Token purchases will be disabled.");  
                }  
                break;  
                  
            case "bedrockeconomy":  
                $this->economyPlugin = $pluginManager->getPlugin("BedrockEconomy");  
                if ($this->economyPlugin === null) {  
                    $this->plugin->getLogger()->warning("BedrockEconomy plugin not found. Token purchases will be disabled.");  
                }  
                break;  
                  
            case "coinapi":  
                $this->economyPlugin = $pluginManager->getPlugin("CoinAPI");  
                if ($this->economyPlugin === null) {  
                    $this->plugin->getLogger()->warning("CoinAPI plugin not found. Token purchases will be disabled.");  
                }  
                break;  
                  
            default:  
                $this->plugin->getLogger()->warning("Unknown economy provider: {$this->provider}. Token purchases will be disabled.");  
                break;  
        }  
    }  
  
    /**  
     * Check if the economy provider is available  
     *   
     * @return bool  
     */  
    public function isEconomyAvailable(): bool {  
        return $this->economyPlugin !== null;  
    }  
  
    /**  
     * Get player's money  
     *   
     * @param Player $player  
     * @return float  
     */  
    public function getPlayerMoney(Player $player): float {  
        if (!$this->isEconomyAvailable()) {  
            return 0.0;  
        }  
          
        try {  
            switch ($this->provider) {  
                case "economyapi":  
                    return (float) $this->economyPlugin->myMoney($player);  
                      
                case "bedrockeconomy":  
                    // BedrockEconomy uses async API, so we need to use a synchronous approach  
                    // This is not ideal, but it's the best we can do without rewriting the entire plugin  
                    $playerName = $player->getName();  
                    $xuid = $player->getXuid();  
                      
                    // Try to use the legacy API first for compatibility  
                    if (method_exists(BedrockEconomyAPI::class, 'legacy')) {  
                        $balance = BedrockEconomyAPI::legacy()->getPlayerBalance($playerName);  
                        if ($balance !== null) {  
                            return (float) $balance;  
                        }  
                    }  
                      
                    // If legacy API fails, try to use reflection as a fallback  
                    try {  
                        $method = new \ReflectionMethod($this->economyPlugin, 'getAPI');  
                        $api = $method->invoke($this->economyPlugin);  
                          
                        if ($api !== null) {  
                            $method = new \ReflectionMethod($api, 'getPlayerBalance');  
                            if ($method !== null) {  
                                return (float) $method->invoke($api, $playerName);  
                            }  
                        }  
                    } catch (\Throwable $e) {  
                        $this->plugin->getLogger()->debug("Failed to get balance using reflection: " . $e->getMessage());  
                    }  
                      
                    return 0.0;  
                      
                case "coinapi":  
                    return (float) CoinAPI::getInstance()->myCoin($player->getName());  
                      
                default:  
                    return 0.0;  
            }  
        } catch (\Throwable $e) {  
            $this->plugin->getLogger()->error("Failed to get balance: " . $e->getMessage());  
            return 0.0;  
        }  
    }  
  
    /**  
     * Check if player has enough money  
     *   
     * @param Player $player  
     * @param float $amount  
     * @return bool  
     */  
    public function hasEnoughMoney(Player $player, float $amount): bool {  
        return $this->getPlayerMoney($player) >= $amount;  
    }  
  
    /**  
     * Reduce player's money  
     *   
     * @param Player $player  
     * @param float $amount  
     * @return bool  
     */  
    public function reduceMoney(Player $player, float $amount): bool {  
        if (!$this->isEconomyAvailable() || !$this->hasEnoughMoney($player, $amount)) {  
            return false;  
        }  
          
        try {  
            switch ($this->provider) {  
                case "economyapi":  
                    return $this->economyPlugin->reduceMoney($player, $amount) === 1;  
                      
                case "bedrockeconomy":  
                    $playerName = $player->getName();  
                    $xuid = $player->getXuid();  
                      
                    // Try to use the legacy API first for compatibility  
                    if (method_exists(BedrockEconomyAPI::class, 'legacy')) {  
                        $result = BedrockEconomyAPI::legacy()->subtractFromPlayerBalance($playerName, (int)$amount);  
                        if ($result) {  
                            return true;  
                        }  
                    }  
                      
                    // If legacy API fails, try to use the closure API  
                    if (method_exists(BedrockEconomyAPI::class, 'CLOSURE')) {  
                        BedrockEconomyAPI::CLOSURE()->subtract(  
                            $xuid,  
                            $playerName,  
                            (int)$amount,  
                            0,  
                            function() {  
                                // Success callback  
                            },  
                            function() {  
                                // Error callback  
                            }  
                        );  
                        return true;  
                    }  
                      
                    // If all else fails, try to use reflection as a fallback  
                    try {  
                        $method = new \ReflectionMethod($this->economyPlugin, 'getAPI');  
                        $api = $method->invoke($this->economyPlugin);  
                          
                        if ($api !== null) {  
                            $method = new \ReflectionMethod($api, 'subtractFromPlayerBalance');  
                            if ($method !== null) {  
                                $method->invoke($api, $playerName, (int)$amount);  
                                return true;  
                            }  
                        }  
                    } catch (\Throwable $e) {  
                        $this->plugin->getLogger()->debug("Failed to reduce balance using reflection: " . $e->getMessage());  
                    }  
                      
                    return false;  
                      
                case "coinapi":  
                    return CoinAPI::getInstance()->reduceCoin($player->getName(), $amount) === CoinAPI::RET_SUCCESS;  
                      
                default:  
                    return false;  
            }  
        } catch (\Throwable $e) {  
            $this->plugin->getLogger()->error("Failed to reduce balance: " . $e->getMessage());  
            return false;  
        }  
    }  
  
    /**  
     * Add money to player  
     *   
     * @param Player $player  
     * @param float $amount  
     * @return bool  
     */  
    public function addMoney(Player $player, float $amount): bool {  
        if (!$this->isEconomyAvailable()) {  
            return false;  
        }  
          
        try {  
            switch ($this->provider) {  
                case "economyapi":  
                    return $this->economyPlugin->addMoney($player, $amount) === 1;  
                      
                case "bedrockeconomy":  
                    $playerName = $player->getName();  
                    $xuid = $player->getXuid();  
                      
                    // Try to use the legacy API first for compatibility  
                    if (method_exists(BedrockEconomyAPI::class, 'legacy')) {  
                        $result = BedrockEconomyAPI::legacy()->addToPlayerBalance($playerName, (int)$amount);  
                        if ($result) {  
                            return true;  
                        }  
                    }  
                      
                    // If legacy API fails, try to use the closure API  
                    if (method_exists(BedrockEconomyAPI::class, 'CLOSURE')) {  
                        BedrockEconomyAPI::CLOSURE()->add(  
                            $xuid,  
                            $playerName,  
                            (int)$amount,  
                            0,  
                            function() {  
                                // Success callback  
                            },  
                            function() {  
                                // Error callback  
                            }  
                        );  
                        return true;  
                    }  
                      
                    // If all else fails, try to use reflection as a fallback  
                    try {  
                        $method = new \ReflectionMethod($this->economyPlugin, 'getAPI');  
                        $api = $method->invoke($this->economyPlugin);  
                          
                        if ($api !== null) {  
                            $method = new \ReflectionMethod($api, 'addToPlayerBalance');  
                            if ($method !== null) {  
                                $method->invoke($api, $playerName, (int)$amount);  
                                return true;  
                            }  
                        }  
                    } catch (\Throwable $e) {  
                        $this->plugin->getLogger()->debug("Failed to add balance using reflection: " . $e->getMessage());  
                    }  
                      
                    return false;  
                      
                case "coinapi":  
                    return CoinAPI::getInstance()->addCoin($player->getName(), $amount) === CoinAPI::RET_SUCCESS;  
                      
                default:  
                    return false;  
            }  
        } catch (\Throwable $e) {  
            $this->plugin->getLogger()->error("Failed to add balance: " . $e->getMessage());  
            return false;  
        }  
    }  
  
    /**  
     * Format money amount with currency symbol  
     *   
     * @param float $amount  
     * @return string  
     */  
    public function formatMoney(float $amount): string {  
        return $this->currencySymbol . number_format($amount, 2);  
    }  
  
    /**  
     * Get the economy provider name  
     *   
     * @return string  
     */  
    public function getProviderName(): string {  
        return $this->provider;  
    }  
  
    /**  
     * Get the currency symbol  
     *   
     * @return string  
     */  
    public function getCurrencySymbol(): string {  
        return $this->currencySymbol;  
    }  
}
