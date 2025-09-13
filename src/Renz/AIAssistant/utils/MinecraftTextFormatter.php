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
     * Safely execute regex with error handling
     * 
     * @param string $pattern Regex pattern
     * @param string $replacement Replacement string
     * @param string $subject Subject string
     * @return string Result or original string on error
     */
    private static function safeRegexReplace(string $pattern, string $replacement, string $subject): string {
        try {
            $result = preg_replace($pattern, $replacement, $subject);
            if ($result === null) {
                error_log("Regex error in MinecraftTextFormatter: " . preg_last_error_msg());
                return $subject;
            }
            return $result;
        } catch (\Exception $e) {
            error_log("Regex exception in MinecraftTextFormatter: " . $e->getMessage());
            return $subject;
        }
    }
    
    /**
     * Convert markdown formatting to Minecraft formatting
     * 
     * @param string $text Text with markdown formatting
     * @return string Text with Minecraft formatting
     */
    public static function markdownToMinecraft(string $text): string {
        // Safety check for empty or null input
        if (empty($text) || strlen($text) > 32768) { // Limit to 32KB to prevent memory issues
            return $text;
        }
        
        // Ensure input is properly cast to string to avoid type errors
        $text = (string)$text;
        
        // Replace markdown headers with colored text (more restrictive patterns)
        $text = self::safeRegexReplace('/^#{4,6}\s+(.+?)$/m', self::COLOR_GRAY . self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        $text = self::safeRegexReplace('/^###\s+(.+?)$/m', self::COLOR_AQUA . self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        $text = self::safeRegexReplace('/^##\s+(.+?)$/m', self::COLOR_YELLOW . self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        $text = self::safeRegexReplace('/^#\s+(.+?)$/m', self::COLOR_GOLD . self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown code blocks with colored text (safer multiline matching)
        $text = self::safeRegexReplace('/```[a-zA-Z0-9]*\n?([\s\S]*?)```/U', self::COLOR_GRAY . "$1" . self::FORMAT_RESET, $text);
        $text = self::safeRegexReplace('/`([^`\n\r]{1,200})`/', self::COLOR_GRAY . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown bold/italic combinations first (proper order)
        $text = self::safeRegexReplace('/\*{3}([^*\n\r]{1,200}?)\*{3}/', self::FORMAT_BOLD . self::FORMAT_ITALIC . "$1" . self::FORMAT_RESET, $text);
        $text = self::safeRegexReplace('/_{3}([^_\n\r]{1,200}?)_{3}/', self::FORMAT_BOLD . self::FORMAT_ITALIC . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown bold with Minecraft bold
        $text = self::safeRegexReplace('/\*{2}([^*\n\r]{1,200}?)\*{2}/', self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        $text = self::safeRegexReplace('/_{2}([^_\n\r]{1,200}?)_{2}/', self::FORMAT_BOLD . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown italic with Minecraft italic (improved negative lookbehind/ahead)
        $text = self::safeRegexReplace('/(?<!\*)\*([^*\n\r]{1,200}?)\*(?!\*)/', self::FORMAT_ITALIC . "$1" . self::FORMAT_RESET, $text);
        $text = self::safeRegexReplace('/(?<!_)_([^_\n\r]{1,200}?)_(?!_)/', self::FORMAT_ITALIC . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown strikethrough
        $text = self::safeRegexReplace('/~{2}([^~\n\r]{1,200}?)~{2}/', self::FORMAT_STRIKETHROUGH . "$1" . self::FORMAT_RESET, $text);
        
        // Replace markdown lists with colored text (better list handling)
        $text = self::safeRegexReplace('/^\s*[-*+]\s+(.+?)$/m', self::COLOR_YELLOW . "• " . self::COLOR_WHITE . "$1", $text);
        $text = self::safeRegexReplace('/^\s*(\d{1,3})\.\s+(.+?)$/m', self::COLOR_YELLOW . "$1. " . self::COLOR_WHITE . "$2", $text);
        
        // Replace markdown links with colored text (handle URLs with query strings and fragments)
        $text = self::safeRegexReplace('/\[([^\]]{1,100})\]\(([^)\s]{1,500}(?:\?[^)\s]{0,200})?(?:#[^)\s]{0,100})?)\)/', self::COLOR_AQUA . self::FORMAT_UNDERLINE . "$1" . self::FORMAT_RESET . self::COLOR_GRAY . " ($2)", $text);
        
        // Replace markdown blockquotes
        $text = self::safeRegexReplace('/^>\s*(.+?)$/m', self::COLOR_GRAY . "│ " . self::COLOR_WHITE . "$1", $text);
        
        // Clean up any remaining markdown artifacts (safer escape cleanup)
        $text = self::safeRegexReplace('/\\\\([*_`~#\[\]()\\\\\/])/', '$1', $text);
        
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
        // Safety check for input
        if (empty($text) || strlen($text) > 32768) {
            return $text;
        }
        
        // Ensure input is properly cast to string to avoid type errors
        $text = (string)$text;
        
        // First convert any markdown to Minecraft formatting
        $text = self::markdownToMinecraft($text);
        
        // Apply default color if no color is present at the start (safer regex)
        $escapedChar = preg_quote(TextFormat::ESCAPE, '/');
        if (!preg_match('/^' . $escapedChar . '[0-9a-v]/', $text)) {
            $text = $defaultColor . $text;
        }
        
        // Ensure paragraphs have proper color after line breaks
        $text = self::safeRegexReplace('/(\r?\n\s*\r?\n)/', "$1" . $defaultColor, $text);
        $text = self::safeRegexReplace('/(\r?\n)([^\n\r§])/', "$1" . $defaultColor . "$2", $text);
        
        // Clean up excessive formatting resets
        $escapedReset = preg_quote(self::FORMAT_RESET, '/');
        $text = self::safeRegexReplace('/' . $escapedReset . '{2,}/', self::FORMAT_RESET, $text);
        
        // Ensure text ends with a reset if it has formatting
        if (str_contains($text, TextFormat::ESCAPE) && !str_ends_with($text, self::FORMAT_RESET)) {
            $text .= self::FORMAT_RESET;
        }
        
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
        // Safety check and sanitize input
        $title = substr((string)$title, 0, 200); // Limit title length
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
        // Safety check and sanitize input
        $subtitle = substr((string)$subtitle, 0, 200); // Limit subtitle length
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
        // Safety checks
        $itemName = substr((string)$itemName, 0, 100);
        $pattern = substr((string)$pattern, 0, 500);
        $ingredients = array_slice($ingredients, 0, 20); // Limit ingredients
        
        $result = self::formatTitle("Crafting Recipe: $itemName") . "\n\n";
        
        $result .= self::COLOR_YELLOW . "Ingredients:\n";
        foreach ($ingredients as $ingredient) {
            $ingredient = substr((string)$ingredient, 0, 100); // Limit ingredient length
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
        // Safety checks for dimensions
        $length = max(1, min(1000, abs($length)));
        $width = max(1, min(1000, abs($width)));
        $height = max(1, min(256, abs($height)));
        $style = substr((string)$style, 0, 50);
        $materials = array_slice($materials, 0, 20);
        
        $wallBlocks = 2 * ($length * $height) + 2 * ($width * $height);
        $floorBlocks = $length * $width;
        $roofBlocks = $length * $width;
        $totalBlocks = $wallBlocks + $floorBlocks + $roofBlocks;
        
        $result = self::formatTitle("Building Calculation") . "\n\n";
        
        $result .= self::COLOR_YELLOW . "Dimensions: " . self::COLOR_WHITE . "{$length}x{$width}x{$height}\n";
        $result .= self::COLOR_YELLOW . "Style: " . self::COLOR_WHITE . $style . "\n\n";
        
        $result .= self::COLOR_YELLOW . "Blocks Needed:\n";
        $result .= self::COLOR_WHITE . "• Walls: " . number_format($wallBlocks) . " blocks\n";
        $result .= self::COLOR_WHITE . "• Floor: " . number_format($floorBlocks) . " blocks\n";
        $result .= self::COLOR_WHITE . "• Roof: " . number_format($roofBlocks) . " blocks\n";
        $result .= self::COLOR_WHITE . "• Total: " . number_format($totalBlocks) . " blocks\n\n";
        
        $result .= self::COLOR_YELLOW . "Recommended Materials:\n";
        foreach ($materials as $material) {
            $material = substr((string)$material, 0, 100);
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
        // Safety check for array size
        if (count($serverInfo) > 50) {
            $serverInfo = array_slice($serverInfo, 0, 50, true);
        }
        
        $result = self::formatTitle("Server Information") . "\n\n";
        
        foreach ($serverInfo as $category => $info) {
            $category = substr((string)$category, 0, 50);
            $result .= self::formatSubtitle($category) . "\n";
            
            if (is_array($info)) {
                $info = array_slice($info, 0, 20, true); // Limit info items
                foreach ($info as $key => $value) {
                    $key = substr((string)$key, 0, 50);
                    $value = substr((string)$value, 0, 200);
                    $result .= self::COLOR_YELLOW . "$key: " . self::COLOR_WHITE . "$value\n";
                }
            } else {
                $info = substr((string)$info, 0, 200);
                $result .= self::COLOR_WHITE . $info . "\n";
            }
            
            $result .= "\n";
        }
        
        return $result;
    }
}
