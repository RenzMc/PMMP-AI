<?php

declare(strict_types=1);

namespace Renz\AIAssistant\utils;

use pocketmine\player\Player;
use pocketmine\Server;
use Renz\AIAssistant\Main;

class ServerInfoProvider {
    /** @var Main */
    private Main $plugin;

    /** @var string */
    private string $serverName;

    /** @var string */
    private string $serverDescription;

    /** @var array */
    private array $serverRules;

    /** @var string */
    private string $ownerName;

    /** @var bool */
    private bool $showCoordinates;

    /** @var bool */
    private bool $showStatistics;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadConfig();
    }

    private function loadConfig(): void {
        $config = $this->plugin->getConfig();
        $this->serverName = (string) $config->getNested("server_info.server_name", "Your Minecraft Server");
        $this->serverDescription = (string) $config->getNested("server_info.server_description", "A PocketMine-MP server with AI Assistant");
        $this->serverRules = (array) $config->getNested("server_info.server_rules", []);
        $this->ownerName = (string) $config->getNested("server_info.owner_name", "ServerOwner");
        $this->showCoordinates = (bool) $config->getNested("server_info.show_coordinates", true);
        $this->showStatistics = (bool) $config->getNested("server_info.show_statistics", true);
    }

    /* ============================
       Basic getters (used by forms)
       ============================ */

    public function getServerName(): string {
        return $this->serverName;
    }

    public function getServerDescription(): string {
        return $this->serverDescription;
    }

    public function getServerRules(): array {
        return $this->serverRules;
    }

    public function getServerOwner(): string {
        return $this->ownerName;
    }

    public function isShowCoordinates(): bool {
        return $this->showCoordinates;
    }

    public function isShowStatistics(): bool {
        return $this->showStatistics;
    }

    /* ============================
       Extra getters referenced by your form
       ============================ */

    public function getMotd(): string {
        // Prioritize config -> Server API -> fallback description
        $config = $this->plugin->getConfig();
        $motd = $config->getNested("server_info.motd", null);
        if ($motd !== null && $motd !== "") {
            return (string) $motd;
        }

        $server = Server::getInstance();
        if ($server !== null && method_exists($server, "getMotd")) {
            $val = $server->getMotd();
            return $val === null ? "" : (string) $val;
        }

        return $this->serverDescription;
    }

    public function getServerSoftware(): string {
        $server = Server::getInstance();
        if ($server !== null) {
            if (method_exists($server, "getPocketMineVersion")) {
                return "PocketMine-MP " . $server->getPocketMineVersion();
            }
            if (method_exists($server, "getVersion")) {
                return "PocketMine-MP " . $server->getVersion();
            }
        }
        return "PocketMine-MP";
    }

    public function getMinecraftVersion(): string {
        // Try config first, then try server methods, else Unknown
        $config = $this->plugin->getConfig();
        $ver = $config->getNested("server_info.minecraft_version", null);
        if ($ver !== null && $ver !== "") {
            return (string) $ver;
        }

        $server = Server::getInstance();
        if ($server !== null) {
            // some PMMP builds expose protocol/version info via getVersion/getPocketMineVersion
            if (method_exists($server, "getPocketMineVersion")) {
                return (string) $server->getPocketMineVersion();
            }
            if (method_exists($server, "getVersion")) {
                return (string) $server->getVersion();
            }
        }

        return "Unknown";
    }

    public function getName(): string {
        // Alias: internal server name (if needed)
        $server = Server::getInstance();
        if ($server !== null && method_exists($server, "getName")) {
            $val = $server->getName();
            if ($val !== null && $val !== "") {
                return (string) $val;
            }
        }
        return $this->serverName;
    }

    public function getOnlinePlayerCount(): int {
        $server = Server::getInstance();
        if ($server !== null) {
            return count($server->getOnlinePlayers());
        }
        return 0;
    }

    public function getMaxPlayerCount(): int {
        $server = Server::getInstance();
        if ($server !== null && method_exists($server, "getMaxPlayers")) {
            return (int) $server->getMaxPlayers();
        }
        // fallback to config if set
        $cfg = $this->plugin->getConfig()->getNested("server_info.max_players", null);
        if (is_int($cfg)) {
            return $cfg;
        }
        return 0;
    }

    public function getServerTPS(): float {
        $server = Server::getInstance();
        if ($server !== null) {
            if (method_exists($server, "getTicksPerSecond")) {
                return (float) $server->getTicksPerSecond();
            }
            if (method_exists($server, "getTicksPerSecondAverage")) {
                return (float) $server->getTicksPerSecondAverage();
            }
        }
        return 0.0;
    }

    public function getMemoryUsage(): string {
        // return human readable memory usage (MB)
        $usage = memory_get_usage(true); // in bytes
        $mb = $usage / 1024 / 1024;
        return round($mb, 2) . " MB";
    }

    /* ============================
       Composite builders (optional)
       ============================ */

    /**
     * Build a plain text block for prompts (no § color codes)
     */
    public function getServerInfoForPrompt(Player $player): string {
        $s = "Server Name: " . $this->getServerName() . "\n";
        $s .= "MOTD: " . $this->getMotd() . "\n";
        $s .= "Description: " . $this->getServerDescription() . "\n";
        $s .= "Owner: " . $this->getServerOwner() . "\n";
        $s .= "Players: " . $this->getOnlinePlayerCount() . "/" . $this->getMaxPlayerCount() . "\n";
        $s .= "TPS: " . $this->getServerTPS() . "\n";
        $s .= "Memory: " . $this->getMemoryUsage() . "\n";
        return $s;
    }

    /**
     * Build a color-coded display block for in-game forms (uses § codes)
     */
    public function getServerInfoForDisplay(Player $player): string {
        $info = "§b§l" . $this->getServerName() . "§r\n";
        $motd = $this->getMotd();
        $info .= "§7" . ($motd !== "" ? $motd : $this->getServerDescription()) . "§r\n\n";

        if (!empty($this->serverRules)) {
            $info .= "§e§lServer Rules:§r\n";
            foreach ($this->serverRules as $i => $r) {
                $info .= "§7" . ($i + 1) . ". " . $r . "§r\n";
            }
            $info .= "\n";
        }

        $info .= "§e§lOwner:§r §7" . $this->getServerOwner() . "§r\n";
        $info .= "§e§lPlayers:§r §7" . $this->getOnlinePlayerCount() . "/" . $this->getMaxPlayerCount() . "§r\n";
        $info .= "§e§lTPS:§r §7" . $this->getServerTPS() . "§r\n";
        $info .= "§e§lMemory:§r §7" . $this->getMemoryUsage() . "§r\n";

        // Coordinates (if enabled)
        if ($this->showCoordinates) {
            $pos = $player->getPosition();
            $worldName = $player->getWorld() !== null ? $player->getWorld()->getFolderName() : "world";
            if ($pos !== null) {
                $info .= "§e§lYour Pos:§r §7X:" . (int)$pos->getX() . " Y:" . (int)$pos->getY() . " Z:" . (int)$pos->getZ() . " (" . $worldName . ")§r\n";
            }
        }

        return $info;
    }

    /* ============================
       Setters (config writers)
       ============================ */

    public function setServerName(string $name): void {
        $this->serverName = $name;
        $cfg = $this->plugin->getConfig();
        $cfg->setNested("server_info.server_name", $name);
        $cfg->save();
    }

    public function setServerDescription(string $desc): void {
        $this->serverDescription = $desc;
        $cfg = $this->plugin->getConfig();
        $cfg->setNested("server_info.server_description", $desc);
        $cfg->save();
    }

    public function setOwnerName(string $owner): void {
        $this->ownerName = $owner;
        $cfg = $this->plugin->getConfig();
        $cfg->setNested("server_info.owner_name", $owner);
        $cfg->save();
    }

    public function addServerRule(string $rule): void {
        $this->serverRules[] = $rule;
        $cfg = $this->plugin->getConfig();
        $cfg->setNested("server_info.server_rules", $this->serverRules);
        $cfg->save();
    }

    public function removeServerRule(int $index): bool {
        if (!isset($this->serverRules[$index])) {
            return false;
        }
        unset($this->serverRules[$index]);
        $this->serverRules = array_values($this->serverRules);
        $cfg = $this->plugin->getConfig();
        $cfg->setNested("server_info.server_rules", $this->serverRules);
        $cfg->save();
        return true;
    }
}