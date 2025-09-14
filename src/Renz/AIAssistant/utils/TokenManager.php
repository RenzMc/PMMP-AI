<?php

declare(strict_types=1);

namespace Renz\AIAssistant\utils;

use pocketmine\player\Player;
use pocketmine\utils\Config;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\economy\EconomyManager;

class TokenManager {
    /** @var Main */
    private Main $plugin;
    
    /** @var Config */
    private Config $tokenData;
    
    /** @var Config */
    private Config $usageData;
    
    /** @var int */
    private int $freeDailyTokens;
    
    /** @var bool */
    private bool $enabled;

    /**
     * TokenManager constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->enabled = (bool) $plugin->getConfig()->getNested("tokens.enabled", true);
        $this->freeDailyTokens = (int) $plugin->getConfig()->getNested("tokens.free_daily_tokens", 3);
        
        // Create data directory if it doesn't exist - Fixed: Better directory handling
        $tokensDir = $plugin->getDataFolder() . "tokens/";
        if (!is_dir($tokensDir)) {
            // Use recursive directory creation with proper error handling
            if (!@mkdir($tokensDir, 0755, true) && !is_dir($tokensDir)) {
                $plugin->getLogger()->error("Failed to create tokens directory: " . $tokensDir);
                // Fallback to main plugin directory
                $tokensDir = $plugin->getDataFolder();
                $plugin->getLogger()->info("Using fallback directory for token data: " . $tokensDir);
            } else {
                // Set proper permissions after creation
                @chmod($tokensDir, 0755);
            }
        }
        
        // Initialize token data storage with default values
        $this->tokenData = new Config($tokensDir . "token_data.yml", Config::YAML, ["players" => []]);
        
        // Initialize usage data storage with default values
        $this->usageData = new Config($tokensDir . "usage_data.yml", Config::YAML, ["usage" => []]);
        
        // Load token data
        $this->loadTokenData();
    }

    /**
     * Load token data from storage
     */
    private function loadTokenData(): void {
        if (!$this->tokenData->exists("players")) {
            $this->tokenData->set("players", []);
            $this->tokenData->save();
        }
        
        if (!$this->usageData->exists("usage")) {
            $this->usageData->set("usage", []);
            $this->usageData->save();
        }
    }

    /**
     * Get player token data
     * 
     * @param string $playerName
     * @return array
     */
    private function getPlayerData(string $playerName): array {
        $players = $this->tokenData->get("players", []);
        
        if (!isset($players[$playerName])) {
            $players[$playerName] = [
                "tokens" => 0,
                "free_tokens_used_today" => 0,
                "last_reset_date" => date("Y-m-d")
            ];
            
            $this->tokenData->set("players", $players);
            $this->tokenData->save();
        }
        
        // Check if we need to reset daily free tokens
        $currentDate = date("Y-m-d");
        if ($players[$playerName]["last_reset_date"] !== $currentDate) {
            $players[$playerName]["free_tokens_used_today"] = 0;
            $players[$playerName]["last_reset_date"] = $currentDate;
            
            $this->tokenData->set("players", $players);
            $this->tokenData->save();
        }
        
        return $players[$playerName];
    }

