<?php  
  
declare(strict_types=1);  
  
namespace Renz\AIAssistant\utils;  
  
use pocketmine\utils\Config;  
use Renz\AIAssistant\Main;  
  
class ConversationManager {  
    /** @var Main */  
    private Main $plugin;  
      
    /** @var array */  
    private array $conversations = [];  
      
    /** @var array */  
    private array $sessions = [];  
      
    /** @var int */  
    private int $maxConversationHistory;  
      
    /** @var int */  
    private int $maxSessions;  
  
    /**  
     * ConversationManager constructor.  
     *   
     * @param Main $plugin  
     */  
    public function __construct(Main $plugin) {  
        $this->plugin = $plugin;  
        $this->maxConversationHistory = (int) $plugin->getConfig()->getNested("prompts.max_conversation_history", 10);  
        $this->maxSessions = (int) $plugin->getConfig()->getNested("history.max_sessions", 10);  
          
        // Create history directory if it doesn't exist  
        $historyDir = $plugin->getDataFolder() . "history/";  
        if (!is_dir($historyDir)) {  
            @mkdir($historyDir, 0777, true);  
        }  
    }  
  
    /**  
     * Load a player's conversation history  
     *   
     * @param string $playerName  
     * @param string|null $sessionId  
     * @return array  
     */  
    public function loadConversation(string $playerName, ?string $sessionId = null): array {  
        // If no session ID is provided, use the current session or create a new one  
        if ($sessionId === null) {  
            if (!isset($this->sessions[$playerName]) || empty($this->sessions[$playerName]["current"])) {  
                $this->createNewSession($playerName);  
            }  
            $sessionId = $this->sessions[$playerName]["current"];  
        }  
          
        // Check if the conversation is already loaded  
        if (isset($this->conversations[$playerName][$sessionId])) {  
            return $this->conversations[$playerName][$sessionId];  
        }  
          
        // Load the conversation from file  
        $filePath = $this->getConversationFilePath($playerName, $sessionId);  
        if (file_exists($filePath)) {  
            $config = new Config($filePath, Config::JSON);  
            $this->conversations[$playerName][$sessionId] = $config->getAll();  
        } else {  
            $this->conversations[$playerName][$sessionId] = [];  
        }  
          
        return $this->conversations[$playerName][$sessionId];  
    }  
  
    /**  
     * Save a player's conversation history  
     *   
     * @param string $playerName  
     * @param string|null $sessionId  
     */  
    public function saveConversation(string $playerName, ?string $sessionId = null): void {  
        // If no session ID is provided, use the current session  
        if ($sessionId === null) {  
            if (!isset($this->sessions[$playerName]) || empty($this->sessions[$playerName]["current"])) {  
                return; // No current session to save  
            }  
            $sessionId = $this->sessions[$playerName]["current"];  
        }  
          
        // Check if the conversation exists  
        if (!isset($this->conversations[$playerName][$sessionId])) {  
            return;  
        }  
          
        // Save the conversation to file  
        $filePath = $this->getConversationFilePath($playerName, $sessionId);  
        $config = new Config($filePath, Config::JSON);  
        $config->setAll($this->conversations[$playerName][$sessionId]);  
        $config->save();  
          
        // Update session metadata  
        $this->updateSessionMetadata($playerName, $sessionId);  
    }  
  
    /**  
     * Add a message to a player's conversation history  
     *   
     * @param string $playerName  
     * @param string $query  
     * @param string $response  
     * @param string|null $sessionId  
     */  
    public function addToConversation(string $playerName, string $query, string $response, ?string $sessionId = null): void {  
        // If no session ID is provided, use the current session or create a new one  
        if ($sessionId === null) {  
            if (!isset($this->sessions[$playerName]) || empty($this->sessions[$playerName]["current"])) {  
                $this->createNewSession($playerName);  
            }  
            $sessionId = $this->sessions[$playerName]["current"];  
        }  
          
        // Load the conversation if it's not already loaded  
        if (!isset($this->conversations[$playerName][$sessionId])) {  
            $this->loadConversation($playerName, $sessionId);  
        }  
          
        // Add the message to the conversation  
        $this->conversations[$playerName][$sessionId][] = [  
            "query" => $query,  
            "response" => $response,  
            "timestamp" => time()  
        ];  
          
        // Limit the conversation history  
        $maxMessages = (int) $this->plugin->getConfig()->getNested("history.max_messages_per_session", 50);  
        if (count($this->conversations[$playerName][$sessionId]) > $maxMessages) {  
            $this->conversations[$playerName][$sessionId] = array_slice(  
                $this->conversations[$playerName][$sessionId],  
                -$maxMessages  
            );  
        }  
          
        // Save the conversation  
        $this->saveConversation($playerName, $sessionId);  
    }  
  
