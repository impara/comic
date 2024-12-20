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

            // Log the prompt we're sending
            $this->logger->info('Sending prompt to NLP model', [
                'prompt' => $prompt,
                'panel_count' => $panelCount
            ]);

            // Call Replicate NLP model with optimized parameters
            $result = $this->replicateClient->predict('nlp', [
                'prompt' => $prompt,
                'max_length' => 2048,
                'temperature' => 0.5,  // Lower temperature for more deterministic output
                'top_p' => 0.9,       // Slightly higher top_p for more natural language
                'repetition_penalty' => 1.1  // Lower repetition penalty for more natural responses
            ]);

            if (empty($result)) {
                throw new RuntimeException('Failed to get valid response from NLP model');
            }

            // Log the raw result
            $this->logger->info('Received NLP model response', [
                'raw_result' => $result
            ]);

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
6. For short stories, break down key moments into separate panels:
   - Setting establishment
   - Character introduction
   - Action/conflict moments
   - Resolution/emotional beats

Format your response as:
Panel 1: [visual description]
Panel 2: [visual description]
...and so on.

For this story, ensure you create exactly {$panelCount} panels by breaking down the narrative into key visual moments, even if the story is short.

Story:
{$story}

Divide this into {$panelCount} panels:
EOT;
    }

    private function parseNLPResponse(string $response): array
    {
        $this->logger->info('Parsing NLP response', [
            'response_length' => strlen($response),
            'raw_response' => $response // Log the actual response
        ]);

        // Validate response is not empty or too short
        if (empty($response) || strlen($response) < 10) {
            $this->logger->error('Invalid NLP response', [
                'response' => $response,
                'length' => strlen($response)
            ]);
            throw new RuntimeException('NLP model returned an invalid or empty response');
        }

        // Split response into lines and clean up
        $lines = array_filter(
            array_map('trim', explode("\n", $response)),
            function ($line) {
                return !empty($line) && $line !== "\n";
            }
        );

        // Log the cleaned lines for debugging
        $this->logger->info('Cleaned response lines', [
            'line_count' => count($lines),
            'lines' => $lines
        ]);

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

        // Log extracted panels
        $this->logger->info('Extracted panels', [
            'panel_count' => count($panels),
            'panels' => $panels
        ]);

        // Validate we got some panels
        if (empty($panels)) {
            $this->logger->error('No panels extracted from response', [
                'lines' => $lines,
                'response' => $response
            ]);
            throw new RuntimeException('Failed to extract any valid panels from NLP response');
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
        // - Base count: 1 panel per 1.5-2 sentences (more panels for shorter stories)
        // - Minimum 2 panels for any story
        // - Adjust for dialogue density
        // - Consider word count for complexity
        $baseCount = max(2, ceil($sentenceCount / 1.5));
        $dialogueAdjustment = ceil($dialogueCount / 2);
        $wordCountAdjustment = $wordCount > 50 ? ceil(($wordCount - 50) / 30) : 0;
        $suggestedCount = $baseCount + $dialogueAdjustment + $wordCountAdjustment;

        // Ensure within limits
        $suggestedCount = max($minPanels, min($maxPanels, $suggestedCount));

        $this->logger->info('Calculated optimal panel count', [
            'word_count' => $wordCount,
            'sentence_count' => $sentenceCount,
            'dialogue_count' => $dialogueCount,
            'base_count' => $baseCount,
            'dialogue_adjustment' => $dialogueAdjustment,
            'word_count_adjustment' => $wordCountAdjustment,
            'final_count' => $suggestedCount
        ]);

        return $suggestedCount;
    }
}