    /**
     * Save player token data
     * 
     * @param string $playerName
     * @param array $data
     * @return bool Success status
     */
    private function savePlayerData(string $playerName, array $data): bool {
        try {
            // Get current players data
            $players = $this->tokenData->get("players", []);
            $players[$playerName] = $data;
            
            // Update the data in memory
            $this->tokenData->set("players", $players);
            
            // Ensure the directory exists before saving
            $tokenDir = dirname($this->tokenData->getPath());
            if (!is_dir($tokenDir)) {
                if (!@mkdir($tokenDir, 0777, true) && !is_dir($tokenDir)) {
                    $this->plugin->getLogger()->error("Failed to create token data directory: " . $tokenDir);
                    
                    // Try to use a fallback directory in the main plugin folder
                    $fallbackDir = $this->plugin->getDataFolder();
                    $this->plugin->getLogger()->info("Using fallback directory for token data: " . $fallbackDir);
                    
                    // Create a new Config object with the fallback path
                    $fallbackPath = $fallbackDir . "token_data.yml";
                    $this->tokenData = new Config($fallbackPath, Config::YAML, ["players" => $players]);
                }
            }
            
            // Double check file permissions - Fixed: Better permission handling
            $filePath = $this->tokenData->getPath();
            $dirPath = dirname($filePath);
            
            // Ensure directory is writable with proper permissions
            if (!is_writable($dirPath)) {
                @chmod($dirPath, 0755);
                if (!is_writable($dirPath)) {
                    $this->plugin->getLogger()->warning("Token directory is not writable: " . $dirPath);
                }
            }
            
            // Ensure the file itself is writable if it exists
            if (file_exists($filePath) && !is_writable($filePath)) {
                @chmod($filePath, 0644);
            }
            
            // Save with proper error handling - Fixed: Config::save() returns void!
            try {
                $this->tokenData->save();
                return true;
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("Failed to save token data for player: " . $playerName . " - " . $e->getMessage());
                
                // Try to save to a different location as last resort
                try {
                    $emergencyPath = $this->plugin->getDataFolder() . "emergency_token_data.yml";
                    $emergencyConfig = new Config($emergencyPath, Config::YAML);
                    $emergencyConfig->setAll(["players" => $players]);
                    $emergencyConfig->save();
                    $this->plugin->getLogger()->info("Saved token data to emergency location: " . $emergencyPath);
                    $this->tokenData = $emergencyConfig; // Use this config from now on
                    return true;
                } catch (\Throwable $emergencyError) {
                    $this->plugin->getLogger()->error("Emergency save also failed: " . $emergencyError->getMessage());
                    return false;
                }
            }
            
            return true;
        } catch (\Throwable $e) {
            $this->plugin->getLogger()->error("Error saving player token data: " . $e->getMessage());
            $this->plugin->getLogger()->error("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Check if a player can use a token
     * 
     * @param Player $player
     * @return bool
     */
    public function canUseToken(Player $player): bool {
        if (!$this->enabled) {
            return true; // Token system disabled, always allow
        }
        
        $playerName = $player->getName();
        $playerData = $this->getPlayerData($playerName);
        
        // Check if player has tokens
        if ($playerData["tokens"] > 0) {
            return true;
        }
        
        // Check if player has free tokens left
        if ($playerData["free_tokens_used_today"] < $this->freeDailyTokens) {
            return true;
        }
        
        return false;
    }

    /**
     * Use a token for a player
     * 
     * @param Player $player
     * @return bool
     */
    public function useToken(Player $player): bool {
        if (!$this->enabled) {
            return true; // Token system disabled, always succeed
        }
        
        try {
            $playerName = $player->getName();
            $playerData = $this->getPlayerData($playerName);
            
            // First try to use purchased tokens
            if ($playerData["tokens"] > 0) {
                $playerData["tokens"]--;
                if ($this->savePlayerData($playerName, $playerData)) {
                    $this->logTokenUsage($playerName, "purchased");
                    return true;
                }
                return false;
            }
            
            // Then try to use free daily tokens
            if ($playerData["free_tokens_used_today"] < $this->freeDailyTokens) {
                $playerData["free_tokens_used_today"]++;
                if ($this->savePlayerData($playerName, $playerData)) {
                    $this->logTokenUsage($playerName, "free");
                    return true;
                }
                return false;
            }
            
            return false;
        } catch (\Throwable $e) {
            $this->plugin->getLogger()->error("Error using token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add tokens to a player
     * 
     * @param string $playerName
     * @param int $amount
     * @return bool
     */
    public function addTokens(string $playerName, int $amount): bool {
        if ($amount <= 0) {
            return false;
        }
        
        $playerData = $this->getPlayerData($playerName);
        $playerData["tokens"] += $amount;
        $this->savePlayerData($playerName, $playerData);
        
        return true;
    }

    /**
     * Get the number of tokens a player has
     * 
     * @param string $playerName
     * @return int
     */
    public function getTokens(string $playerName): int {
        $playerData = $this->getPlayerData($playerName);
        return $playerData["tokens"];
    }

    /**
     * Get the number of free tokens a player has used today
     * 
     * @param string $playerName
     * @return int
     */
    public function getFreeTokensUsedToday(string $playerName): int {
        $playerData = $this->getPlayerData($playerName);
        return $playerData["free_tokens_used_today"];
    }

    /**
     * Get the number of free tokens a player has left today
     * 
     * @param string $playerName
     * @return int
     */
    public function getFreeTokensLeftToday(string $playerName): int {
        $playerData = $this->getPlayerData($playerName);
        return max(0, $this->freeDailyTokens - $playerData["free_tokens_used_today"]);
    }

    /**
     * Get token status message for a player
     * 
     * @param string $playerName
     * @return string
     */
    public function getTokenStatusMessage(string $playerName): string {
        if (!$this->enabled) {
            return "Token system is disabled.";
        }
        
        $playerData = $this->getPlayerData($playerName);
        $purchasedTokens = $playerData["tokens"];
        $freeTokensUsed = $playerData["free_tokens_used_today"];
        $freeTokensLeft = max(0, $this->freeDailyTokens - $freeTokensUsed);
        
        return "You have {$purchasedTokens} purchased tokens and {$freeTokensLeft} free daily tokens left.";
    }

   /**
 * Log token usage
 *
 * @param string $playerName
 * @param string $tokenType
 * @return bool Success status
 */
   private function logTokenUsage(string $playerName, string $tokenType): bool {
    try {
        $usage = $this->usageData->get("usage", []);
        $date = date("Y-m-d");

        if (!isset($usage[$date])) {
            $usage[$date] = [];
        }

        if (!isset($usage[$date][$playerName])) {
            $usage[$date][$playerName] = [
                "free" => 0,
                "purchased" => 0
            ];
        }

        $usage[$date][$playerName][$tokenType]++;

        // Update in memory
        $this->usageData->set("usage", $usage);

        // Ensure the directory exists before saving
        $usagePath = $this->usageData->getPath();
        $usageDir = $usagePath !== null ? dirname($usagePath) : $this->plugin->getDataFolder();
        if (!is_dir($usageDir)) {
            if (!@mkdir($usageDir, 0755, true) && !is_dir($usageDir)) {
                $this->plugin->getLogger()->error("Failed to create usage data directory: " . $usageDir);
                return false;
            }
        }

        // Save with proper try/catch (Config::save() is void)
        try {
            $this->usageData->save();
            return true;
        } catch (\Throwable $e) {
            $this->plugin->getLogger()->error("Failed to save token usage data for player: " . $playerName . " - " . $e->getMessage());

            // Fallback: try emergency save to plugin data folder
            try {
                $emergencyPath = $this->plugin->getDataFolder() . "emergency_usage_data.yml";
                $emergencyConfig = new Config($emergencyPath, Config::YAML, ["usage" => $usage]);
                $emergencyConfig->set("usage", $usage);
                $emergencyConfig->save();
                $this->usageData = $emergencyConfig;
                $this->plugin->getLogger()->info("Saved usage data to emergency location: " . $emergencyPath);
                return true;
            } catch (\Throwable $emergencyError) {
                $this->plugin->getLogger()->error("Emergency usage save also failed: " . $emergencyError->getMessage());
                return false;
            }
        }
    } catch (\Throwable $e) {
        $this->plugin->getLogger()->error("Error logging token usage: " . $e->getMessage());
        return false;
    }
}

    /**
     * Get token usage for a specific date
     * 
     * @param string $date
     * @return array
     */
    public function getTokenUsageByDate(string $date): array {
        $usage = $this->usageData->get("usage", []);
        return $usage[$date] ?? [];
    }

    /**
     * Get token usage for a specific player
     * 
     * @param string $playerName
     * @param int $days
     * @return array
     */
    public function getTokenUsageByPlayer(string $playerName, int $days = 7): array {
        $usage = $this->usageData->get("usage", []);
        $result = [];
        
        // Get usage for the last $days days
        $currentDate = new \DateTime();
        for ($i = 0; $i < $days; $i++) {
            $date = $currentDate->format("Y-m-d");
            $result[$date] = [
                "free" => 0,
                "purchased" => 0
            ];
            
            if (isset($usage[$date][$playerName])) {
                $result[$date] = $usage[$date][$playerName];
            }
            
            $currentDate->modify("-1 day");
        }
        
        return $result;
    }

    /**
     * Get total token usage
     * 
     * @param int $days
     * @return array
     */
    public function getTotalTokenUsage(int $days = 7): array {
        $usage = $this->usageData->get("usage", []);
        $result = [];
        
        // Get usage for the last $days days
        $currentDate = new \DateTime();
        for ($i = 0; $i < $days; $i++) {
            $date = $currentDate->format("Y-m-d");
            $result[$date] = [
                "free" => 0,
                "purchased" => 0,
                "total" => 0
            ];
            
            if (isset($usage[$date])) {
                foreach ($usage[$date] as $playerUsage) {
                    $result[$date]["free"] += $playerUsage["free"];
                    $result[$date]["purchased"] += $playerUsage["purchased"];
                }
                $result[$date]["total"] = $result[$date]["free"] + $result[$date]["purchased"];
            }
            
            $currentDate->modify("-1 day");
        }
        
        return $result;
    }

    /**
     * Check if the token system is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Get the number of free daily tokens
     * 
     * @return int
     */
    public function getFreeDailyTokens(): int {
        return $this->freeDailyTokens;
    }

    /**
     * Buy tokens for a player
     * 
     * @param Player $player
     * @param int $amount
     * @return bool
     */
    public function buyTokens(Player $player, int $amount): bool {
        if ($amount <= 0) {
            return false;
        }
        
        $economyManager = $this->plugin->getEconomyManager();
        $config = $this->plugin->getConfig();
        $tokenPrice = (float) $config->getNested("tokens.token_price", 100);
        $totalCost = $tokenPrice * $amount;
        
        // Check if player has enough money
        if (!$economyManager->hasMoney($player, $totalCost)) {
            return false;
        }
        
        // Deduct money and add tokens
        if ($economyManager->reduceMoney($player, $totalCost)) {
            return $this->addTokens($player->getName(), $amount);
        }
        
        return false;
    }

    /**
     * Buy a token package for a player
     * 
     * @param Player $player
     * @param int $packageIndex
     * @return bool
     */
    public function buyTokenPackage(Player $player, int $packageIndex): bool {
        $config = $this->plugin->getConfig();
        $packages = $config->getNested("tokens.token_packages", []);
        
        if (!isset($packages[$packageIndex])) {
            return false;
        }
        
        $package = $packages[$packageIndex];
        $tokens = (int) $package["tokens"];
        $price = (float) $package["price"];
        
        $economyManager = $this->plugin->getEconomyManager();
        
        // Check if player has enough money
        if (!$economyManager->hasMoney($player, $price)) {
            return false;
        }
        
        // Deduct money and add tokens
        if ($economyManager->reduceMoney($player, $price)) {
            return $this->addTokens($player->getName(), $tokens);
        }
        
        return false;
    }
}