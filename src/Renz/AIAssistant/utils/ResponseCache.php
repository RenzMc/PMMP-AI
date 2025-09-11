<?php

declare(strict_types=1);

namespace Renz\AIAssistant\utils;

use Renz\AIAssistant\Main;

class ResponseCache {
    /** @var Main */
    private Main $plugin;
    
    /** @var array */
    private array $cache = [];
    
    /** @var int */
    private int $cacheDuration;

    /**
     * ResponseCache constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->cacheDuration = (int) $plugin->getConfig()->getNested("advanced.cache_duration", 3600);
        $this->loadCache();
    }

    /**
     * Load cache from file
     */
    private function loadCache(): void {
        $cacheFile = $this->plugin->getDataFolder() . "response_cache.json";
        
        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            
            if (is_array($cacheData)) {
                // Filter out expired cache entries
                $currentTime = time();
                foreach ($cacheData as $key => $entry) {
                    if ($entry["expires"] > $currentTime) {
                        $this->cache[$key] = $entry;
                    }
                }
                
                if ($this->plugin->isDebugEnabled()) {
                    $this->plugin->getLogger()->debug("Loaded " . count($this->cache) . " valid cache entries");
                }
            }
        }
    }

    /**
     * Save cache to file
     */
    public function saveCache(): void {
        $cacheFile = $this->plugin->getDataFolder() . "response_cache.json";
        file_put_contents($cacheFile, json_encode($this->cache));
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Saved " . count($this->cache) . " cache entries");
        }
    }

    /**
     * Get a cached response for a query
     * 
     * @param string $query
     * @return string|null
     */
    public function getResponse(string $query): ?string {
        // Generate a cache key
        $key = $this->generateCacheKey($query);
        
        // Check if the key exists in the cache
        if (isset($this->cache[$key])) {
            $entry = $this->cache[$key];
            
            // Check if the entry has expired
            if ($entry["expires"] > time()) {
                return $entry["response"];
            } else {
                // Remove expired entry
                unset($this->cache[$key]);
            }
        }
        
        return null;
    }

    /**
     * Cache a response for a query
     * 
     * @param string $query
     * @param string $response
     */
    public function cacheResponse(string $query, string $response): void {
        // Generate a cache key
        $key = $this->generateCacheKey($query);
        
        // Store the response in the cache
        $this->cache[$key] = [
            "query" => $query,
            "response" => $response,
            "expires" => time() + $this->cacheDuration
        ];
        
        // Save the cache periodically (every 10 entries)
        if (count($this->cache) % 10 === 0) {
            $this->saveCache();
        }
    }

    /**
     * Generate a cache key for a query
     * 
     * @param string $query
     * @return string
     */
    private function generateCacheKey(string $query): string {
        // Normalize the query (lowercase, trim whitespace)
        $normalizedQuery = strtolower(trim($query));
        
        // Generate a hash of the normalized query
        return md5($normalizedQuery);
    }

    /**
     * Clear the cache
     */
    public function clearCache(): void {
        $this->cache = [];
        $this->saveCache();
    }

    /**
     * Get the number of cached responses
     * 
     * @return int
     */
    public function getCacheSize(): int {
        return count($this->cache);
    }
}