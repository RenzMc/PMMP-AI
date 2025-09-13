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
     * @param string $response
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
     * 
     * @return string
     */
    public function generateRequestId(): string {
        return uniqid('req_', true);
    }

    /**
     * Clean up old cancelled requests
     * 
     * @param int $maxAge Maximum age in seconds
     * @return void
     */
    public function cleanupCancelledRequests(int $maxAge = 3600): void {
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