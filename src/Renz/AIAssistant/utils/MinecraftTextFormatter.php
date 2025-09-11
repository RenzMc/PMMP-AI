<?php

declare(strict_types=1);

namespace Renz\AIAssistant\utils;

use pocketmine\utils\TextFormat;

class MinecraftTextFormatter {
    // Map the color constants to TextFormat constants
    public const COLOR_BLACK = TextFormat::BLACK;
    public const COLOR_DARK_BLUE = TextFormat::DARK_BLUE;
    public const COLOR_DARK_GREEN = TextFormat::DARK_GREEN;
    public const COLOR_DARK_AQUA = TextFormat::DARK_AQUA;
    public const COLOR_DARK_RED = TextFormat::DARK_RED;
    public const COLOR_DARK_PURPLE = TextFormat::DARK_PURPLE;
    public const COLOR_GOLD = TextFormat::GOLD;
    public const COLOR_GRAY = TextFormat::GRAY;
    public const COLOR_DARK_GRAY = TextFormat::DARK_GRAY;
    public const COLOR_BLUE = TextFormat::BLUE;
    public const COLOR_GREEN = TextFormat::GREEN;
    public const COLOR_AQUA = TextFormat::AQUA;
    public const COLOR_RED = TextFormat::RED;
    public const COLOR_LIGHT_PURPLE = TextFormat::LIGHT_PURPLE;
    public const COLOR_YELLOW = TextFormat::YELLOW;
    public const COLOR_WHITE = TextFormat::WHITE;
    
    // Add the new material colors from TextFormat
    public const COLOR_MINECOIN_GOLD = TextFormat::MINECOIN_GOLD;
    public const COLOR_MATERIAL_QUARTZ = TextFormat::MATERIAL_QUARTZ;
    public const COLOR_MATERIAL_IRON = TextFormat::MATERIAL_IRON;
    public const COLOR_MATERIAL_NETHERITE = TextFormat::MATERIAL_NETHERITE;
    public const COLOR_MATERIAL_REDSTONE = TextFormat::MATERIAL_REDSTONE;
    public const COLOR_MATERIAL_COPPER = TextFormat::MATERIAL_COPPER;
    public const COLOR_MATERIAL_GOLD = TextFormat::MATERIAL_GOLD;
    public const COLOR_MATERIAL_EMERALD = TextFormat::MATERIAL_EMERALD;
    public const COLOR_MATERIAL_DIAMOND = TextFormat::MATERIAL_DIAMOND;
    public const COLOR_MATERIAL_LAPIS = TextFormat::MATERIAL_LAPIS;
    public const COLOR_MATERIAL_AMETHYST = TextFormat::MATERIAL_AMETHYST;
    public const COLOR_MATERIAL_RESIN = TextFormat::MATERIAL_RESIN;
    
    // Map the formatting constants to TextFormat constants
    public const FORMAT_OBFUSCATED = TextFormat::OBFUSCATED;
    public const FORMAT_BOLD = TextFormat::BOLD;
    public const FORMAT_STRIKETHROUGH = TextFormat::STRIKETHROUGH;
    public const FORMAT_UNDERLINE = TextFormat::UNDERLINE;
    public const FORMAT_ITALIC = TextFormat::ITALIC;
    public const FORMAT_RESET = TextFormat::RESET;
    