    /**  
     * Get a player's conversation history  
     *   
     * @param string $playerName  
     * @param string|null $sessionId  
     * @return array  
     */  
    public function getConversation(string $playerName, ?string $sessionId = null): array {  
        // If no session ID is provided, use the current session or create a new one  
        if ($sessionId === null) {  
            if (!isset($this->sessions[$playerName]) || empty($this->sessions[$playerName]["current"])) {  
                $this->createNewSession($playerName);  
            }  
            $sessionId = $this->sessions[$playerName]["current"];  
        }  
          
        // Load the conversation if it's not already loaded  
        if (!isset($this->conversations[$playerName][$sessionId])) {  
            $this->loadConversation($playerName, $sessionId);  
        }  
          
        // Return only the most recent messages up to the maximum history limit  
        $conversation = $this->conversations[$playerName][$sessionId];  
        if (count($conversation) > $this->maxConversationHistory) {  
            return array_slice($conversation, -$this->maxConversationHistory);  
        }  
          
        return $conversation;  
    }  
  
    /**  
     * Save all loaded conversations  
     */  
    public function saveAllConversations(): void {  
        foreach ($this->conversations as $playerName => $sessions) {  
            foreach ($sessions as $sessionId => $conversation) {  
                $this->saveConversation($playerName, $sessionId);  
            }  
        }  
    }  
  
    /**  
     * Get the file path for a player's conversation history  
     *   
     * @param string $playerName  
     * @param string $sessionId  
     * @return string  
     */  
    private function getConversationFilePath(string $playerName, string $sessionId): string {  
        $playerDir = $this->plugin->getDataFolder() . "history/" . strtolower($playerName) . "/";  
        if (!is_dir($playerDir)) {  
            @mkdir($playerDir, 0777, true);  
        }  
        return $playerDir . $sessionId . ".json";  
    }  
  
    /**  
     * Create a new session for a player  
     *   
     * @param string $playerName  
     * @return string The new session ID  
     */  
    public function createNewSession(string $playerName): string {  
        // Generate a new session ID  
        $sessionId = date("Ymd_His") . "_" . substr(md5(uniqid()), 0, 8);  
          
        // Initialize the sessions array for this player if it doesn't exist  
        if (!isset($this->sessions[$playerName])) {  
            $this->sessions[$playerName] = [  
                "current" => "",  
                "sessions" => []  
            ];  
        }  
          
        // Set the new session as the current session  
        $this->sessions[$playerName]["current"] = $sessionId;  
          
        // Add the session to the list of sessions  
        $this->sessions[$playerName]["sessions"][$sessionId] = [  
            "created" => time(),  
            "last_used" => time(),  
            "message_count" => 0,  
            "title" => "Chat Session " . date("Y-m-d H:i")  
        ];  
          
        // Limit the number of sessions  
        if (count($this->sessions[$playerName]["sessions"]) > $this->maxSessions) {  
            // Find the oldest session  
            $oldestSession = null;  
            $oldestTime = PHP_INT_MAX;  
              
            foreach ($this->sessions[$playerName]["sessions"] as $id => $session) {  
                if ($session["last_used"] < $oldestTime) {  
                    $oldestTime = $session["last_used"];  
                    $oldestSession = $id;  
                }  
            }  
              
            // Remove the oldest session  
            if ($oldestSession !== null) {  
                unset($this->sessions[$playerName]["sessions"][$oldestSession]);  
                  
                // Also remove the conversation file  
                $filePath = $this->getConversationFilePath($playerName, $oldestSession);  
                if (file_exists($filePath)) {  
                    @unlink($filePath);  
                }  
            }  
        }  
          
        // Save the sessions metadata  
        $this->saveSessionsMetadata($playerName);  
          
        // Initialize the conversation for this session  
        $this->conversations[$playerName][$sessionId] = [];  
          
        return $sessionId;  
    }  
  
