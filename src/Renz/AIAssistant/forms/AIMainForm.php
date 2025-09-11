<?php

declare(strict_types=1);

namespace Renz\AIAssistant\forms;

use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Renz\AIAssistant\Main;

class AIMainForm {
    /** @var Main */
    private Main $plugin;

    /**
     * AIMainForm constructor.
     * 
     * @param Main $plugin
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    /**
     * Send the form to a player
     * 
     * @param Player $player
     */
    public function sendTo(Player $player): void {
        $form = new SimpleForm(function(Player $player, $data) {
            if ($data === null) {
                return;
            }
            
            switch ($data) {
                case 0: // Ask a question
                    $this->openAskQuestionForm($player);
                    break;
                case 1: // Crafting recipes
                    $this->openCraftingForm($player);
                    break;
                case 2: // Building calculator
                    $this->openBuildingCalculatorForm($player);
                    break;
                case 3: // Server stats
                    $this->showServerStats($player);
                    break;
            }
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("main_form.title", "AI Assistant");
        $content = $this->plugin->getFormSetting("main_form.content", "Welcome to the AI Assistant! What would you like to do?");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Format content with text formatting from config
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
        $content = $this->plugin->formatFormText($contentFormat . $content);
        
        $form->setTitle($title);
        $form->setContent($content);
        
        // Add buttons from config
        $this->addButtonFromConfig($form, "main_form.buttons.chat", "Ask a Question", 0);
        $this->addButtonFromConfig($form, "main_form.buttons.crafting", "Crafting Recipes", 1);
        $this->addButtonFromConfig($form, "main_form.buttons.building", "Building Calculator", 2);
        $this->addButtonFromConfig($form, "main_form.buttons.server_info", "Server Stats", 3);
        
        
        $form->sendToPlayer($player);
    }

    /**
     * Add a button to the form using configuration
     * 
     * @param SimpleForm $form The form to add the button to
     * @param string $configPath The path to the button configuration
     * @param string $defaultText The default button text
     * @param int $index The button index for debugging
     */
    private function addButtonFromConfig(SimpleForm $form, string $configPath, string $defaultText, int $index): void {
        // Get button text from config
        $text = $this->plugin->getFormSetting("$configPath.text", $defaultText);
        
        // Get button color from config
        $color = $this->plugin->getFormSetting("$configPath.color", "&a");
        
        // Format button text
        $buttonText = $this->plugin->formatFormText($color . $text);
        
        // Get button texture from config
        $texture = $this->plugin->getFormSetting("$configPath.texture", "");
        
        // Add button to form
        $form->addButton($buttonText, 0, $texture);
        
        if ($this->plugin->isDebugEnabled()) {
            $this->plugin->getLogger()->debug("Added button '$text' with texture '$texture' at index $index");
        }
    }

    /**
     * Open the ask question form
     * 
     * @param Player $player
     */
    private function openAskQuestionForm(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                return;
            }
            
            $question = trim($data[1] ?? "");
            if (empty($question)) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.enter_question_generic");
                return;
            }
            
            // Process the question and get a response
            $response = $this->plugin->getProviderManager()->getDefaultProvider()->processQuery($player, $question);
            
            // Show the response
            $this->showResponseForm($player, $question, $response);
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("chat_form.title", "Chat with AI");
        $content = $this->plugin->getFormSetting("chat_form.content", "What would you like to ask the AI Assistant?");
        $placeholder = $this->plugin->getFormSetting("chat_form.placeholder", "Type your question here...");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Format content with text formatting from config
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
        $content = $this->plugin->formatFormText($contentFormat . $content);
        
        $form->setTitle($title);
        $form->addLabel($content);
        $form->addInput("", $placeholder);
        
        $form->sendToPlayer($player);
    }

    /**
     * Open the crafting form
     * 
     * @param Player $player
     */
    private function openCraftingForm(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                return;
            }
            
            $item = trim($data[1] ?? "");
            if (empty($item)) {
                $this->plugin->getMessageManager()->sendConfigurableMessage($player, "forms.enter_item_generic");
                return;
            }
            
            // Process the crafting query
            $response = $this->plugin->getProviderManager()->getDefaultProvider()->processQuery($player, "How to craft " . $item);
            
            // Show the response
            $this->showResponseForm($player, "How to craft " . $item, $response);
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("crafting_form.title", "Crafting Helper");
        $content = $this->plugin->getFormSetting("crafting_form.content", "Enter the name of the item you want to craft:");
        $placeholder = $this->plugin->getFormSetting("crafting_form.placeholder", "Diamond Pickaxe");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Format content with text formatting from config
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
        $content = $this->plugin->formatFormText($contentFormat . $content);
        
        $form->setTitle($title);
        $form->addLabel($content);
        $form->addInput("", $placeholder);
        
