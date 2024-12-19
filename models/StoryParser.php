<?php

require_once __DIR__ . '/../interfaces/StoryParserInterface.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ReplicateClient.php';

class StoryParser implements StoryParserInterface
{
    private LoggerInterface $logger;
    private Config $config;
    private ReplicateClient $replicateClient;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->config = Config::getInstance();
        $this->replicateClient = new ReplicateClient($this->logger);
    }

    public function segmentStory(string $story, array $options = []): array
    {
        $this->logger->info('Starting story segmentation', [
            'story_length' => strlen($story),
            'options' => $options
        ]);

        try {
            // Get optimal panel count based on story length and complexity
            $panelCount = $this->getOptimalPanelCount($story);

            // Build the segmentation prompt with clear instructions
            $prompt = $this->buildSegmentationPrompt($story, $panelCount);

            // Call Replicate NLP model with optimized parameters
            $result = $this->replicateClient->predict('nlp', [
                'prompt' => $prompt,
                'max_length' => 2048,
                'temperature' => 0.7, // Lower temperature for more focused outputs
                'top_p' => 0.8,      // More focused sampling
                'repetition_penalty' => 1.2
            ]);

            if (empty($result)) {
                throw new RuntimeException('Failed to get valid response from NLP model');
            }

            // Process and validate the segmented panels
            $panels = $this->parseNLPResponse($result[0]);
            return $this->processPanelDescriptions($panels);
        } catch (Exception $e) {
            $this->logger->error('Story segmentation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'story_preview' => substr($story, 0, 100) . '...'
            ]);
            throw new RuntimeException('Failed to segment story: ' . $e->getMessage());
        }
    }

    private function buildSegmentationPrompt(string $story, int $panelCount): string
    {
        // Create a detailed prompt for the NLP model
        return <<<EOT
Task: Divide this story into exactly {$panelCount} sequential comic panels.

Requirements:
1. Each panel description must be visual and illustratable
2. Maintain story continuity and flow between panels
3. Include key actions, emotions, and settings
4. Keep descriptions concise but detailed
5. Focus on visual elements and actions

Format your response as:
Panel 1: [visual description]
Panel 2: [visual description]
...and so on.

Story:
{$story}

Divide this into {$panelCount} panels:
EOT;
    }

    private function parseNLPResponse(string $response): array
    {
        $this->logger->info('Parsing NLP response', ['response_length' => strlen($response)]);

        // Split response into lines and clean up
        $lines = array_filter(
            array_map('trim', explode("\n", $response)),
            function ($line) {
                return !empty($line) && $line !== "\n";
            }
        );

        // Extract panel descriptions using regex
        $panels = [];
        foreach ($lines as $line) {
            // Match both "Panel X:" and "X:" formats
            if (preg_match('/(?:Panel\s*)?(\d+):\s*(.+)/i', $line, $matches)) {
                $panelNumber = (int)$matches[1];
                $description = trim($matches[2]);

                // Store panel with its number for proper ordering
                $panels[$panelNumber] = $description;
            }
        }

        // Sort panels by number and return just the descriptions
        ksort($panels);
        return array_values($panels);
    }

    public function processPanelDescriptions(array $panels): array
    {
        $this->logger->info('Processing panel descriptions', ['panel_count' => count($panels)]);

        $processedPanels = [];
        foreach ($panels as $index => $panel) {
            // Clean and normalize the panel description
            $description = trim($panel);

            // Skip empty panels
            if (empty($description)) {
                continue;
            }

            // Enhance description for better image generation
            $description = $this->enhanceDescription($description, $index + 1, count($panels));

            $processedPanels[] = $description;
        }

        // Validate panel count
        $minPanels = $this->config->get('comic_strip.min_panels');
        if (count($processedPanels) < $minPanels) {
            throw new RuntimeException("Not enough valid panels generated. Minimum required: {$minPanels}");
        }

        return $processedPanels;
    }

    private function enhanceDescription(string $description, int $panelNumber, int $totalPanels): string
    {
        // Add panel context if not present
        if (!preg_match('/\b(panel|scene|view|shot)\b/i', $description)) {
            $description = "Panel {$panelNumber} of {$totalPanels}: " . $description;
        }

        // Add visual perspective if not present
        if (!preg_match('/\b(close-up|medium shot|wide shot|angle)\b/i', $description)) {
            // Default to medium shot if no perspective is specified
            $description .= ", medium shot";
        }

        // Ensure proper punctuation
        if (!preg_match('/[.!?]$/', $description)) {
            $description .= '.';
        }

        return $description;
    }

    public function getOptimalPanelCount(string $story): int
    {
        // Get configuration limits
        $minPanels = $this->config->get('comic_strip.min_panels');
        $maxPanels = $this->config->get('comic_strip.max_panels');

        // Calculate based on story length and complexity
        $wordCount = str_word_count($story);
        $sentenceCount = preg_match_all('/[.!?]+/', $story, $matches);
        $dialogueCount = preg_match_all('/["\'](.*?)[\'"]/i', $story, $matches);

        // Enhanced heuristic:
        // - Base count: 1 panel per 2-3 sentences
        // - Adjust for dialogue density
        // - Consider word count for complexity
        $baseCount = ceil($sentenceCount / 2.5);
        $dialogueAdjustment = ceil($dialogueCount / 2);
        $suggestedCount = $baseCount + $dialogueAdjustment;

        // Ensure within limits
        $suggestedCount = max($minPanels, min($maxPanels, $suggestedCount));

        $this->logger->info('Calculated optimal panel count', [
            'word_count' => $wordCount,
            'sentence_count' => $sentenceCount,
            'dialogue_count' => $dialogueCount,
            'base_count' => $baseCount,
            'dialogue_adjustment' => $dialogueAdjustment,
            'final_count' => $suggestedCount
        ]);

        return $suggestedCount;
    }
}