    /**  
     * Update session metadata  
     *   
     * @param string $playerName  
     * @param string $sessionId  
     */  
    private function updateSessionMetadata(string $playerName, string $sessionId): void {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        // Update the session metadata  
        if (isset($this->sessions[$playerName]["sessions"][$sessionId])) {  
            $this->sessions[$playerName]["sessions"][$sessionId]["last_used"] = time();  
            $this->sessions[$playerName]["sessions"][$sessionId]["message_count"] =   
                isset($this->conversations[$playerName][$sessionId]) ?   
                count($this->conversations[$playerName][$sessionId]) : 0;  
              
            // Auto-generate a title based on the first query if there's no title  
            if (empty($this->sessions[$playerName]["sessions"][$sessionId]["title"]) ||   
                $this->sessions[$playerName]["sessions"][$sessionId]["title"] === "Chat Session " . date("Y-m-d H:i", $this->sessions[$playerName]["sessions"][$sessionId]["created"])) {  
                if (isset($this->conversations[$playerName][$sessionId][0]["query"])) {  
                    $firstQuery = $this->conversations[$playerName][$sessionId][0]["query"];  
                    $title = substr($firstQuery, 0, 30);  
                    if (strlen($firstQuery) > 30) {  
                        $title .= "...";  
                    }  
                    $this->sessions[$playerName]["sessions"][$sessionId]["title"] = $title;  
                }  
            }  
              
            // Save the sessions metadata  
            $this->saveSessionsMetadata($playerName);  
        }  
    }  
  
    /**  
     * Load sessions metadata for a player  
     *   
     * @param string $playerName  
     */  
    public function loadSessionsMetadata(string $playerName): void {  
        $playerDir = $this->plugin->getDataFolder() . "history/" . strtolower($playerName) . "/";  
        if (!is_dir($playerDir)) {  
            @mkdir($playerDir, 0777, true);  
        }  
          
        $filePath = $playerDir . "sessions.json";  
          
        if (file_exists($filePath)) {  
            $config = new Config($filePath, Config::JSON);  
            $this->sessions[$playerName] = $config->getAll();  
        } else {  
            $this->sessions[$playerName] = [  
                "current" => "",  
                "sessions" => []  
            ];  
        }  
    }  
  
    /**  
     * Save sessions metadata for a player  
     *   
     * @param string $playerName  
     */  
    private function saveSessionsMetadata(string $playerName): void {  
        $playerDir = $this->plugin->getDataFolder() . "history/" . strtolower($playerName) . "/";  
        if (!is_dir($playerDir)) {  
            @mkdir($playerDir, 0777, true);  
        }  
          
        $filePath = $playerDir . "sessions.json";  
        $config = new Config($filePath, Config::JSON);  
        $config->setAll($this->sessions[$playerName]);  
        $config->save();  
    }  
  
    /**  
     * Get all sessions for a player  
     *   
     * @param string $playerName  
     * @return array  
     */  
    public function getAllSessions(string $playerName): array {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        return $this->sessions[$playerName]["sessions"];  
    }  
      
    /**  
     * Get sessions metadata for a player  
     *   
     * @param string $playerName  
     * @return array  
     */  
    public function getSessionsMetadata(string $playerName): array {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        return $this->sessions[$playerName]["sessions"] ?? [];  
    }  
  
    /**  
     * Get the current session ID for a player  
     *   
     * @param string $playerName  
     * @return string|null  
     */  
    public function getCurrentSessionId(string $playerName): ?string {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        return $this->sessions[$playerName]["current"] ?? null;  
    }  
  
    /**  
     * Set the current session for a player  
     *   
     * @param string $playerName  
     * @param string $sessionId  
     * @return bool  
     */  
    public function setCurrentSession(string $playerName, string $sessionId): bool {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        // Check if the session exists  
        if (!isset($this->sessions[$playerName]["sessions"][$sessionId])) {  
            return false;  
        }  
          
        // Set the current session  
        $this->sessions[$playerName]["current"] = $sessionId;  
          
        // Update the session's last used time  
        $this->sessions[$playerName]["sessions"][$sessionId]["last_used"] = time();  
          
        // Save the sessions metadata  
        $this->saveSessionsMetadata($playerName);  
          
        return true;  
    }  
  
