<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;
use Renz\AIAssistant\forms\MainForm;
use Renz\AIAssistant\forms\MainForm;

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
    // Enhanced error state detection
    $isError = (strpos($response, "§c") === 0) ||
               (strpos($response, "error") !== false) ||
               (strpos($response, "failed") !== false) ||
               (strpos($response, "Error:") !== false) ||
               (strpos($response, "Failed:") !== false);

    // Ensure response content is properly formatted and not empty
    if (empty(trim($response))) {
        $response = $this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed");
        $isError = true;
    }

    // Improved request management - cancel if error or final (kept consistent with original intent)
    if ($isError) {
        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
    } else {
        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
    }
    // (Note: both branches call cancelPlayerRequests; kept intentionally per original logic)

    // Split response into pages if it's too long and not already paginated
    if (empty($pages) && !$isError) {
        $pages = $this->splitResponseIntoPages($response);
    } elseif (empty($pages)) {
        $pages = [$response]; // Single page for error states
    }

    // Fallback for empty pages
    if (empty($pages)) {
        $pages = [$response];
    }

    $totalPages = count($pages);
    $currentResponse = $pages[$currentPage] ?? $pages[0] ?? $response; // Triple fallback

    // Create button mapping for dynamic handling
    $buttonMap = [];
    $buttonIndex = 0;

    // Create the SimpleForm with callback. We pass $buttonMap by reference so callback can read mapping.
    $form = new SimpleForm(function(Player $player, ?int $data) use ($question, $response, $currentPage, $pages, $totalPages, &$buttonMap) {
        if ($data === null) {
            return;
        }

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
                
            case 'new_session':
                $this->createNewSession($player);
                break;

            default:
                // Unknown action fallback
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
    $formattedResponse = $this->formatResponseContent($currentResponse, $isError);

    // Truncate response if too long for SimpleForm
    $maxContentLength = 1500;
    if (strlen($formattedResponse) > $maxContentLength) {
        $formattedResponse = mb_substr($formattedResponse, 0, $maxContentLength) . "\n\n... (content truncated, use pagination)";
    }

    // Format content based on state
    $questionColor = MinecraftTextFormatter::COLOR_YELLOW;
    $responseColor = $isError ? MinecraftTextFormatter::COLOR_RED : MinecraftTextFormatter::COLOR_GREEN;

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

    // Ask another question button (always show)
    $newQuestionText = $this->plugin->getFormSetting("response_form.buttons.new_question.text", "Ask Another Question");
    $newQuestionColor = $this->plugin->getFormSetting("response_form.buttons.new_question.color", "&a");
    $newQuestionTexture = $this->plugin->getFormSetting("response_form.buttons.new_question.texture", "textures/ui/chat_icon");
    $form->addButton($this->plugin->formatFormText($newQuestionColor . $newQuestionText), 0, $newQuestionTexture);
    $buttonMap[$buttonIndex++] = 'ask_another';
    
    // New Session button
    $newSessionText = $this->plugin->getFormSetting("response_form.buttons.new_session.text", "New Session");
    $newSessionColor = $this->plugin->getFormSetting("response_form.buttons.new_session.color", "&e");
    $newSessionTexture = $this->plugin->getFormSetting("response_form.buttons.new_session.texture", "textures/ui/plus");
    $form->addButton($this->plugin->formatFormText($newSessionColor . $newSessionText), 0, $newSessionTexture);
    $buttonMap[$buttonIndex++] = 'new_session';

    // Retry button (show if error occurred)
    if ($isError) {
        $form->addButton("§6Retry Question", 0, "textures/ui/refresh");
        $buttonMap[$buttonIndex++] = 'retry';
    }

    // Add to conversation history only on first page and final response
    if ($currentPage === 0 && !$isError) {
        $this->plugin->getConversationManager()->addToConversation($player->getName(), $question, $response);
    }

    // Adjusted delay based on state (ticks)
    $delay = 10; // ticks

    $this->plugin->getScheduler()->scheduleDelayedTask(
        new \pocketmine\scheduler\ClosureTask(function() use ($form, $player, $currentPage): void {
            if ($player->isOnline()) {
                $form->sendToPlayer($player);
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
        // Force close current form first
        $player->removeCurrentWindow();
        
        // Wait a bit then send new form with final response
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
            function() use ($player, $question, $finalResponse): void {
                if ($player->isOnline()) {
                    $this->sendTo($player, $question, $finalResponse);
                }
            }
        ), 15); // Longer delay to ensure form is properly closed
    }
    
    /**
     * ADDED: Format response content based on state
     * 
     * @param string $response
     * @param bool $isError
     * @return string
     */
    private function formatResponseContent(string $response, bool $isError): string {
        // Ensure response content is properly formatted and visible
        $formattedResponse = trim($response);
        
        if (empty($formattedResponse)) {
            $formattedResponse = $this->plugin->getMessageManager()->getConfigurableMessage("forms.generation_failed");
        }
        
        // Apply text formatting if needed
        if (!str_contains($formattedResponse, "§")) {
            $formattedResponse = MinecraftTextFormatter::formatText($formattedResponse);
        }
        
        if ($isError) {
            $formattedResponse = "§l§4[ERROR] " . $formattedResponse;
        } else {
            $formattedResponse = "§l§2[COMPLETE] " . $formattedResponse;
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
            $pages = array_filter(str_split($response, $maxCharsPerPage));
            if (empty($pages)) {
                $pages = [$response]; // Ultimate fallback
            }
        }
        
        return $pages;
    }
    
    /**
     * Handle asking another question
     * 
     * @param Player $player
     */
    private function askAnotherQuestion(Player $player): void {
        // Ensure request is completely cleaned up before opening new chat form
        $this->plugin->getProviderManager()->cancelPlayerRequests($player->getName());
        
        // Add delay to ensure proper cleanup before opening new form
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
            function() use ($player): void {
                if ($player->isOnline()) {
                    $form = new MainForm($this->plugin);
                    $form->openChatForm($player);
                    $this->plugin->getMessageManager()->sendToastNotification(
                        $player,
                        "info",
                        $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_title"),
                        $this->plugin->getMessageManager()->getConfigurableMessage("toasts.response.new_question_body")
                    );
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
                    
                    // Process the query
                    try {
                        $this->plugin->getProviderManager()->processQuery($player, $question);
                    } catch (\Throwable $e) {
                        $errorMessage = $this->getErrorMessage($e);
                        $responseForm = new ResponseForm($this->plugin);
                        $responseForm->updateWithFinalResponse($player, $question, $errorMessage);
                    }
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
    /**
     * Create a new session and redirect to chat form
     * 
     * @param Player $player
     */
    private function createNewSession(Player $player): void {
        // Create a new session
        $sessionId = $this->plugin->getConversationManager()->createNewSession($player->getName());
        
        // Notify the player
        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.session.new_session_title", "New Session Created"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.session.new_session_body", "You can now start a new conversation.")
        );
        
        // Open chat form for the new session
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(
            function() use ($player): void {
                if ($player->isOnline()) {
                    $form = new ChatForm($this->plugin);
                    $form->sendTo($player);
                }
            }
        ), 15);
    }
    
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
