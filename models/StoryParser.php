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

        $maxAttempts = 3;
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            try {
                $attempt++;
                $this->logger->info('Attempting story segmentation', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts
                ]);

                // Get optimal panel count based on story length and complexity
                $panelCount = $this->getOptimalPanelCount($story);

                // Build the segmentation prompt with clear instructions
                $prompt = $this->buildSegmentationPrompt($story, $panelCount);

                // Log the prompt we're sending
                $this->logger->info('Sending prompt to NLP model', [
                    'prompt' => $prompt,
                    'panel_count' => $panelCount,
                    'attempt' => $attempt
                ]);

                // Call Replicate NLP model with optimized parameters
                $result = $this->replicateClient->predict('nlp', [
                    'prompt' => $prompt,
                    'max_length' => 2048,
                    'temperature' => 0.7 + ($attempt * 0.1),  // Increase temperature slightly with each attempt
                    'top_p' => 0.9,
                    'repetition_penalty' => 1.2
                ]);

                if (empty($result) || !is_array($result) || empty($result[0])) {
                    throw new RuntimeException('NLP model returned an invalid response structure');
                }

                $response = $result[0];

                // Check for truncated or invalid response
                if (strlen($response) < 50 || !preg_match('/Panel \d+:/i', $response)) {
                    throw new RuntimeException('NLP model returned a truncated or invalid response');
                }

                // Log the raw result
                $this->logger->info('Received NLP model response', [
                    'raw_result' => $result,
                    'response_text' => $response,
                    'response_length' => strlen($response),
                    'attempt' => $attempt
                ]);

                // Process and validate the segmented panels
                $panels = $this->parseNLPResponse($response);

                // Validate panel count
                if (count($panels) < 2) {
                    throw new RuntimeException('Not enough valid panels generated from NLP response');
                }

                return $this->processPanelDescriptions($panels);
            } catch (Exception $e) {
                $lastError = $e;
                $this->logger->warning('Story segmentation attempt failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempt < $maxAttempts
                ]);

                // If this is the last attempt, throw the error
                if ($attempt >= $maxAttempts) {
                    $this->logger->error('Story segmentation failed after all attempts', [
                        'total_attempts' => $attempt,
                        'final_error' => $e->getMessage(),
                        'story_preview' => substr($story, 0, 100) . '...'
                    ]);
                    throw new RuntimeException('Failed to segment story after ' . $attempt . ' attempts: ' . $e->getMessage());
                }

                // Wait a bit before retrying
                sleep(1);
            }
        }

        // This should never be reached due to the throw in the loop
        throw new RuntimeException('Failed to segment story: ' . ($lastError ? $lastError->getMessage() : 'Unknown error'));
    }

    private function buildSegmentationPrompt(string $story, int $panelCount): string
    {
        // Create a detailed prompt for the NLP model
        return <<<EOT
You are a comic book artist breaking down a story into sequential panels. Your task is to divide the following story into exactly {$panelCount} comic panels, even if the story is short or abstract.

Instructions:
1. Create EXACTLY {$panelCount} panels - no more, no less
2. Each panel must be a clear, visual description that an artist can illustrate
3. Focus on key moments, actions, expressions, and visual elements
4. For short stories, break down moments into:
   - Setting establishment
   - Character introduction
   - Key action or discovery moment
   - Emotional reaction or consequence
   - Resolution or final state
5. Keep descriptions specific and illustratable

Response Format Example for a short story:
Panel 1: [Establish the setting/scene visually]
Panel 2: [Show character's initial state/action]
Panel 3: [Depict the key moment/discovery]
Panel 4: [Show the reaction/consequence]
Panel 5: [Illustrate the resolution]

Important Rules:
1. Start each line with "Panel X: " where X is the panel number
2. Number panels sequentially from 1 to {$panelCount}
3. Do not include any other text or explanations
4. Each panel must be on its own line
5. Each panel must be a concrete, visual scene

Story to divide:
{$story}

Remember: I need EXACTLY {$panelCount} panels, each starting with "Panel X: ". Focus on making each panel visually distinct and concrete. Begin your response now:
EOT;
    }

    private function parseNLPResponse(string $response): array
    {
        $this->logger->info('Parsing NLP response', [
            'response_length' => strlen($response),
            'raw_response' => $response
        ]);

        // First, try to split panels that are in the same line
        $response = preg_replace('/\. Panel (\d+):/', ".\nPanel $1:", $response);

        // Remove any text after the last panel (like "I hope this breakdown is helpful...")
        if (preg_match('/(.*Panel \d+:.*?\.)\s*(?:Panel|$)/s', $response, $matches)) {
            $response = $matches[1];
        }

        // Split response into lines and clean up
        $lines = array_filter(
            array_map('trim', explode("\n", $response)),
            function ($line) {
                return !empty($line) &&
                    $line !== "\n" &&
                    strlen($line) > 5;
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
            // Try to extract multiple panels from a single line
            $panelMatches = [];
            preg_match_all('/Panel\s*(\d+)\s*:\s*([^.]+(?:\.[^.P]+)*)/i', $line . ' ', $panelMatches, PREG_SET_ORDER);

            foreach ($panelMatches as $match) {
                $panelNumber = (int)$match[1];
                $description = trim($match[2]);

                // Remove any trailing punctuation that might interfere with the next panel
                $description = rtrim($description, " \t\n\r\0\x0B.,");

                // Validate panel description
                if (strlen($description) < 10) {
                    $this->logger->warning('Skipping too short panel description', [
                        'panel' => $panelNumber,
                        'description' => $description
                    ]);
                    continue;
                }

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

        // Validate panel sequence
        $expectedCount = max(array_keys($panels));
        for ($i = 1; $i <= $expectedCount; $i++) {
            if (!isset($panels[$i])) {
                $this->logger->error('Missing panel in sequence', [
                    'missing_panel' => $i,
                    'available_panels' => array_keys($panels)
                ]);
                throw new RuntimeException("Missing panel {$i} in the sequence");
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
        $this->logger->info('Calculating optimal panel count', [
            'story_length' => strlen($story)
        ]);

        // Count sentences (roughly)
        $sentences = array_filter(array_map('trim', preg_split('/[.!?]+/', $story)));
        $sentenceCount = count($sentences);

        // Count potential scene transitions (indicated by certain words)
        $transitionWords = [
            'while',
            'when',
            'after',
            'before',
            'then',
            'finally',
            'meanwhile',
            'suddenly',
            'later',
            'next',
            'soon',
            'discover',
            'find',
            'encounter',
            'meet',
            'learn'
        ];
        $transitionCount = 0;
        foreach ($transitionWords as $word) {
            $transitionCount += substr_count(strtolower($story), $word);
        }

        // Base calculation
        $baseCount = max(
            2, // Minimum 2 panels
            min(
                6, // Maximum 6 panels
                ceil($sentenceCount * 1.5) + $transitionCount
            )
        );

        $this->logger->info('Panel count calculation', [
            'sentence_count' => $sentenceCount,
            'transition_count' => $transitionCount,
            'base_count' => $baseCount
        ]);

        return $baseCount;
    }
}
