<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;
use Renz\AIAssistant\forms\MainForm;
use Renz\AIAssistant\forms\ChatForm;

class ResponseForm {
    /** @var Main */
    private Main $plugin;

    /**
     * ResponseForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

   /**
 * Send an AI response form to a player.
 *
 * @param Player $player
 * @param string $question
 * @param string $response
 * @param int $currentPage
 * @param array $pages
 */
   public function sendTo(Player $player, string $question, string $response, int $currentPage = 0, array $pages = []): void {
    // Debug logging
    $this->plugin->getLogger()->debug("ResponseForm::sendTo - Player: " . $player->getName() . ", Page: " . $currentPage);

    // Enhanced loading state detection
    $isLoading = strpos($response, "Processing") !== false ||
                 strpos($response, "Please wait") !== false ||
                 strpos($response, "Generating") !== false ||
                 strpos($response, "Loading") !== false;

    // Enhanced error state detection
    $isError = (strpos($response, "§c") === 0) ||
               (strpos($response, "error") !== false) ||
               (strpos($response, "failed") !== false) ||
               (strpos($response, "Error:") !== false) ||
               (strpos($response, "Failed:") !== false);

    // Debug response state
    $this->plugin->getLogger()->debug(
        "ResponseForm state - IsLoading: " . ($isLoading ? 'true' : 'false') .
        ", IsError: " . ($isError ? 'true' : 'false') .
        ", Response length: " . strlen($response) .
        ", First 100 chars: " . substr($response, 0, min(100, strlen($response)))
    );

    // Ensure response content is properly formatted and not empty
    if (empty(trim($response))) {
        $response = $this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed");
        $isError = true;
    }

    // Improved request management - cancel if error or final (kept consistent with original intent)
    if ($isError) {
        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
    } elseif (!$isLoading) {
        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
    }
    // (Note: both branches call cancelPlayerRequests; kept intentionally per original logic)

    // Split response into pages if it's too long and not already paginated
    if (empty($pages) && !$isLoading && !$isError) {
        $pages = $this->splitResponseIntoPages($response);
    } elseif (empty($pages)) {
        $pages = [$response]; // Single page for loading/error states
    }

    // Fallback for empty pages
    if (empty($pages)) {
        $pages = [$response];
        $this->plugin->getLogger()->warning("ResponseForm: pages array was empty, using fallback");
    }

    $totalPages = count($pages);
    $currentResponse = $pages[$currentPage] ?? $pages[0] ?? $response; // Triple fallback

    $this->plugin->getLogger()->debug("ResponseForm: Total pages: $totalPages, Current response length: " . strlen($currentResponse));

    // Create button mapping for dynamic handling
    $buttonMap = [];
    $buttonIndex = 0;

    // Create the SimpleForm with callback. We pass $buttonMap by reference so callback can read mapping.
    $form = new SimpleForm(function(Player $player, ?int $data) use ($question, $response, $currentPage, $pages, $totalPages, &$buttonMap) {
        if ($data === null) {
            return;
        }

        $this->plugin->getLogger()->debug("ResponseForm: Button clicked - Index: $data, Action: " . ($buttonMap[$data] ?? 'unknown'));

        $action = $buttonMap[$data] ?? 'back';

        switch ($action) {
            case 'back':
                $mainForm = new MainForm($this->plugin);
                $mainForm->sendTo($player);
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "main_menu");
                break;

            case 'next_page':
                $responseFormNext = new ResponseForm($this->plugin);
                $responseFormNext->sendTo($player, $question, $response, max(0, $currentPage + 1), $pages);
                break;

            case 'prev_page':
                $responseFormPrev = new ResponseForm($this->plugin);
                $responseFormPrev->sendTo($player, $question, $response, max(0, $currentPage - 1), $pages);
                break;

            case 'ask_another':
                $this->askAnotherQuestion($player);
                break;

            case 'retry':
                $this->retryQuestion($player, $question);
                break;

            default:
                // Unknown action fallback
                $this->plugin->getLogger()->warning("ResponseForm: Unknown action requested: " . $action);
                break;
        }
    });