    /**
     * Convert markdown formatting to Minecraft formatting
     * 
     * @param string $text Text with markdown formatting
     * @return string Text with Minecraft formatting
     */
    public static function markdownToMinecraft(string $text): string {
        // Replace markdown headers with colored text
        $text = preg_replace('/^# (.*)$/m', self::COLOR_GOLD . self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        $text = preg_replace('/^## (.*)$/m', self::COLOR_YELLOW . self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        $text = preg_replace('/^### (.*)$/m', self::COLOR_AQUA . self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown bold with Minecraft bold
        $text = preg_replace('/\*\*([^*]+)\*\*/', self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown italic with Minecraft italic
        $text = preg_replace('/\*([^*]+)\*/', self::FORMAT_ITALIC . "$1" . self::FORMAT_RESET, $text);
        $text = preg_replace('/_([^_]+)_/', self::FORMAT_ITALIC . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown code blocks with colored text
        $text = preg_replace('/```([^`]+)```/', self::COLOR_GRAY . "$1" . self::FORMAT_RESET, $text);
        $text = preg_replace('/`([^`]+)`/', self::COLOR_GRAY . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown lists with colored text
        $text = preg_replace('/^- (.*)$/m', self::COLOR_YELLOW . "• " . self::COLOR_WHITE . "$1", $text);
        $text = preg_replace('/^\* (.*)$/m', self::COLOR_YELLOW . "• " . self::COLOR_WHITE . "$1", $text);
        $text = preg_replace('/^(\d+)\. (.*)$/m', self::COLOR_YELLOW . "$1. " . self::COLOR_WHITE . "$2", $text);
        
        // Replace markdown links with colored text
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', self::COLOR_AQUA . "$1" . self::COLOR_GRAY . " ($2)" . self::FORMAT_RESET, $text);
        
        return $text;
    }
    
    /**
     * Format text for Minecraft with default styling
     * 
     * @param string $text Text to format
     * @param string $defaultColor Default color to use
     * @return string Formatted text
     */
    public static function formatText(string $text, string $defaultColor = self::COLOR_WHITE): string {
        // First convert any markdown to Minecraft formatting
        $text = self::markdownToMinecraft($text);
        
        // Apply default color if no color is present at the start
        if (!preg_match('/^' . preg_quote(TextFormat::ESCAPE, '/') . '[0-9a-v]/', $text)) {
            $text = $defaultColor . $text;
        }
        
        // Ensure paragraphs have proper color
        $text = preg_replace('/(\n\n|\r\n\r\n)/', "$1" . $defaultColor, $text);
        
        return $text;
    }
    
    /**
     * Create a formatted title
     * 
     * @param string $title Title text
     * @param string $color Color code
     * @return string Formatted title
     */
    public static function formatTitle(string $title, string $color = self::COLOR_GOLD): string {
        return $color . self::FORMAT_BOLD . $title . self::FORMAT_RESET;
    }
    
    /**
     * Create a formatted subtitle
     * 
     * @param string $subtitle Subtitle text
     * @param string $color Color code
     * @return string Formatted subtitle
     */
    public static function formatSubtitle(string $subtitle, string $color = self::COLOR_YELLOW): string {
        return $color . self::FORMAT_BOLD . $subtitle . self::FORMAT_RESET;
    }
    
    /**
     * Format a crafting recipe for Minecraft
     * 
     * @param string $itemName Item name
     * @param array $ingredients Array of ingredients
     * @param string $pattern Crafting pattern
     * @return string Formatted crafting recipe
     */
    public static function formatCraftingRecipe(string $itemName, array $ingredients, string $pattern): string {
        $result = self::formatTitle("Crafting Recipe: $itemName") . "\n\n";
        
        $result .= self::COLOR_YELLOW . "Ingredients:\n";
        foreach ($ingredients as $ingredient) {
            $result .= self::COLOR_WHITE . "• " . $ingredient . "\n";
        }
        
        $result .= "\n" . self::COLOR_YELLOW . "Pattern:\n";
        $result .= self::COLOR_WHITE . $pattern . "\n";
        
        return $result;
    }
    
    /**
     * Format a building calculation for Minecraft
     * 
     * @param int $length Building length
     * @param int $width Building width
     * @param int $height Building height
     * @param string $style Building style
     * @param array $materials Recommended materials
     * @return string Formatted building calculation
     */
    public static function formatBuildingCalculation(int $length, int $width, int $height, string $style, array $materials): string {
        $wallBlocks = 2 * ($length * $height) + 2 * ($width * $height);
        $floorBlocks = $length * $width;
        $roofBlocks = $length * $width;
        $totalBlocks = $wallBlocks + $floorBlocks + $roofBlocks;
        
        $result = self::formatTitle("Building Calculation") . "\n\n";
        
        $result .= self::COLOR_YELLOW . "Dimensions: " . self::COLOR_WHITE . "{$length}x{$width}x{$height}\n";
        $result .= self::COLOR_YELLOW . "Style: " . self::COLOR_WHITE . $style . "\n\n";
        
        $result .= self::COLOR_YELLOW . "Blocks Needed:\n";
        $result .= self::COLOR_WHITE . "• Walls: " . $wallBlocks . " blocks\n";
        $result .= self::COLOR_WHITE . "• Floor: " . $floorBlocks . " blocks\n";
        $result .= self::COLOR_WHITE . "• Roof: " . $roofBlocks . " blocks\n";
        $result .= self::COLOR_WHITE . "• Total: " . $totalBlocks . " blocks\n\n";
        
        $result .= self::COLOR_YELLOW . "Recommended Materials:\n";
        foreach ($materials as $material) {
            $result .= self::COLOR_WHITE . "• " . $material . "\n";
        }
        
        return $result;
    }
    
    /**
     * Format server information for Minecraft
     * 
     * @param array $serverInfo Server information
     * @return string Formatted server information
     */
    public static function formatServerInfo(array $serverInfo): string {
        $result = self::formatTitle("Server Information") . "\n\n";
        
        foreach ($serverInfo as $category => $info) {
            $result .= self::formatSubtitle($category) . "\n";
            
            foreach ($info as $key => $value) {
                $result .= self::COLOR_YELLOW . "$key: " . self::COLOR_WHITE . "$value\n";
            }
            
            $result .= "\n";
        }
        
        return $result;
    }
}