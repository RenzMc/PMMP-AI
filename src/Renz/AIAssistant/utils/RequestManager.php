<?php

declare(strict_types=1);

namespace Renz\AIAssistant\utils;

use pocketmine\player\Player;
use Renz\AIAssistant\Main;

class RequestManager {
    /** @var Main */
    private Main $plugin;
    
    /** @var array */
    private array $activeRequests = [];
    
    /** @var array */
    private array $cancelledRequests = [];
    
    /** @var array */
    private array $pendingRequests = [];
    
    /** @var array */
    private array $formContexts = [];
    
    /** @var array */
    private array $readyResponses = [];

    /**
     * RequestManager constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Track a new AI request
     * 
     * @param string $playerName
     * @param string $requestId
     * @param string $query
     * @return void
     */
    public function trackRequest(string $playerName, string $requestId, string $query): void {
        $this->activeRequests[$playerName] = [
            'id' => $requestId,
            'query' => $query,
            'startTime' => microtime(true),
            'status' => 'pending'
        ];
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Started tracking request {$requestId} for player {$playerName}");
        }
    }

    /**
     * Complete a request
     * 
     * @param string $playerName
     * @param string $requestId
     * @return void
     */
    public function completeRequest(string $playerName, string $requestId): void {
        if (isset($this->activeRequests[$playerName]) && $this->activeRequests[$playerName]['id'] === $requestId) {
            $duration = microtime(true) - $this->activeRequests[$playerName]['startTime'];
            
            if ($this->plugin->isDebugEnabled()) {
                $this->plugin->getLogger()->debug("Completed request {$requestId} for player {$playerName} in {$duration} seconds");
            }
            
            unset($this->activeRequests[$playerName]);
        }
    }

    /**
     * Cancel a request
     * 
     * @param string $playerName
     * @return bool True if a request was cancelled, false otherwise
     */
    public function cancelRequest(string $playerName): bool {
        if (isset($this->activeRequests[$playerName])) {
            $requestId = $this->activeRequests[$playerName]['id'];
            $query = $this->activeRequests[$playerName]['query'];
            $duration = microtime(true) - $this->activeRequests[$playerName]['startTime'];
            
            // Mark as cancelled
            $this->cancelledRequests[$requestId] = [
                'playerName' => $playerName,
                'query' => $query,
                'cancelTime' => microtime(true),
                'duration' => $duration
            ];
            
            if ($this->plugin->isDebugEnabled()) {
                $this->plugin->getLogger()->debug("Cancelled request {$requestId} for player {$playerName} after {$duration} seconds");
            }
            
            unset($this->activeRequests[$playerName]);
            return true;
        }
        
        return false;
    }

    /**
     * Backwards-compatible alias for older code that calls cancelPlayerRequests()
     *
     * @param string $playerName
     * @return bool
     */
    public function cancelPlayerRequests(string $playerName): bool {
        return $this->cancelRequest($playerName);
    }

    /**
     * Check if a player has an active request
     * 
     * @param string $playerName
     * @return bool
     */
    public function hasActiveRequest(string $playerName): bool {
        return isset($this->activeRequests[$playerName]);
    }

    /**
     * Check if a request is cancelled
     * 
     * @param string $requestId
     * @return bool
     */
    public function isRequestCancelled(string $requestId): bool {
        return isset($this->cancelledRequests[$requestId]);
    }

    /**
     * Get active request for a player
     * 
     * @param string $playerName
     * @return array|null
     */
    public function getActiveRequest(string $playerName): ?array {
        return $this->activeRequests[$playerName] ?? null;
    }

    /**
     * Generate a unique request ID
     * Accepts any prefix (will be cast to string) to avoid uniqid() errors.
     * 
     * @param mixed|null $prefix
     * @param bool $moreEntropy
     * @return string
     */
    public function generateRequestId($prefix = null, bool $moreEntropy = true): string {
        if ($prefix === null) {
            return uniqid('req_', $moreEntropy);
        }

        // Cast prefix to string to ensure uniqid() always receives a string.
        return uniqid((string)$prefix, $moreEntropy);
    }

    /**
     * Clean up old cancelled requests
     * Accepts non-int input and casts to int to prevent type errors.
     * 
     * @param mixed $maxAge Maximum age in seconds
     * @return void
     */
    public function cleanupCancelledRequests($maxAge = 3600): void {
        $maxAge = (int)$maxAge;
        $now = microtime(true);
        foreach ($this->cancelledRequests as $requestId => $request) {
            if ($now - $request['cancelTime'] > $maxAge) {
                unset($this->cancelledRequests[$requestId]);
            }
        }
    }
    
    /**
     * Store pending request data for async callback
     * 
     * @param string $requestId
     * @param array $data
     * @return void
     */
    public function setPendingRequest(string $requestId, array $data): void {
        $this->pendingRequests[$requestId] = $data;
    }
    
    /**
     * Store a ready response for a player
     * 
     * @param string $playerName
     * @param string $question
     * @param string $response
     * @return void
     */
    public function setReadyResponse(string $playerName, string $question, string $response): void {
        $this->readyResponses[$playerName] = [
            'question' => $question,
            'response' => $response,
            'timestamp' => time()
        ];
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Stored ready response for player {$playerName}");
        }
    }
    
    /**
     * Check if a player has a ready response
     * 
     * @param string $playerName
     * @return bool
     */
    public function hasReadyResponse(string $playerName): bool {
        return isset($this->readyResponses[$playerName]);
    }
    
    /**
     * Get and consume ready response for a player
     * 
     * @param string $playerName
     * @return array|null
     */
    public function consumeReadyResponse(string $playerName): ?array {
        if (isset($this->readyResponses[$playerName])) {
            $response = $this->readyResponses[$playerName];
            unset($this->readyResponses[$playerName]);
            
            if ($this->plugin->isDebugEnabled()) {
                $this->plugin->getLogger()->debug("Consumed ready response for player {$playerName}");
            }
            
            return $response;
        }
        
        return null;
    }
    
    /**
     * Clear ready response for a player without consuming
     * 
     * @param string $playerName
     * @return void
     */
    public function clearReadyResponse(string $playerName): void {
        if (isset($this->readyResponses[$playerName])) {
            unset($this->readyResponses[$playerName]);
            
            if ($this->plugin->isDebugEnabled()) {
                $this->plugin->getLogger()->debug("Cleared ready response for player {$playerName}");
            }
        }
    }
    
    /**
     * Get pending request data for async callback
     * 
     * @param string $requestId
     * @return array|null
     */
    public function getPendingRequest(string $requestId): ?array {
        return $this->pendingRequests[$requestId] ?? null;
    }
    
    /**
     * Remove pending request data after processing
     * 
     * @param string $requestId
     * @return void
     */
    public function removePendingRequest(string $requestId): void {
        unset($this->pendingRequests[$requestId]);
    }

    /**
     * Set form context for a player request
     * 
     * @param string $playerName
     * @param array $context
     * @return void
     */
    public function setFormContext(string $playerName, array $context): void {
        $this->formContexts[$playerName] = $context;
    }

    /**
     * Get form context for a player
     * 
     * @param string $playerName
     * @return array|null
     */
    public function getFormContext(string $playerName): ?array {
        return $this->formContexts[$playerName] ?? null;
    }

    /**
     * Clear form context for a player
     * 
     * @param string $playerName
     * @return void
     */
    public function clearFormContext(string $playerName): void {
        unset($this->formContexts[$playerName]);
    }
}