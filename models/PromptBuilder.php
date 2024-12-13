<?php

require_once __DIR__ . '/Config.php';

class PromptBuilder
{
    private Config $config;
    private array $negativePrompts;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->negativePrompts = $this->config->getNegativePrompts();
    }

    /**
     * Build a character prompt combining character and scene descriptions
     * @param string $characterDescription Character description
     * @param string $sceneDescription Scene context
     * @return string Combined prompt
     */
    public function buildCharacterPrompt(string $characterDescription, string $sceneDescription = ''): string
    {
        $prompt = "Create a comic book style character who is {$characterDescription}";

        if ($sceneDescription) {
            $prompt .= ", shown in a scene where {$sceneDescription}";
        }

        $prompt .= ". The character should be well-lit, centered, and drawn in a detailed comic book art style.";

        // Add negative prompts
        if (!empty($this->negativePrompts)) {
            $prompt .= " Negative prompt: " . implode(', ', $this->negativePrompts);
        }

        return $prompt;
    }

    /**
     * Build a background prompt from scene description
     * @param string $sceneDescription Scene description
     * @return string Background generation prompt
     */
    public function buildBackgroundPrompt(string $sceneDescription): string
    {
        $prompt = "Create a comic book style background scene showing {$sceneDescription}. " .
            "The scene should be detailed, well-composed, and suitable for a comic panel background. " .
            "Do not include any characters.";

        // Add negative prompts
        if (!empty($this->negativePrompts)) {
            $prompt .= " Negative prompt: " . implode(', ', $this->negativePrompts);
        }

        return $prompt;
    }

    /**
     * Build a modification prompt for img2img
     * @param string $description Modification description
     * @return string Modification prompt
     */
    public function buildModificationPrompt(string $description): string
    {
        $prompt = "Modify the character to {$description}, maintaining comic book style and quality.";

        // Add negative prompts
        if (!empty($this->negativePrompts)) {
            $prompt .= " Negative prompt: " . implode(', ', $this->negativePrompts);
        }

        return $prompt;
    }
}