    // Title and prefixes / formatting
    $title = $this->plugin->getFormSetting("response_form.title", "AI Response");
    $questionPrefix = $this->plugin->getFormSetting("response_form.question_prefix", "Question: ");
    $responsePrefix = $this->plugin->getFormSetting("response_form.response_prefix", "Response: ");

    $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
    $title = $this->plugin->formatFormText($titleFormat . $title);

    // Add page indicator to title if multiple pages
    if ($totalPages > 1) {
        $title .= " (" . ($currentPage + 1) . "/" . $totalPages . ")";
    }

    // Enhanced response formatting (assumes formatResponseContent exists)
    $formattedResponse = $this->formatResponseContent($currentResponse, $isError, $isLoading);

    // Truncate response if too long for SimpleForm
    $maxContentLength = 1500;
    if (strlen($formattedResponse) > $maxContentLength) {
        $formattedResponse = mb_substr($formattedResponse, 0, $maxContentLength) . "\n\n... (content truncated, use pagination)";
        $this->plugin->getLogger()->debug("ResponseForm: Content truncated from " . strlen($currentResponse) . " to " . strlen($formattedResponse) . " chars");
    }

    // Format content based on state
    $questionColor = MinecraftTextFormatter::COLOR_YELLOW;
    $responseColor = $isError ? MinecraftTextFormatter::COLOR_RED :
                     ($isLoading ? MinecraftTextFormatter::COLOR_AQUA : MinecraftTextFormatter::COLOR_GREEN);

    $content = $questionColor . $questionPrefix . MinecraftTextFormatter::COLOR_WHITE . $question . "\n\n" .
               $responseColor . $responsePrefix . "\n" . $formattedResponse;

    // Final content length check and truncate question if needed
    if (strlen($content) > 2000) {
        $maxQuestionLength = 200;
        $truncatedQuestion = mb_substr($question, 0, $maxQuestionLength) . (strlen($question) > $maxQuestionLength ? "..." : "");
        $content = $questionColor . $questionPrefix . MinecraftTextFormatter::COLOR_WHITE . $truncatedQuestion . "\n\n" .
                   $responseColor . $responsePrefix . "\n" . $formattedResponse;
    }

    $form->setTitle($title);
    $form->setContent($content);

    // Dynamic button mapping - Always add back button first
    $backText = $this->plugin->getFormSetting("response_form.buttons.back.text", "Back to Main Menu");
    $backColor = $this->plugin->getFormSetting("response_form.buttons.back.color", "&9");
    $backTexture = $this->plugin->getFormSetting("response_form.buttons.back.texture", "textures/ui/arrow_left");
    $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
    $buttonMap[$buttonIndex++] = 'back';

    // Add navigation and action buttons based on state
    if (!$isLoading) {
        // Pagination buttons (if multiple pages exist)
        if ($totalPages > 1) {
            if ($currentPage < $totalPages - 1) {
                $form->addButton("§aNext Page", 0, "textures/ui/arrow_right");
                $buttonMap[$buttonIndex++] = 'next_page';
            }

            if ($currentPage > 0) {
                $form->addButton("§ePrevious Page", 0, "textures/ui/arrow_left");
                $buttonMap[$buttonIndex++] = 'prev_page';
            }
        }

        // Ask another question button (always show if not loading)
        $newQuestionText = $this->plugin->getFormSetting("response_form.buttons.new_question.text", "Ask Another Question");
        $newQuestionColor = $this->plugin->getFormSetting("response_form.buttons.new_question.color", "&a");
        $newQuestionTexture = $this->plugin->getFormSetting("response_form.buttons.new_question.texture", "textures/ui/chat_icon");
        $form->addButton($this->plugin->formatFormText($newQuestionColor . $newQuestionText), 0, $newQuestionTexture);
        $buttonMap[$buttonIndex++] = 'ask_another';

        // Retry button (show if error occurred)
        if ($isError) {
            $form->addButton("§6Retry Question", 0, "textures/ui/refresh");
            $buttonMap[$buttonIndex++] = 'retry';
        }
    }

    // Debug button mapping
    $this->plugin->getLogger()->debug("ResponseForm: Button mapping - " . json_encode($buttonMap));

