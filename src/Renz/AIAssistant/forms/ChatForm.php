<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\forms\ResponseForm;
use Renz\AIAssistant\forms\TokenShopForm;

class ChatForm {
    /** @var Main */
    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Send the chat form to a player
     *
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                // player closed the form
                return;
            }

            $question = trim((string)($data[1] ?? ""));

            if ($question === "") {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.enter_question_first");
                $this->plugin->getMessageManager()->sendToastNotification(
                    $player,
                    "error",
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_title"),
                    $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.no_question_body")
                );

                $this->sendTo($player);
                return;
            }

            // Token handling
            $tokenManager = $this->plugin->getTokenManager();
            if ($tokenManager->isEnabled()) {
                if (!$tokenManager->canUseToken($player)) {
                    $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.no_tokens_purchase");
                    $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "no_tokens");

                    $shopForm = new TokenShopForm($this->plugin);
                    $shopForm->sendTo($player);
                    return;
                }
            }

            // Request manager checks
            $requestManager = $this->plugin->getProviderManager()->getRequestManager();
            if ($requestManager->hasActiveRequest($player->getName())) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "console.request_already_active");
                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_active");
                return;
            }

            $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "processing");

            $requestManager->setFormContext($player->getName(), [
                'type' => 'chat_form',
                'question' => $question,
                'tokenManager' => $tokenManager
            ]);

            $responseForm = new ResponseForm($this->plugin);
            $loadingMessage = $this->plugin->getMessageManager()->getConfigurableMessage("loading.content.waiting_message");
            $responseForm->sendTo($player, $question, $loadingMessage);

            try {
                $this->plugin->getProviderManager()->processQuery($player, $question);
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("ChatForm processQuery error: " . $e->getMessage());

                $errorMessage = $this->getErrorMessage($e);
                $responseForm->sendTo($player, $question, $errorMessage);

                $this->plugin->getMessageManager()->sendSpecificToastNotification($player, "request_cancelled");

                $requestManager->clearFormContext($player->getName());
                return;
            }
        });

        $title = $this->plugin->getFormSetting("chat_form.title", "Chat with AI");
        $content = $this->plugin->getFormSetting("chat_form.content", "What would you like to ask the AI Assistant?");
        $placeholder = $this->plugin->getFormSetting("chat_form.placeholder", "Type your question here...");

        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);

        $form->setTitle($title);

        $tokenManager = $this->plugin->getTokenManager();
        if ($tokenManager->isEnabled()) {
            $tokenStatus = $tokenManager->getTokenStatusMessage($player->getName());
            $highlightFormat = $this->plugin->getFormSetting("general.text_formatting.highlight", "&e");
            $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");

            $form->addLabel(TextFormat::colorize($highlightFormat . $tokenStatus . "\n\n") .
                           TextFormat::colorize($contentFormat . $content));
        } else {
            $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
            $form->addLabel(TextFormat::colorize($contentFormat . $content));
        }

        $form->addInput("§fYour Question:", $placeholder);

        $form->sendToPlayer($player);

        $this->plugin->getMessageManager()->sendToastNotification(
            $player,
            "info",
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_title"),
            $this->plugin->getMessageManager()->getConfigurableMessage("toasts.chat.welcome_body")
        );
    }

    /**
     * Map exception to friendly error message
     *
     * @param \Throwable $e
     * @return string
     */
    private function getErrorMessage(\Throwable $e): string {
        $errorMsg = $e->getMessage();

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

        return $this->plugin->getMessageManager()->getConfigurableMessage("forms.ai_unknown_error");
    }
}