    /**  
     * Delete a session  
     *   
     * @param string $playerName  
     * @param string $sessionId  
     * @return bool  
     */  
    public function deleteSession(string $playerName, string $sessionId): bool {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        // Check if the session exists  
        if (!isset($this->sessions[$playerName]["sessions"][$sessionId])) {  
            return false;  
        }  
          
        // Remove the session from the list  
        unset($this->sessions[$playerName]["sessions"][$sessionId]);  
          
        // If this was the current session, set the current session to the most recent one  
        if ($this->sessions[$playerName]["current"] === $sessionId) {  
            if (empty($this->sessions[$playerName]["sessions"])) {  
                $this->sessions[$playerName]["current"] = "";  
            } else {  
                // Find the most recently used session  
                $mostRecentSession = null;  
                $mostRecentTime = 0;  
                  
                foreach ($this->sessions[$playerName]["sessions"] as $id => $session) {  
                    if ($session["last_used"] > $mostRecentTime) {  
                        $mostRecentTime = $session["last_used"];  
                        $mostRecentSession = $id;  
                    }  
                }  
                  
                $this->sessions[$playerName]["current"] = $mostRecentSession;  
            }  
        }  
          
        // Save the sessions metadata  
        $this->saveSessionsMetadata($playerName);  
          
        // Remove the conversation file  
        $filePath = $this->getConversationFilePath($playerName, $sessionId);  
        if (file_exists($filePath)) {  
            @unlink($filePath);  
        }  
          
        // Remove from memory if loaded  
        if (isset($this->conversations[$playerName][$sessionId])) {  
            unset($this->conversations[$playerName][$sessionId]);  
        }  
          
        return true;  
    }  
  
    /**  
     * Rename a session  
     *   
     * @param string $playerName  
     * @param string $sessionId  
     * @param string $newTitle  
     * @return bool  
     */  
    public function renameSession(string $playerName, string $sessionId, string $newTitle): bool {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        // Check if the session exists  
        if (!isset($this->sessions[$playerName]["sessions"][$sessionId])) {  
            return false;  
        }  
          
        // Update the session title  
        $this->sessions[$playerName]["sessions"][$sessionId]["title"] = $newTitle;  
          
        // Save the sessions metadata  
        $this->saveSessionsMetadata($playerName);  
          
        return true;  
    }  
  
    /**  
     * Search for messages in a player's conversation history  
     *   
     * @param string $playerName  
     * @param string $query  
     * @return array  
     */  
    public function searchConversations(string $playerName, string $query): array {  
        $results = [];  
          
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        // Search in each session  
        foreach ($this->sessions[$playerName]["sessions"] as $sessionId => $sessionData) {  
            // Load the conversation if it's not already loaded  
            if (!isset($this->conversations[$playerName][$sessionId])) {  
                $this->loadConversation($playerName, $sessionId);  
            }  
              
            // Search in this conversation  
            $sessionResults = [];  
            foreach ($this->conversations[$playerName][$sessionId] as $index => $message) {  
                if (stripos($message["query"], $query) !== false || stripos($message["response"], $query) !== false) {  
                    $sessionResults[] = [  
                        "index" => $index,  
                        "message" => $message  
                    ];  
                }  
            }  
              
            // If we found any results in this session, add them to the overall results  
            if (!empty($sessionResults)) {  
                $results[$sessionId] = [  
                    "title" => $sessionData["title"],  
                    "created" => $sessionData["created"],  
                    "last_used" => $sessionData["last_used"],  
                    "message_count" => $sessionData["message_count"],  
                    "matches" => $sessionResults  
                ];  
            }  
        }  
          
        return $results;  
    }  
      
    /**  
     * Clear a player's conversation history  
     *   
     * @param string $playerName  
     * @return bool  
     */  
    public function clearConversation(string $playerName): bool {  
        // Make sure the sessions array is initialized  
        if (!isset($this->sessions[$playerName])) {  
            $this->loadSessionsMetadata($playerName);  
        }  
          
        $currentSessionId = $this->getCurrentSessionId($playerName);  
        if ($currentSessionId === null) {  
            return false;  
        }  
          
        // Clear the current conversation  
        if (isset($this->conversations[$playerName][$currentSessionId])) {  
            $this->conversations[$playerName][$currentSessionId] = [];  
              
            // Save the empty conversation  
            $filePath = $this->getConversationFilePath($playerName, $currentSessionId);  
            $config = new Config($filePath, Config::JSON);  
            $config->setAll([]);  
            $config->save();  
              
            // Update session metadata  
            if (isset($this->sessions[$playerName]["sessions"][$currentSessionId])) {  
                $this->sessions[$playerName]["sessions"][$currentSessionId]["message_count"] = 0;  
                $this->saveSessionsMetadata($playerName);  
            }  
              
            return true;  
        }  
          
        return false;  
    }  
}