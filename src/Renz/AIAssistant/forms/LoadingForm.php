<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use Renz\AIAssistant\Main;
use Renz\AIAssistant\utils\MinecraftTextFormatter;

class LoadingForm {
    private Main $plugin;
    private Player $player;
    private string $query;
    private string $title;
    private ?int $taskId = null;
    private bool $isCancelled = false;
    private $onCancel = null;
    private array $loadingFrames;
    private int $currentFrame = 0;
    private SimpleForm $form;

    public function __construct(Main $plugin, Player $player, string $query, string $title = null) {
        $this->plugin = $plugin;
        $this->player = $player;
        $this->query = $query;
        $this->title = $title ?? $this->plugin->getMessageManager()->getConfigurableMessage("ui.assistant_title");
        
        // Load configurable frames (defaults are in config.yml)
        $this->loadingFrames = $this->plugin->getConfig()->getNested("messages.loading.frames", []);
        
        $this->createForm();
    }

    private function createForm(): void {
        $this->form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) {
                $this->cancel();
                return;
            }
            if ($data === 0) {
                $this->cancel();
                return;
            }
        });

        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $formattedTitle = $this->plugin->formatFormText($titleFormat . $this->title);

        $this->form->setTitle($formattedTitle);

        $queryLabel = $this->plugin->getMessageManager()->getConfigurableMessage("loading.content.query_label");
        $waitingMessage = $this->plugin->getMessageManager()->getConfigurableMessage("loading.content.waiting_message");
        $toastNotice = $this->plugin->getMessageManager()->getConfigurableMessage("loading.content.toast_notice");
        
        $content = MinecraftTextFormatter::COLOR_YELLOW . $queryLabel . " " . MinecraftTextFormatter::COLOR_WHITE . $this->query . "\n\n";
        $content .= MinecraftTextFormatter::COLOR_GRAY . $waitingMessage . "\n\n";
        $content .= MinecraftTextFormatter::COLOR_GRAY . TextFormat::ITALIC . $toastNotice;

        $this->form->setContent($content);

        $cancelLabel = $this->plugin->getMessageManager()->getConfigurableMessage("loading.content.cancel_button");
        $cancelText = $this->plugin->getFormSetting("general.button_colors.danger", "&c") . $cancelLabel;
        $this->form->addButton(TextFormat::colorize($cancelText), 0, "textures/ui/cancel");
    }

    public function show(callable $onCancel = null): void {
        $this->onCancel = $onCancel;
        $this->form->sendToPlayer($this->player);
        $this->startAnimationTask();
    }

    private function startAnimationTask(): void {
        if ($this->taskId !== null) {
            return;
        }

        $scheduler = $this->plugin->getScheduler();

        $task = new ClosureTask(function(): void {
            if ($this->isCancelled) {
                return;
            }
            if (!$this->player->isOnline()) {
                $this->cancel();
                return;
            }
            $this->currentFrame = ($this->currentFrame + 1) % count($this->loadingFrames);
            $frameText = $this->loadingFrames[$this->currentFrame];
            try {
                if (method_exists($this->player, 'sendToastNotification')) {
                    $title = mb_substr($frameText, 0, 8);
                    $body = $frameText;
                    $this->player->sendToastNotification($title, $body);
                } elseif (method_exists($this->player, 'sendPopup')) {
                    $this->player->sendPopup($frameText);
                } else {
                    $this->plugin->getMessageManager()->sendMessage($this->player, TextFormat::clean($frameText));
                }
            } catch (\Throwable $e) {
                $this->cancel();
                $this->plugin->getLogger()->error("Error sending toast: " . $e->getMessage());
            }
        });

        $handler = $scheduler->scheduleRepeatingTask($task, 10);
        if (is_object($handler) && method_exists($handler, 'getTaskId')) {
            $this->taskId = $handler->getTaskId();
        } else {
            $this->taskId = (int)$handler;
        }
    }

    public function finish(?string $resultText = null): void {
        if ($this->isCancelled) {
            return;
        }
        $this->isCancelled = true;
        if ($this->taskId !== null) {
            try {
                $this->plugin->getScheduler()->cancelTask($this->taskId);
            } catch (\Throwable $e) {
            }
            $this->taskId = null;
        }
        try {
            if ($this->player->isOnline() && method_exists($this->player, 'sendToastNotification')) {
                $this->player->sendToastNotification("", "");
            }
        } catch (\Throwable $e) {
        }
        if ($resultText !== null && $this->player->isOnline()) {
            $resultMessage = $this->plugin->getMessageManager()->getConfigurableMessage("loading.result_prefix") . $resultText;
            $this->plugin->getMessageManager()->sendMessage($this->player, TextFormat::colorize($resultMessage));
        }
    }

    public function cancel(): void {
        if ($this->isCancelled) {
            return;
        }
        $this->isCancelled = true;
        if ($this->taskId !== null) {
            try {
                $this->plugin->getScheduler()->cancelTask($this->taskId);
            } catch (\Throwable $e) {
            }
            $this->taskId = null;
        }
        try {
            if ($this->player->isOnline() && method_exists($this->player, 'sendToastNotification')) {
                $this->player->sendToastNotification("", "");
            }
        } catch (\Throwable $e) {
        }
        if ($this->onCancel !== null) {
            try {
                ($this->onCancel)();
            } catch (\Throwable $e) {
            }
        }
    }

    public function isCancelled(): bool {
        return $this->isCancelled;
    }
}