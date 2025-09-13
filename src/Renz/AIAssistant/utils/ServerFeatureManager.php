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
            $this->plugin->getLogger()->debug("Loaded " . count($this->features) . " server feature categories");
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
     * Search for features by keyword (existing helper - returns all matches)
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
     * 100% PURE YAML MATCHING - NO HARDCODED PATTERNS
     * Dynamically extracts ALL keywords from YAML and matches against user query
     *
     * @param string $query
     * @return array keyed by category => [featureName => data, ...]
     */
    public function getRelevantFeaturesArray(string $query): array {
        // Normalize user query
        $normalizedQuery = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
        
        // Extract all meaningful words from query (2+ chars, no punctuation)
        $queryWords = array_filter(
            preg_split('/[\s\-_.,!?;:()\[\]\/\\\\]+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY),
            function($word) {
                return mb_strlen($word) >= 2 && !is_numeric($word);
            }
        );

        if (empty($queryWords)) {
            return [];
        }

        $relevantFeatures = [];
        $featureScores = [];

        // Process each feature in YAML
        foreach ($this->features as $category => $categoryFeatures) {
            foreach ($categoryFeatures as $featureName => $featureData) {
                $score = $this->calculatePureYamlScore($queryWords, $normalizedQuery, $category, $featureName, $featureData);
                
                if ($score > 0) {
                    if (!isset($relevantFeatures[$category])) {
                        $relevantFeatures[$category] = [];
                    }
                    $relevantFeatures[$category][$featureName] = $featureData;
                    $featureScores[$category . '::' . $featureName] = $score;
                }
            }
        }

        // Sort by relevance score
        arsort($featureScores);
        
        // Reorder results based on scores
        $sortedFeatures = [];
        foreach ($featureScores as $key => $score) {
            list($cat, $name) = explode('::', $key, 2);
            if (!isset($sortedFeatures[$cat])) {
                $sortedFeatures[$cat] = [];
            }
            $sortedFeatures[$cat][$name] = $relevantFeatures[$cat][$name];
        }

        return $sortedFeatures;
    }

    /**
     * Calculate score based PURELY on YAML content - NO HARDCODED LOGIC
     * Dynamically extracts keywords from ALL YAML fields
     *
     * @param array $queryWords
     * @param string $fullQuery
     * @param string $category
     * @param string $featureName
     * @param array $featureData
     * @return float
     */
    private function calculatePureYamlScore(array $queryWords, string $fullQuery, string $category, string $featureName, array $featureData): float {
        $score = 0.0;
        
        // Extract ALL text content from YAML dynamically
        $yamlContent = $this->extractAllYamlText($category, $featureName, $featureData);
        
        // Score based on word matches
        foreach ($queryWords as $queryWord) {
            $wordScore = $this->calculateWordScore($queryWord, $yamlContent);
            $score += $wordScore;
        }
        
        // Bonus for full phrase matches
        $phraseScore = $this->calculatePhraseScore($fullQuery, $yamlContent);
        $score += $phraseScore;
        
        return $score;
    }

    /**
     * Extract ALL text content from YAML feature data dynamically
     * This method recursively processes ANY structure in YAML
     *
     * @param string $category
     * @param string $featureName  
     * @param array $featureData
     * @return array
     */
    private function extractAllYamlText(string $category, string $featureName, array $featureData): array {
        $content = [
            'category' => $this->normalizeText($category),
            'feature_name' => $this->normalizeText($featureName)
        ];
        
        // Recursively extract ALL text from YAML structure
        $content = array_merge($content, $this->recursiveTextExtraction($featureData));
        
        return array_filter($content, function($text) {
            return !empty($text);
        });
    }

    /**
     * Recursively extract text from ANY YAML structure
     * Works with nested arrays, objects, any data type
     *
     * @param mixed $data
     * @param string $prefix
     * @return array
     */
    private function recursiveTextExtraction($data, string $prefix = ''): array {
        $extracted = [];
        
        if (is_string($data)) {
            $key = $prefix ?: 'text_' . count($extracted);
            $extracted[$key] = $this->normalizeText($data);
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $newPrefix = $prefix ? $prefix . '_' . $key : $key;
                
                if (is_string($value)) {
                    $extracted[$newPrefix] = $this->normalizeText($value);
                } elseif (is_array($value)) {
                    // Recursively process nested arrays
                    $nested = $this->recursiveTextExtraction($value, $newPrefix);
                    $extracted = array_merge($extracted, $nested);
                } else {
                    // Convert other types to string
                    $extracted[$newPrefix] = $this->normalizeText((string)$value);
                }
            }
        } else {
            // Handle other data types
            $key = $prefix ?: 'data_' . count($extracted);
            $extracted[$key] = $this->normalizeText((string)$data);
        }
        
        return $extracted;
    }

    /**
     * Normalize text for consistent matching
     *
     * @param string $text
     * @return string
     */
    private function normalizeText(string $text): string {
        // Convert to lowercase
        $normalized = mb_strtolower($text);
        
        // Replace underscores and hyphens with spaces
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        
        // Remove extra spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return trim($normalized);
    }

    /**
     * Calculate word matching score against YAML content
     *
     * @param string $queryWord
     * @param array $yamlContent
     * @return float
     */
    private function calculateWordScore(string $queryWord, array $yamlContent): float {
        $wordScore = 0.0;
        $escapedWord = preg_quote($queryWord, '/');
        
        foreach ($yamlContent as $fieldName => $fieldContent) {
            if (empty($fieldContent)) continue;
            
            // Exact word boundary match
            if (preg_match('/\b' . $escapedWord . '\b/u', $fieldContent)) {
                $fieldWeight = $this->getFieldWeight($fieldName);
                $wordScore += $fieldWeight * 2.0; // Exact match bonus
            }
            // Partial match
            elseif (mb_strpos($fieldContent, $queryWord) !== false) {
                $fieldWeight = $this->getFieldWeight($fieldName);
                $wordScore += $fieldWeight * 1.0;
            }
            // Fuzzy match for longer words
            elseif (mb_strlen($queryWord) > 3) {
                $similarity = $this->calculateStringSimilarity($queryWord, $fieldContent);
                if ($similarity > 0.7) {
                    $fieldWeight = $this->getFieldWeight($fieldName);
                    $wordScore += $fieldWeight * $similarity * 0.5;
                }
            }
        }
        
        return $wordScore;
    }

    /**
     * Calculate phrase matching score
     *
     * @param string $fullQuery
     * @param array $yamlContent
     * @return float
     */
    private function calculatePhraseScore(string $fullQuery, array $yamlContent): float {
        $phraseScore = 0.0;
        $escapedQuery = preg_quote($fullQuery, '/');
        
        foreach ($yamlContent as $fieldName => $fieldContent) {
            if (empty($fieldContent)) continue;
            
            // Exact phrase match
            if (mb_strpos($fieldContent, $fullQuery) !== false) {
                $fieldWeight = $this->getFieldWeight($fieldName);
                $phraseScore += $fieldWeight * 5.0; // High bonus for phrase match
            }
            // Partial phrase similarity
            else {
                $similarity = $this->calculateStringSimilarity($fullQuery, $fieldContent);
                if ($similarity > 0.6) {
                    $fieldWeight = $this->getFieldWeight($fieldName);
                    $phraseScore += $fieldWeight * $similarity * 2.0;
                }
            }
        }
        
        return $phraseScore;
    }

    /**
     * Get field weight based on field name/type
     * Dynamically determines importance based on common YAML field patterns
     *
     * @param string $fieldName
     * @return float
     */
    private function getFieldWeight(string $fieldName): float {
        // Dynamic weight calculation based on field name patterns
        $fieldName = mb_strtolower($fieldName);
        
        // Higher weight for core identification fields
        if ($fieldName === 'feature_name' || $fieldName === 'name') return 3.0;
        if ($fieldName === 'category') return 2.5;
        
        // Weight based on field name patterns (no hardcoding specific fields)
        if (mb_strpos($fieldName, 'command') !== false) return 2.8;
        if (mb_strpos($fieldName, 'description') !== false) return 2.0;
        if (mb_strpos($fieldName, 'tutorial') !== false) return 1.8;
        if (mb_strpos($fieldName, 'title') !== false) return 2.2;
        if (mb_strpos($fieldName, 'info') !== false) return 1.5;
        if (mb_strpos($fieldName, 'help') !== false) return 1.7;
        
        // Default weight for any other field
        return 1.0;
    }

    /**
     * Calculate string similarity using multiple algorithms
     *
     * @param string $str1
     * @param string $str2
     * @return float
     */
    private function calculateStringSimilarity(string $str1, string $str2): float {
        if (empty($str1) || empty($str2)) return 0.0;
        
        // Use levenshtein for short strings, similar_text for longer ones
        if (mb_strlen($str1) < 50 && mb_strlen($str2) < 50) {
            $maxLen = max(mb_strlen($str1), mb_strlen($str2));
            if ($maxLen === 0) return 0.0;
            
            $distance = levenshtein($str1, $str2);
            return 1.0 - ($distance / $maxLen);
        } else {
            similar_text($str1, $str2, $percent);
            return $percent / 100.0;
        }
    }

    /**
     * Get relevant features for an AI query - returns formatted string
     * Limits to max 5 features total and formats using formatFeaturesForPrompt()
     *
     * @param string $query
     * @return string
     */
    public function getRelevantFeatures(string $query): string {
        $features = $this->getRelevantFeaturesArray($query);

        if (empty($features)) {
            return "No server features found matching your query based on the current configuration.";
        }

        // Limit total returned features to 5 for optimal AI processing
        $limited = [];
        $count = 0;
        foreach ($features as $cat => $catFeatures) {
            foreach ($catFeatures as $name => $data) {
                if (!isset($limited[$cat])) $limited[$cat] = [];
                $limited[$cat][$name] = $data;
                $count++;
                if ($count >= 5) break 2;
            }
        }

        return $this->formatFeaturesForPrompt($limited);
    }

    /**
     * Format relevant features for AI prompt - Clean format for API consumption
     * 
     * @param array $relevantFeatures keyed by category => [name => data]
     * @return string
     */
    public function formatFeaturesForPrompt(array $relevantFeatures): string {
        if (empty($relevantFeatures)) {
            return "";
        }
        
        $prompt = "RELEVANT SERVER FEATURES:\n\n";
        
        foreach ($relevantFeatures as $category => $features) {
            $categoryName = strtoupper(str_replace('_', ' ', $category));
            $prompt .= "CATEGORY: {$categoryName}\n";
            $prompt .= str_repeat("-", strlen("CATEGORY: {$categoryName}")) . "\n";
            
            foreach ($features as $name => $data) {
                $featureName = ucwords(str_replace('_', ' ', $name));
                $prompt .= "\nFeature: {$featureName}\n";
                
                // Dynamically format ALL data from YAML
                $formattedData = $this->formatFeatureDataClean($data);
                $prompt .= $formattedData;
            }
            
            $prompt .= "\n";
        }
        
        return trim($prompt);
    }

    /**
     * Clean formatting for feature data - optimized for AI API consumption
     * Handles ANY structure in YAML without hardcoding field names
     *
     * @param mixed $data
     * @param int $level
     * @return string
     */
    private function formatFeatureDataClean($data, int $level = 0): string {
        $formatted = '';
        $indent = str_repeat('  ', $level);
        
        if (is_string($data)) {
            return $indent . $data . "\n";
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $keyFormatted = ucwords(str_replace('_', ' ', (string)$key));
                
                if (is_string($value)) {
                    $formatted .= $indent . "{$keyFormatted}: {$value}\n";
                } elseif (is_array($value)) {
                    $formatted .= $indent . "{$keyFormatted}:\n";
                    
                    // Handle command-like arrays specially for better readability
                    if ($this->isCommandLikeArray($value)) {
                        foreach ($value as $subKey => $subValue) {
                            $formatted .= $indent . "  * {$subKey} - {$subValue}\n";
                        }
                    } else {
                        $formatted .= $this->formatFeatureDataClean($value, $level + 1);
                    }
                } else {
                    $formatted .= $indent . "{$keyFormatted}: " . $this->convertToString($value) . "\n";
                }
            }
        } else {
            $formatted .= $indent . $this->convertToString($data) . "\n";
        }
        
        return $formatted;
    }

    /**
     * Check if array looks like command definitions
     *
     * @param array $array
     * @return bool
     */
    private function isCommandLikeArray(array $array): bool {
        foreach ($array as $key => $value) {
            if (is_string($key) && is_string($value)) {
                // Check if key looks like a command (starts with / or contains command-like patterns)
                if (strpos($key, '/') === 0 || 
                    strpos($key, '<') !== false || 
                    strpos($key, '>') !== false ||
                    strpos(strtolower($key), 'command') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Convert any data type to clean string representation
     *
     * @param mixed $value
     * @return string
     */
    private function convertToString($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            return 'null';
        } elseif (is_array($value)) {
            return '[' . implode(', ', array_map([$this, 'convertToString'], $value)) . ']';
        } else {
            return (string)$value;
        }
    }

    /**
     * Save the features to the config file
     */
    public function saveFeatures(): void {
        $this->featureConfig->setAll($this->features);
        $this->featureConfig->save();
    }
}
