<?php

declare(strict_types=1);

namespace Renz\AIAssistant\tasks;

use pocketmine\scheduler\BulkCurlTask;
use pocketmine\scheduler\BulkCurlTaskOperation;
use pocketmine\Server;
use Closure;
use pocketmine\utils\InternetRequestResult;
use pocketmine\utils\InternetException;
use Renz\AIAssistant\Main;

class HttpRequestTask {
    private string $url;
    private array $headers;
    private object $callbackObject;
    private string $callbackMethod;
    private string $requestMethod;
    private string $requestBody;
    private string $requestId;
    private float $timeout;
    private string $cainfo_path;
    private int $retryCount = 0;
    private int $maxRetries = 2;
    private ?Main $plugin = null;

    public function __construct(string $url, array $headers, object $callbackObject, string $methodName, string $method = "GET", string $body = "", string $requestId = "", int $timeout = 30, string $cainfo_path = "") {
        $this->url = $url;
        $this->headers = $headers;
        $this->callbackObject = $callbackObject;
        $this->callbackMethod = $methodName;
        $this->requestMethod = strtoupper($method);
        $this->requestBody = $body;
        $this->requestId = $requestId;
        $this->timeout = (float) $timeout;
        $this->cainfo_path = $cainfo_path;
        
        // Get plugin reference for scheduling
        $server = Server::getInstance();
        $this->plugin = $server->getPluginManager()->getPlugin("PMMP-AI");

        // Start the bulk curl task immediately when constructed
        $this->execute();
    }

    /**
     * Mask Authorization header value for safe logging
     */
    private function maskHeadersForLog(array $headers): array {
        $out = [];
        foreach ($headers as $k => $v) {
            // Headers may be given as "Key: value" strings or as assoc ["Key" => "value"]
            if (is_int($k)) {
                $line = $v;
                if (stripos($line, 'authorization:') === 0) {
                    $out[] = 'Authorization: Bearer <REDACTED>';
                } else {
                    $out[] = $line;
                }
            } else {
                $key = $k;
                $val = $v;
                if (strtolower($key) === 'authorization') {
                    $out[] = $key . ': Bearer <REDACTED>';
                } else {
                    $out[] = $key . ': ' . $val;
                }
            }
        }
        return $out;
    }

    /**
     * Normalize headers into an array of "Key: value" strings and trim values.
     */
    private function normalizeHeaders(array $headers): array {
        $normalized = [];
        foreach ($headers as $k => $v) {
            if (is_int($k)) {
                // already a header line (maybe "Key: value")
                $line = trim($v);
                // if no colon, skip
                if (strpos($line, ':') === false) continue;
                [$key, $val] = explode(':', $line, 2);
                $normalized[] = trim($key) . ': ' . trim($val);
            } else {
                $normalized[] = trim($k) . ': ' . trim((string)$v);
            }
        }
        return $normalized;
    }