    // Add to conversation history only on first page and final response
    if ($currentPage === 0 && !$isLoading && !$isError) {
        $this->plugin->getConversationManager()->addToConversation($player->getName(), $question, $response);
    }

    // Adjusted delay based on state (ticks)
    $delay = $isLoading ? 5 : 10; // ticks

    $this->plugin->getScheduler()->scheduleDelayedTask(
        new \pocketmine\scheduler\ClosureTask(function() use ($form, $player, $currentPage, $isLoading): void {
            if ($player->isOnline()) {
                $this->plugin->getLogger()->debug("Sending ResponseForm to " . $player->getName() . " page " . $currentPage . " (loading: " . ($isLoading ? 'yes' : 'no') . ")");
                $form->sendToPlayer($player);
            } else {
                $this->plugin->getLogger()->warning("Player " . $player->getName() . " went offline before form could be sent");
            }
        }),
        $delay
    );
}
    
    /**
     * ADDED: Update form with final response (called by provider when response is ready)
     * 
     * @param Player $player
     * @param string $question
     * @param string $finalResponse
     */
    public function updateWithFinalResponse(Player $player, string $question, string $finalResponse): void {
        $this->plugin->getLogger()->debug("Updating ResponseForm with final response for " . $player->getName());
        
        // Force close current form first
        $player->removeCurrentWindow();
        
        // Wait a bit then send new form with final response
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
            function() use ($player, $question, $finalResponse): void {
                if ($player->isOnline()) {
                    $this->sendTo($player, $question, $finalResponse);
                } else {
                    $this->plugin->getLogger()->warning("Player " . $player->getName() . " went offline during response update");
                }
            }
        ), 15); // Longer delay to ensure form is properly closed
    }
    
    /**
     * ADDED: Format response content based on state
     * 
     * @param string $response
     * @param bool $isError
     * @param bool $isLoading
     * @return string
     */
    private function formatResponseContent(string $response, bool $isError, bool $isLoading): string {
        // Ensure response content is properly formatted and visible
        $formattedResponse = trim($response);
        
        if (empty($formattedResponse)) {
            $formattedResponse = $this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed");
        }
        
        // Apply text formatting if needed
        if (!str_contains($formattedResponse, "§")) {
            $formattedResponse = MinecraftTextFormatter::formatText($formattedResponse);
        }
        
        // Add status indicators (no emojis, just text)
        if ($isLoading) {
            $formattedResponse = "§b[LOADING] " . $formattedResponse;
        } elseif ($isError) {
            $formattedResponse = "§c[ERROR] " . $formattedResponse;
        } else {
            $formattedResponse = "§a[COMPLETE] " . $formattedResponse;
        }
        
        return $formattedResponse;
    }
    
    /**
     * Split response into pages based on character limit
     * 
     * @param string $response
     * @return array
     */
    private function splitResponseIntoPages(string $response): array {
        $maxCharsPerPage = 1200; // Reduced limit for safety
        $pages = [];
        
        if (strlen($response) <= $maxCharsPerPage) {
            return [$response];
        }
        
        // Split by sentences first, then by character limit
        $sentences = preg_split('/(?<=[.!?])\s+/', $response, -1, PREG_SPLIT_NO_EMPTY);
        
        if (empty($sentences)) {
            // Fallback to simple character splitting
            $pages = array_filter(str_split($response, $maxCharsPerPage));
            return empty($pages) ? [$response] : $pages;
        }
        
        $currentPage = '';
        foreach ($sentences as $sentence) {
            $testLength = strlen($currentPage . ' ' . $sentence);
            
            // If adding this sentence would exceed the limit, start a new page
            if ($testLength > $maxCharsPerPage && !empty($currentPage)) {
                $pages[] = trim($currentPage);
                $currentPage = $sentence;
            } else {
                $currentPage .= (empty($currentPage) ? '' : ' ') . $sentence;
            }
        }
        
        // Add the last page if it has content
        if (!empty(trim($currentPage))) {
            $pages[] = trim($currentPage);
        }
        
        // Final safety check
        if (empty($pages)) {
            $this->plugin->getLogger()->warning("splitResponseIntoPages: Failed to split response, using fallback");
            $pages = array_filter(str_split($response, $maxCharsPerPage));
            if (empty($pages)) {
                $pages = [$response]; // Ultimate fallback
            }
        }
        
        $this->plugin->getLogger()->debug("splitResponseIntoPages: Created " . count($pages) . " pages from " . strlen($response) . " chars");
        
        return $pages;
    }
    
    /**
     * Handle asking another question
     * 
     * @param Player $player
     */
    private function askAnotherQuestion(Player $player): void {
        $this->plugin->getLogger()->debug("askAnotherQuestion called for " . $player->getName());
        
        // Ensure request is completely cleaned up before opening new chat form
        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
        
        // Add delay to ensure proper cleanup before opening new form
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
            function() use ($player): void {
                if ($player->isOnline()) {
                    $form = new ChatForm($this->plugin);
                    $form->sendTo($player);
                    $this->plugin->getMessageManager()->sendToastNotification(
                        $player,
                        "info",
                        $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_title"),
                        $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_body")
                    );
                    $this->plugin->getLogger()->debug("ChatForm sent to " . $player->getName());
                } else {
                    $this->plugin->getLogger()->warning("Player " . $player->getName() . " went offline before ChatForm could be sent");
                }
            }
        ), 15); // Increased delay for better cleanup
    }
    
    /**
     * Handle retrying a question
     * 
     * @param Player $player
     * @param string $question
     */
    private function retryQuestion(Player $player, string $question): void {
        $this->plugin->getLogger()->debug("retryQuestion called for " . $player->getName() . " with question: " . substr($question, 0, 50) . "...");
        
        // Retry the same question
        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
        
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
            function() use ($player, $question): void {
                if ($player->isOnline()) {
                    // Set form context for the request
                    $requestManager = $this->plugin->getProviderManager()->getRequestManager();
                    $requestManager->setFormContext($player->getName(), [
                        'type' => 'chat_form',
                        'question' => $question,
                        'tokenManager' => $this->plugin->getTokenManager()
                    ]);
                    
                    // Show loading response form immediately
                    $responseForm = new ResponseForm($this->plugin);
                    $loadingMessage = "§bProcessing your request, please wait...";
                    $responseForm->sendTo($player, $question, $loadingMessage);
                    
                    // Process the query
                    try {
                        $this->plugin->getProviderManager()->processQuery($player, $question);
                        $this->plugin->getLogger()->debug("Retry query processed for " . $player->getName());
                    } catch (\Throwable $e) {
                        $this->plugin->getLogger()->error("Error in retryQuestion: " . $e->getMessage());
                        $errorMessage = $this->getErrorMessage($e);
                        $responseForm->updateWithFinalResponse($player, $question, $errorMessage);
                    }
                } else {
                    $this->plugin->getLogger()->warning("Player " . $player->getName() . " went offline before retry could be processed");
                }
            }
        ), 15); // Increased delay for better cleanup
    }
    
    /**
     * Get appropriate error message based on exception type
     * 
     * @param \Throwable $e
     * @return string
     */
    private function getErrorMessage(\Throwable $e): string {
        $errorMsg = $e->getMessage();
        
        // Check for specific error types and return appropriate configured messages
        if (strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, 'timed out') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_timeout_error");
        }
        
        if (strpos($errorMsg, 'connection') !== false || strpos($errorMsg, 'connect') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_connection_error");
        }
        
        if (strpos($errorMsg, 'rate limit') !== false || strpos($errorMsg, 'too many') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_rate_limit_error");
        }
        
        if (strpos($errorMsg, 'quota') !== false || strpos($errorMsg, 'exceeded') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_quota_exceeded");
        }
        
        if (strpos($errorMsg, 'invalid') !== false || strpos($errorMsg, 'parse') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_invalid_response");
        }
        
        if (strpos($errorMsg, 'unavailable') !== false || strpos($errorMsg, 'service') !== false) {
            return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_service_unavailable");
        }
        
        // Default error message
        return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_unknown_error");
    }
}
