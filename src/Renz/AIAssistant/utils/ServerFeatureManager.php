<?php

declare(strict_types=1);

namespace Renz\AIAssistant\utils;

use Renz\AIAssistant\Main;
use pocketmine\utils\Config;

class ServerFeatureManager {
    /** @var Main */
    private Main $plugin;
    
    /** @var array */
    private array $features = [];
    
    /** @var Config */
    private Config $featureConfig;

    /**
     * ServerFeatureManager constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadFeatures();
    }

    /**
     * Load features from fiturserver.yml
     */
    private function loadFeatures(): void {
        // Create default fiturserver.yml if it doesn't exist
        $configPath = $this->plugin->getDataFolder() . "fiturserver.yml";
        if (!file_exists($configPath)) {
            $this->plugin->saveResource("fiturserver.yml");
        }
        
        // Load the features config
        $this->featureConfig = new Config($configPath, Config::YAML);
        $this->features = $this->featureConfig->getAll();
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Loaded " . count($this->features) . " server features");
        }
    }

    /**
     * Get all server features
     * 
     * @return array
     */
    public function getAllFeatures(): array {
        return $this->features;
    }

    /**
     * Get a specific feature by category and name
     * 
     * @param string $category
     * @param string $name
     * @return array|null
     */
    public function getFeature(string $category, string $name): ?array {
        if (isset($this->features[$category][$name])) {
            return $this->features[$category][$name];
        }
        
        return null;
    }

    /**
     * Get all features in a category
     * 
     * @param string $category
     * @return array
     */
    public function getCategoryFeatures(string $category): array {
        return $this->features[$category] ?? [];
    }

    /**
     * Get all feature categories
     * 
     * @return array
     */
    public function getCategories(): array {
        return array_keys($this->features);
    }

    /**
     * Add a new feature
     * 
     * @param string $category
     * @param string $name
     * @param array $data
     * @return bool
     */
    public function addFeature(string $category, string $name, array $data): bool {
        if (!isset($this->features[$category])) {
            $this->features[$category] = [];
        }
        
        $this->features[$category][$name] = $data;
        $this->featureConfig->setAll($this->features);
        $this->featureConfig->save();
        
        return true;
    }

    /**
     * Remove a feature
     * 
     * @param string $category
     * @param string $name
     * @return bool
     */
    public function removeFeature(string $category, string $name): bool {
        if (isset($this->features[$category][$name])) {
            unset($this->features[$category][$name]);
            
            // Remove the category if it's empty
            if (empty($this->features[$category])) {
                unset($this->features[$category]);
            }
            
            $this->featureConfig->setAll($this->features);
            $this->featureConfig->save();
            
            return true;
        }
        
        return false;
    }

    /**
     * Search for features by keyword
     * 
     * @param string $keyword
     * @return array
     */
    public function searchFeatures(string $keyword): array {
        $keyword = strtolower($keyword);
        $results = [];
        
        foreach ($this->features as $category => $categoryFeatures) {
            foreach ($categoryFeatures as $name => $data) {
                // Search in name
                if (strpos(strtolower($name), $keyword) !== false) {
                    if (!isset($results[$category])) {
                        $results[$category] = [];
                    }
                    $results[$category][$name] = $data;
                    continue;
                }
                
                // Search in description
                if (isset($data['description']) && strpos(strtolower($data['description']), $keyword) !== false) {
                    if (!isset($results[$category])) {
                        $results[$category] = [];
                    }
                    $results[$category][$name] = $data;
                    continue;
                }
                
                // Search in tutorial
                if (isset($data['tutorial']) && strpos(strtolower($data['tutorial']), $keyword) !== false) {
                    if (!isset($results[$category])) {
                        $results[$category] = [];
                    }
                    $results[$category][$name] = $data;
                    continue;
                }
            }
        }
        
        return $results;
    }

    /**
     * Get relevant features for an AI query
     * 
     * @param string $query
     * @return array
     */
    public function getRelevantFeatures(string $query): array {
        // Extract keywords from the query
        $keywords = $this->extractKeywords($query);
        
        $relevantFeatures = [];
        
        // Search for each keyword
        foreach ($keywords as $keyword) {
            $results = $this->searchFeatures($keyword);
            
            // Merge results
            foreach ($results as $category => $categoryFeatures) {
                if (!isset($relevantFeatures[$category])) {
                    $relevantFeatures[$category] = [];
                }
                
                foreach ($categoryFeatures as $name => $data) {
                    $relevantFeatures[$category][$name] = $data;
                }
            }
        }
        
        return $relevantFeatures;
    }

    /**
     * Extract keywords from a query
     * 
     * @param string $query
     * @return array
     */
    private function extractKeywords(string $query): array {
        // Convert to lowercase
        $query = strtolower($query);
        
        // Remove common words
        $commonWords = ['a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'with', 'how', 'what', 'when', 'where', 'why', 'who', 'which', 'is', 'are', 'do', 'does', 'did', 'can', 'could', 'will', 'would', 'should', 'may', 'might', 'must', 'have', 'has', 'had', 'i', 'you', 'he', 'she', 'it', 'we', 'they'];
        
        // Split into words
        $words = preg_split('/\s+/', $query);
        
        // Filter out common words and short words
        $keywords = array_filter($words, function($word) use ($commonWords) {
            return !in_array($word, $commonWords) && strlen($word) > 2;
        });
        
        return array_values($keywords);
    }

    /**
     * Format relevant features for AI prompt
     * 
     * @param array $relevantFeatures
     * @return string
     */
    public function formatFeaturesForPrompt(array $relevantFeatures): string {
        if (empty($relevantFeatures)) {
            return "";
        }
        
        $prompt = "Here are some relevant server features that might help answer the question:\n\n";
        
        foreach ($relevantFeatures as $category => $features) {
            $prompt .= "Category: " . ucfirst($category) . "\n";
            
            foreach ($features as $name => $data) {
                $prompt .= "- " . ucfirst($name) . ":\n";
                
                if (isset($data['description'])) {
                    $prompt .= "  Description: " . $data['description'] . "\n";
                }
                
                if (isset($data['tutorial'])) {
                    $prompt .= "  Tutorial: " . $data['tutorial'] . "\n";
                }
                
                if (isset($data['commands']) && is_array($data['commands'])) {
                    $prompt .= "  Commands:\n";
                    foreach ($data['commands'] as $command => $description) {
                        $prompt .= "    - " . $command . ": " . $description . "\n";
                    }
                }
                
                $prompt .= "\n";
            }
        }
        
        return $prompt;
    }

    /**
     * Save the features to the config file
     */
    public function saveFeatures(): void {
        $this->featureConfig->setAll($this->features);
        $this->featureConfig->save();
    }
}