        $form->sendToPlayer($player);
    }

    /**
     * Open the building calculator form
     * 
     * @param Player $player
     */
    private function openBuildingCalculatorForm(Player $player): void {
        $form = new CustomForm(function(Player $player, ?array $data) {
            if ($data === null) {
                return;
            }
            
            $length = (int) ($data[1] ?? 10);
            $width = (int) ($data[2] ?? 10);
            $height = (int) ($data[3] ?? 5);
            
            // Get building styles from config
            $styles = $this->plugin->getFormSetting("building_calculator_form.fields.style.options", ["Modern", "Medieval", "Futuristic"]);
            $style = $styles[$data[4] ?? 0];
            
            // Process the building calculation query
            $query = "Calculate materials for a {$length}x{$width}x{$height} {$style} house";
            $response = $this->plugin->getProviderManager()->getDefaultProvider()->processQuery($player, $query);
            
            // Show the response
            $this->showResponseForm($player, $query, $response);
        });
        
        // Get form title and content from forms config
        $title = $this->plugin->getFormSetting("building_calculator_form.title", "Building Calculator");
        $content = $this->plugin->getFormSetting("building_calculator_form.content", "Calculate materials needed for your building project:");
        
        // Get field labels and defaults from config
        $lengthLabel = $this->plugin->getFormSetting("building_calculator_form.fields.length.label", "Length");
        $lengthDefault = $this->plugin->getFormSetting("building_calculator_form.fields.length.default", "10");
        $lengthPlaceholder = $this->plugin->getFormSetting("building_calculator_form.fields.length.placeholder", "10");
        
        $widthLabel = $this->plugin->getFormSetting("building_calculator_form.fields.width.label", "Width");
        $widthDefault = $this->plugin->getFormSetting("building_calculator_form.fields.width.default", "10");
        $widthPlaceholder = $this->plugin->getFormSetting("building_calculator_form.fields.width.placeholder", "10");
        
        $heightLabel = $this->plugin->getFormSetting("building_calculator_form.fields.height.label", "Height");
        $heightDefault = $this->plugin->getFormSetting("building_calculator_form.fields.height.default", "5");
        $heightPlaceholder = $this->plugin->getFormSetting("building_calculator_form.fields.height.placeholder", "5");
        
        $styleLabel = $this->plugin->getFormSetting("building_calculator_form.fields.style.label", "Building Style");
        $styleOptions = $this->plugin->getFormSetting("building_calculator_form.fields.style.options", ["Modern", "Medieval", "Futuristic"]);
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Format content with text formatting from config
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&7");
        $content = $this->plugin->formatFormText($contentFormat . $content);
        
        $form->setTitle($title);
        $form->addLabel($content);
        $form->addInput(TextFormat::colorize("&f" . $lengthLabel . ":"), $lengthPlaceholder, $lengthDefault);
        $form->addInput(TextFormat::colorize("&f" . $widthLabel . ":"), $widthPlaceholder, $widthDefault);
        $form->addInput(TextFormat::colorize("&f" . $heightLabel . ":"), $heightPlaceholder, $heightDefault);
        $form->addDropdown(TextFormat::colorize("&f" . $styleLabel . ":"), $styleOptions);
        
        $form->sendToPlayer($player);
    }

    /**
     * Show server stats
     * 
     * @param Player $player
     */
    private function showServerStats(Player $player): void {
        $response = $this->plugin->getProviderManager()->getDefaultProvider()->processQuery($player, "server stats");
        $this->showResponseForm($player, "Server Statistics", $response);
    }


    /**
     * Show the response form
     * 
     * @param Player $player
     * @param string $question
     * @param string $response
     */
    private function showResponseForm(Player $player, string $question, string $response): void {
        $form = new SimpleForm(function(Player $player, $data) {
            if ($data === null) {
                return;
            }
            
            // Return to main form
            $mainForm = new AIMainForm($this->plugin);
            $mainForm->sendTo($player);
        });
        
        // Get form title from forms config
        $title = $this->plugin->getFormSetting("response_form.title", "AI Response");
        
        // Get question and response prefixes from forms config
        $questionPrefix = $this->plugin->getFormSetting("response_form.question_prefix", "Question: ");
        $responsePrefix = $this->plugin->getFormSetting("response_form.response_prefix", "Response: ");
        
        // Format title with text formatting from config
        $titleFormat = $this->plugin->getFormSetting("general.text_formatting.title", "&l&b");
        $title = $this->plugin->formatFormText($titleFormat . $title);
        
        // Format content with text formatting from config
        $highlightFormat = $this->plugin->getFormSetting("general.text_formatting.highlight", "&e");
        $contentFormat = $this->plugin->getFormSetting("general.text_formatting.content", "&f");
        
        $content = TextFormat::colorize($highlightFormat . $questionPrefix) . 
                  TextFormat::colorize($contentFormat . $question . "\n\n") .
                  TextFormat::colorize($highlightFormat . $responsePrefix) . 
                  TextFormat::colorize($contentFormat . "\n" . $response);
        
        $form->setTitle($title);
        $form->setContent($content);
        
        // Add back button from config
        $backText = $this->plugin->getFormSetting("response_form.buttons.back.text", "Back to Main Menu");
        $backColor = $this->plugin->getFormSetting("response_form.buttons.back.color", "&9");
        $backTexture = $this->plugin->getFormSetting("response_form.buttons.back.texture", "textures/ui/arrow_left");
        
        $form->addButton($this->plugin->formatFormText($backColor . $backText), 0, $backTexture);
        
        $form->sendToPlayer($player);
    }
}