<?php

declare(strict_types=1);

namespace Renz\AIAssistant\providers;

interface AIProvider {
    /**
     * Generate a response from the AI
     * 
     * @param string $query The user's query
     * @param array $conversationHistory Previous conversation history
     * @param string $systemPrompt The system prompt to use
     * @param string $serverInfo Additional server information
     * @param string $serverFeatures Relevant server features for the query
     * @return string The AI's response
     */
    public function generateResponse(string $query, array $conversationHistory, string $systemPrompt, string $serverInfo, string $serverFeatures = ""): string;
    
    /**
     * Get the provider name
     * 
     * @return string
     */
    public function getName(): string;
    
    /**
     * Get the provider description
     * 
     * @return string
     */
    public function getDescription(): string;
    
    /**
     * Check if the provider is properly configured
     * 
     * @return bool
     */
    public function isConfigured(): bool;
}