    public function execute(): void {
        $serverLogger = Server::getInstance()->getLogger();

        // Normalize and trim headers
        $extraHeaders = $this->normalizeHeaders($this->headers);

        // Ensure Content-Type exists if body is JSON
        $bodyIsJson = $this->looksLikeJson($this->requestBody);
        if ($bodyIsJson) {
            $hasContentType = false;
            foreach ($extraHeaders as $h) {
                if (stripos($h, 'content-type:') === 0) {
                    $hasContentType = true;
                    break;
                }
            }
            if (!$hasContentType) {
                $extraHeaders[] = 'Content-Type: application/json';
            }
        }

        // Ensure Authorization header is trimmed and correctly formatted (if exists)
        // convert any "Authorization: Bearer <key>" lines to trimmed version
        foreach ($extraHeaders as $i => $h) {
            if (stripos($h, 'authorization:') === 0) {
                // normalize spacing -> "Authorization: Bearer xxxx..."
                $parts = explode(':', $h, 2);
                $val = trim($parts[1] ?? '');
                $extraHeaders[$i] = 'Authorization: ' . $val;
            }
        }

        // Prepare extra curl options (PocketMine BulkCurlTask compatible)
        $extraOpts = [
            CURLOPT_TIMEOUT => (int)$this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // Force HTTP/1.1 for stability
            CURLOPT_USERAGENT => 'PocketMine-AI-Assistant/1.0',
            CURLOPT_RETURNTRANSFER => true,
            // Let PocketMine handle header parsing automatically
        ];

        // SSL options - always enable for HTTPS
        if (strpos($this->url, 'https://') === 0) {
            $extraOpts[CURLOPT_SSL_VERIFYPEER] = true;
            $extraOpts[CURLOPT_SSL_VERIFYHOST] = 2;

            // Set CA certificate path if provided and exists
            if (!empty($this->cainfo_path) && file_exists($this->cainfo_path)) {
                $extraOpts[CURLOPT_CAINFO] = $this->cainfo_path;
            }
        } else {
            // For HTTP requests, disable SSL verification (not recommended, but kept for completeness)
            $extraOpts[CURLOPT_SSL_VERIFYPEER] = false;
            $extraOpts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        // Performance optimizations (thread-safe for PocketMine async)
        $extraOpts[CURLOPT_DNS_CACHE_TIMEOUT] = 300;
        $extraOpts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4; // Force IPv4 to avoid DNS issues
        $extraOpts[CURLOPT_TCP_KEEPALIVE] = 1;
        $extraOpts[CURLOPT_TCP_KEEPIDLE] = 30;
        $extraOpts[CURLOPT_TCP_KEEPINTVL] = 10;
        
        // DNS bypass for OpenRouter (conditional fallback on retry)
        if ($this->retryCount > 0 && strpos($this->url, 'openrouter.ai') !== false) {
            $extraOpts[CURLOPT_RESOLVE] = ["openrouter.ai:443:104.18.3.115"]; // Use known IP as fallback
        }

        // Remove any conflicting options and ensure compatibility
        unset($extraOpts[CURLOPT_HTTPHEADER]); // BulkCurlTask handles headers separately

        // Configure request method and body
        if ($this->requestMethod === "POST") {
            $extraOpts[CURLOPT_POST] = true;
            if ($this->requestBody !== "") {
                $extraOpts[CURLOPT_POSTFIELDS] = $this->requestBody;
            }
        } elseif ($this->requestMethod !== "GET") {
            $extraOpts[CURLOPT_CUSTOMREQUEST] = $this->requestMethod;
            if ($this->requestBody !== "") {
                $extraOpts[CURLOPT_POSTFIELDS] = $this->requestBody;
            }
        }

        // Log summary (mask Authorization)
        $masked = $this->maskHeadersForLog($extraHeaders);
        $serverLogger->info("[HttpRequestTask] Preparing request:");
        $serverLogger->info("[HttpRequestTask] URL: " . $this->url);
        $serverLogger->info("[HttpRequestTask] Method: " . $this->requestMethod . " Timeout: " . $this->timeout);
        $serverLogger->info("[HttpRequestTask] Headers: " . json_encode($masked));
        $bodySample = $this->requestBody === "" ? "<empty>" : (strlen($this->requestBody) > 1000 ? substr($this->requestBody, 0, 1000) . '... (truncated)' : $this->requestBody);
        // to avoid logging sensitive content in body, only log first 1000 chars
        $serverLogger->info("[HttpRequestTask] Body length: " . strlen($this->requestBody) . " Body sample: " . (is_string($bodySample) ? substr($bodySample, 0, 500) : '<non-string>'));
        $serverLogger->info("[HttpRequestTask] CAINFO: " . ($this->cainfo_path !== "" ? $this->cainfo_path : '<none>'));

        // Create the BulkCurlTaskOperation (PocketMine compatible)
        $operation = new BulkCurlTaskOperation(
            page: $this->url,
            timeout: $this->timeout,
            extraHeaders: $extraHeaders,
            extraOpts: $extraOpts
        );

        // Create completion callback
        $onCompletion = Closure::fromCallable([$this, 'handleCompletion']);

        // Create and submit the BulkCurlTask
        $bulkTask = new BulkCurlTask([$operation], $onCompletion);

        try {
            Server::getInstance()->getAsyncPool()->submitTask($bulkTask);
            $serverLogger->info("[HttpRequestTask] Submitted BulkCurlTask for URL: " . $this->url);
        } catch (\Throwable $t) {
            // If submit fails, immediately trigger callback with error
            $serverLogger->error("[HttpRequestTask] Failed to submit BulkCurlTask: " . $t->getMessage());
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'error' => 'Failed to submit BulkCurlTask: ' . $t->getMessage(),
                'httpCode' => 0
            ]);
        }
    }

    /**
     * Determine if string looks like JSON
     */
    private function looksLikeJson(string $s): bool {
        $sTrim = trim($s);
        return ($sTrim === '') ? false : (($sTrim[0] === '{' || $sTrim[0] === '[') && (json_decode($sTrim) !== null));
    }

    /**
     * Handle completion of the BulkCurlTask
     *
     * @param array $results
     */
    private function handleCompletion(array $results): void {
        $serverLogger = Server::getInstance()->getLogger();

        $result = $results[0] ?? null;

        // Log raw result for debugging (but mask any Authorization if present)
        $safeResults = $results;
        // try to remove Authorization from any nested headers before logging
        array_walk_recursive($safeResults, function (&$v, $k) {
            if (is_string($v) && stripos($v, 'authorization:') === 0) {
                $v = 'Authorization: Bearer <REDACTED>';
            }
        });
        $serverLogger->debug("[HttpRequestTask] Raw completion result (masked): " . json_encode($safeResults));

        if ($result === null) {
            $serverLogger->error("[HttpRequestTask] No result returned from BulkCurlTask");
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'error' => 'No result from BulkCurlTask',
                'httpCode' => 0
            ]);
            return;
        }

        // InternetRequestResult objects (PocketMine-MP 5.x format)
        if ($result instanceof InternetRequestResult) {
            $httpCode = $result->getCode();
            $response = $result->getBody();
            $headers = $result->getHeaders();
            
            $serverLogger->info("[HttpRequestTask] Received InternetRequestResult code=" . $httpCode);
            
            // Check for errors and retry if needed
            if ($httpCode >= 400) {
                $short = strlen($response) > 1000 ? substr($response, 0, 1000) . '... (truncated)' : $response;
                $serverLogger->error("[HttpRequestTask] HTTP " . $httpCode . " Response: " . $short);
                
                // Parse Retry-After header for rate limiting
                $retryAfter = $this->parseRetryAfter($headers);
                
                // Retry on 429 (rate limit) or 5xx server errors
                if ($this->shouldRetry($response, $httpCode)) {
                    $this->retryWithDelay($retryAfter);
                    return;
                }
                
                $this->triggerCallback([
                    'requestId' => $this->requestId,
                    'error' => "HTTP {$httpCode}: " . $short,
                    'httpCode' => $httpCode
                ]);
                return;
            }
            
            // Success response
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'response' => $response,
                'httpCode' => $httpCode,
                'headers' => $headers
            ]);
            return;
        }

        // InternetException objects - retry on DNS/connection errors
        if ($result instanceof InternetException) {
            $errorMsg = $result->getMessage();
            $serverLogger->error("[HttpRequestTask] InternetException: " . $errorMsg);
            
            // Retry on DNS or connection failures
            if ($this->shouldRetry($errorMsg, 0)) {
                $this->retry();
                return;
            }
            
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'error' => 'Internet request failed: ' . $errorMsg,
                'httpCode' => 0
            ]);
            return;
        }

        // If result is an array with error information (common)
        if (is_array($result) && isset($result['error'])) {
            $serverLogger->error("[HttpRequestTask] BulkCurl returned error: " . (is_string($result['error']) ? $result['error'] : json_encode($result['error'])));
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'error' => $result['error'],
                'httpCode' => $result['http_code'] ?? 0
            ]);
            return;
        }

        // If result is a string (successful response)
        if (is_string($result)) {
            $serverLogger->info("[HttpRequestTask] Received string response (len=" . strlen($result) . ")");
            $sample = strlen($result) > 1000 ? substr($result, 0, 1000) . '... (truncated)' : $result;
            $serverLogger->debug("[HttpRequestTask] Response sample: " . $sample);
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'response' => $result,
                'httpCode' => 200,
                'headers' => []
            ]);
            return;
        }

        // Handle array result format (if BulkCurlTask returns structured data)
        if (is_array($result)) {
            $response = $result['body'] ?? $result['response'] ?? '';
            $httpCode = $result['http_code'] ?? $result['httpCode'] ?? 200;
            $headers = $result['headers'] ?? [];

            if (empty($response)) {
                $serverLogger->error("[HttpRequestTask] Empty response from server, httpCode=" . $httpCode);
                $this->triggerCallback([
                    'requestId' => $this->requestId,
                    'error' => 'Empty response from server',
                    'httpCode' => $httpCode
                ]);
                return;
            }

            if ($httpCode >= 400) {
                // log more details for debugging
                $short = strlen($response) > 1000 ? substr($response, 0, 1000) . '... (truncated)' : $response;
                $serverLogger->error("[HttpRequestTask] HTTP " . $httpCode . " Response: " . $short);
                
                // Parse Retry-After header for rate limiting
                $retryAfter = $this->parseRetryAfter($headers ?? []);
                
                // Retry on 429 (rate limit) or 5xx server errors
                if ($this->shouldRetry($response, $httpCode)) {
                    $this->retryWithDelay($retryAfter);
                    return;
                }
                
                $this->triggerCallback([
                    'requestId' => $this->requestId,
                    'error' => "HTTP {$httpCode}: " . $short,
                    'httpCode' => $httpCode
                ]);
                return;
            }

            $serverLogger->info("[HttpRequestTask] HTTP " . $httpCode . " OK, response len=" . strlen($response));
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'response' => $response,
                'httpCode' => $httpCode,
                'headers' => $headers
            ]);
            return;
        }

        // Fallback for unexpected result format
        $resultType = is_object($result) ? get_class($result) : gettype($result);
        $resultDetails = '';
        try {
            if (is_object($result) && method_exists($result, '__toString')) {
                $resultDetails = " Details: " . $result->__toString();
            } elseif (is_array($result)) {
                $resultDetails = " Details: " . json_encode($result);
            } elseif (is_scalar($result)) {
                $resultDetails = " Value: " . (string)$result;
            }
        } catch (\Throwable $t) {
            $resultDetails = " (failed to stringify result)";
        }

        $serverLogger->error("[HttpRequestTask] Unexpected result format: " . $resultType . $resultDetails);
        $this->triggerCallback([
            'requestId' => $this->requestId,
            'error' => 'Unexpected result format from BulkCurlTask: ' . $resultType,
            'httpCode' => 0
        ]);
    }

    /**
     * Check if request should be retried based on error type
     */
    private function shouldRetry(string $errorMsg, int $httpCode): bool {
        if ($this->retryCount >= $this->maxRetries) {
            return false;
        }
        
        // Retry on DNS resolution failures
        if (stripos($errorMsg, 'could not resolve host') !== false || 
            stripos($errorMsg, 'name or service not known') !== false ||
            stripos($errorMsg, 'temporary failure in name resolution') !== false) {
            return true;
        }
        
        // Retry on connection timeouts
        if (stripos($errorMsg, 'timeout') !== false || 
            stripos($errorMsg, 'operation timed out') !== false) {
            return true;
        }
        
        // Retry on rate limiting (429) or server errors (5xx)
        if ($httpCode == 429 || ($httpCode >= 500 && $httpCode < 600)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Parse Retry-After header for rate limiting
     */
    private function parseRetryAfter(array $headers): int {
        foreach ($headers as $header) {
            if (stripos($header, 'retry-after:') === 0) {
                $value = trim(substr($header, 12));
                if (is_numeric($value)) {
                    return min((int)$value, 60); // Max 60 seconds
                }
                // Could also parse HTTP date format, but numeric is most common
            }
        }
        return 0;
    }
    
    /**
     * Retry the request with exponential backoff or Retry-After delay
     */
    private function retryWithDelay(int $retryAfter = 0): void {
        $this->retryCount++;
        
        // Use Retry-After if provided, otherwise exponential backoff
        $backoffSeconds = $retryAfter > 0 ? $retryAfter : min(pow(2, $this->retryCount), 8);
        
        $serverLogger = Server::getInstance()->getLogger();
        $serverLogger->info("[HttpRequestTask] Retrying request #{$this->retryCount} in {$backoffSeconds}s for URL: " . $this->url);
        
        // Schedule retry after backoff delay using plugin scheduler
        if ($this->plugin instanceof Main) {
            $this->plugin->getScheduler()->scheduleDelayedTask(
            new class($this) extends \pocketmine\scheduler\Task {
                private HttpRequestTask $httpTask;
                
                public function __construct(HttpRequestTask $httpTask) {
                    $this->httpTask = $httpTask;
                }
                
                public function onRun(): void {
                    $this->httpTask->execute();
                }
            },
            $backoffSeconds * 20 // Convert to ticks (20 ticks = 1 second)
        );
        } else {
            // Fallback: trigger callback with error if plugin not available
            $this->triggerCallback([
                'requestId' => $this->requestId,
                'error' => 'Plugin not available for retry scheduling',
                'httpCode' => 0
            ]);
        }
    }
    
    /**
     * Legacy retry method (for DNS/connection errors)
     */
    private function retry(): void {
        $this->retryWithDelay(0);
    }

    /**
     * Trigger the callback with the result
     *
     * @param array $result
     */
    private function triggerCallback(array $result): void {
        $server = Server::getInstance();
        $plugin = $server->getPluginManager()->getPlugin("PMMP-AI");

        // Avoid logging API keys: mask Authorization if present
        $maskedForLog = $result;
        if (isset($maskedForLog['response']) && is_string($maskedForLog['response'])) {
            $maskedForLog['response'] = (strlen($maskedForLog['response']) > 2000) ? substr($maskedForLog['response'], 0, 2000) . '... (truncated)' : $maskedForLog['response'];
        }
        $server->getLogger()->debug("[HttpRequestTask] Triggering callback with result (masked): " . json_encode($maskedForLog));

        if ($plugin !== null && method_exists($plugin, $this->callbackMethod)) {
            // Pass the full result including requestId for proper routing
            $plugin->{$this->callbackMethod}($result);
        } elseif (method_exists($this->callbackObject, $this->callbackMethod)) {
            // Direct callback to the provided object
            $this->callbackObject->{$this->callbackMethod}($result);
        } else {
            $server->getLogger()->warning("[HttpRequestTask] No valid callback found for method: " . $this->callbackMethod);
        }
    }
}