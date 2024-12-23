<?php

require_once __DIR__ . '/../interfaces/StoryParserInterface.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ReplicateClient.php';
require_once __DIR__ . '/CacheManager.php';

class StoryParser implements StoryParserInterface
{
    private LoggerInterface $logger;
    private Config $config;
    private ReplicateClient $replicateClient;
    private CacheManager $cacheManager;
    private const PANEL_COUNT = 4;
    private const CACHE_DURATION = 3600; // 1 hour cache for NLP results

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        $this->replicateClient = new ReplicateClient(
            $this->config->getApiToken(),
            $logger
        );

        // Initialize cache manager
        $cachePath = $this->config->getTempPath() . '/nlp_cache';
        $this->cacheManager = new CacheManager($cachePath, $this->logger);
    }

    public function segmentStory(string $story, array $options = []): array
    {
        $this->logger->info("Starting story segmentation", [
            'story_length' => strlen($story),
            'target_panels' => self::PANEL_COUNT,
            'story_content' => $story,
            'options' => $options
        ]);

        try {
            // Initial segmentation based on NLP
            $this->logger->debug("Attempting NLP segmentation", [
                'story_preview' => substr($story, 0, 100),
                'options' => $options
            ]);

            $segments = $this->segmentIntoScenes($story);

            $this->logger->debug("Initial segmentation complete", [
                'segment_count' => count($segments),
                'segments' => array_map(fn($s) => [
                    'length' => strlen($s),
                    'content' => $s
                ], $segments)
            ]);

            // Enforce 4-panel format
            if (count($segments) < self::PANEL_COUNT) {
                $this->logger->debug("Expanding scenes", [
                    'current_count' => count($segments),
                    'target_count' => self::PANEL_COUNT
                ]);
                $segments = $this->expandScenes($segments);
            } elseif (count($segments) > self::PANEL_COUNT) {
                $this->logger->debug("Consolidating scenes", [
                    'current_count' => count($segments),
                    'target_count' => self::PANEL_COUNT
                ]);
                $segments = $this->consolidateScenes($segments);
            }

            // Validate final segments
            $this->validateSegments($segments);

            $this->logger->info("Story segmentation completed", [
                'final_segment_count' => count($segments),
                'segments' => array_map(fn($s) => [
                    'length' => strlen($s),
                    'content' => $s
                ], $segments)
            ]);

            return $segments;
        } catch (Exception $e) {
            $this->logger->error("Story segmentation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'story_length' => strlen($story)
            ]);
            throw $e;
        }
    }

    /**
     * Initial scene segmentation using NLP model with fallback
     */
    private function segmentIntoScenes(string $story): array
    {
        try {
            // Try NLP-based segmentation first
            return $this->nlpSegmentation($story);
        } catch (Exception $e) {
            $this->logger->warning("NLP segmentation failed, falling back to simple segmentation", [
                'error' => $e->getMessage()
            ]);
            // Fallback to simple segmentation
            return $this->simpleSegmentation($story);
        }
    }

    /**
     * NLP-based scene segmentation with caching
     */
    private function nlpSegmentation(string $story): array
    {
        // Generate cache key based on story content
        $cacheKey = 'scene_' . md5($story);

        $this->logger->debug("Starting NLP segmentation", [
            'story_length' => strlen($story),
            'cache_key' => $cacheKey
        ]);

        // Try to get from cache first
        $cachedScenes = $this->cacheManager->get($cacheKey, self::CACHE_DURATION);
        if ($cachedScenes !== null) {
            $this->logger->info("Using cached scene segmentation", [
                'cache_key' => $cacheKey,
                'scene_count' => count($cachedScenes)
            ]);
            return $cachedScenes;
        }

        // Prepare the prompt for the NLP model
        $prompt = $this->buildSegmentationPrompt($story);

        $this->logger->debug("Prepared NLP prompt", [
            'prompt_length' => strlen($prompt),
            'prompt_preview' => substr($prompt, 0, 200)
        ]);

        try {
            // Get model configuration
            $modelConfig = $this->config->get('replicate.models.nlp');
            if (!$modelConfig) {
                throw new Exception('NLP model configuration not found');
            }

            $this->logger->debug("Calling NLP model", [
                'model' => 'nlp',
                'version' => $modelConfig['version'],
                'parameters' => array_merge(
                    $modelConfig['params'],
                    ['prompt' => substr($prompt, 0, 100) . '...']
                )
            ]);

            // Call NLP model through ReplicateClient
            $result = $this->replicateClient->predict('nlp', array_merge(
                $modelConfig['params'],
                ['prompt' => $prompt]
            ));

            $this->logger->debug("NLP model response received", [
                'raw_result' => $result,
                'result_type' => gettype($result),
                'has_content' => !empty($result)
            ]);

            // Process and validate NLP output
            $scenes = $this->processNLPOutput($result);

            $this->logger->debug("Processed NLP output", [
                'scene_count' => count($scenes),
                'scenes' => array_map(fn($s) => [
                    'length' => strlen($s),
                    'content' => $s
                ], $scenes)
            ]);

            // Cache the results
            $this->cacheManager->set($cacheKey, $scenes);

            $this->logger->info("NLP segmentation successful", [
                'scene_count' => count($scenes),
                'cache_key' => $cacheKey
            ]);

            return $scenes;
        } catch (Exception $e) {
            $this->logger->error("NLP segmentation error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'story_length' => strlen($story)
            ]);
            throw $e;
        }
    }

    /**
     * Build enhanced prompt for NLP segmentation
     */
    private function buildSegmentationPrompt(string $story): string
    {
        $this->logger->debug("Building segmentation prompt", [
            'story_length' => strlen($story)
        ]);

        $prompt = <<<EOT
Task: Analyze and segment this story into exactly 4 scenes for a comic strip panel sequence.

Requirements:
1. Each scene must be visually distinct and representable in a single panel
2. Consider dramatic timing and narrative flow
3. Ensure proper distribution of action and dialogue
4. Maintain character continuity across scenes
5. Create natural transitions between panels
6. Each scene should be self-contained yet connected to the overall narrative flow

Story:
$story

Format your response as 4 scenes, each marked with [SCENE] prefix:
[SCENE] Opening scene description with clear visual elements
[SCENE] Second scene building on the narrative
[SCENE] Third scene with rising action or conflict
[SCENE] Final scene with resolution or punchline

Note: Each scene description should:
- Focus on visual elements that can be drawn
- Include character positions and interactions
- Describe the setting and atmosphere
- Avoid dialogue-heavy descriptions
- Be clear and concise
EOT;

        $this->logger->debug("Built segmentation prompt", [
            'prompt_length' => strlen($prompt),
            'prompt_preview' => substr($prompt, 0, 200)
        ]);

        return $prompt;
    }

    /**
     * Process NLP model output into scenes with enhanced validation
     */
    private function processNLPOutput($result): array
    {
        if (empty($result)) {
            $this->logger->error("NLP model returned empty result");
            throw new RuntimeException("NLP model returned empty result");
        }

        // Get the first element if result is an array
        $output = is_array($result) ? $result[0] : $result;

        $this->logger->debug("Processing NLP output", [
            'output_length' => strlen($output),
            'output_preview' => substr($output, 0, 200)
        ]);

        // Split output into scenes based on [SCENE] marker
        $scenes = array_filter(
            array_map(
                'trim',
                preg_split('/\[SCENE\]/', $output, -1, PREG_SPLIT_NO_EMPTY)
            )
        );

        if (empty($scenes)) {
            $this->logger->error("No valid scenes found in NLP output", [
                'output' => $output
            ]);
            throw new RuntimeException("No valid scenes found in NLP output");
        }

        // Validate scene count
        if (count($scenes) !== self::PANEL_COUNT) {
            $this->logger->warning("NLP model returned incorrect number of scenes", [
                'expected' => self::PANEL_COUNT,
                'received' => count($scenes),
                'scenes' => $scenes
            ]);

            // Attempt to fix scene count
            if (count($scenes) < self::PANEL_COUNT) {
                $this->logger->debug("Expanding scenes to match panel count");
                $scenes = $this->expandScenes($scenes);
            } else {
                $this->logger->debug("Consolidating scenes to match panel count");
                $scenes = $this->consolidateScenes($scenes);
            }
        }

        // Validate scene content
        foreach ($scenes as $index => $scene) {
            if (strlen($scene) < 20) {
                $this->logger->error("Scene $index is too short", [
                    'scene' => $scene,
                    'length' => strlen($scene)
                ]);
                throw new RuntimeException("Scene $index is too short for meaningful visualization");
            }
        }

        $this->logger->debug("Processed scenes", [
            'scene_count' => count($scenes),
            'scenes' => array_map(fn($s, $i) => [
                'index' => $i,
                'length' => strlen($s),
                'content' => substr($s, 0, 100) . '...'
            ], $scenes, array_keys($scenes))
        ]);

        return $scenes;
    }

    /**
     * Simple segmentation fallback
     */
    private function simpleSegmentation(string $story): array
    {
        // Split on sentence endings followed by spaces
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($story), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            throw new RuntimeException("Story must contain at least one complete sentence");
        }

        // Group sentences into logical scenes
        $scenes = [];
        $currentScene = [];
        $sceneLength = 0;

        foreach ($sentences as $sentence) {
            $currentScene[] = $sentence;
            $sceneLength += strlen($sentence);

            // Start new scene on significant breaks or length threshold
            if ($this->isSceneBreak($sentence) || $sceneLength > 200) {
                $scenes[] = implode(' ', $currentScene);
                $currentScene = [];
                $sceneLength = 0;
            }
        }

        // Add remaining sentences as last scene
        if (!empty($currentScene)) {
            $scenes[] = implode(' ', $currentScene);
        }

        return $scenes;
    }

    /**
     * Expand scenes to fill 4 panels
     */
    private function expandScenes(array $scenes): array
    {
        if (empty($scenes)) {
            throw new RuntimeException("No scenes to expand");
        }

        $expanded = [];
        $sceneCount = count($scenes);

        // If only one scene, split it into 4
        if ($sceneCount === 1) {
            $sentences = preg_split('/(?<=[.!?])\s+/', trim($scenes[0]), -1, PREG_SPLIT_NO_EMPTY);
            $sentencesPerPanel = max(1, ceil(count($sentences) / self::PANEL_COUNT));

            for ($i = 0; $i < self::PANEL_COUNT; $i++) {
                $start = $i * $sentencesPerPanel;
                $slice = array_slice($sentences, $start, $sentencesPerPanel);
                $expanded[] = !empty($slice) ? implode(' ', $slice) : $scenes[0];
            }
        }
        // Otherwise, distribute scenes evenly
        else {
            $distribution = $this->calculatePanelDistribution($sceneCount);
            $sceneIndex = 0;

            foreach ($distribution as $count) {
                $slice = array_slice($scenes, $sceneIndex, $count);
                $expanded[] = implode(' ', $slice);
                $sceneIndex += $count;
            }
        }

        return $expanded;
    }

    /**
     * Consolidate scenes to fit in 4 panels
     */
    private function consolidateScenes(array $scenes): array
    {
        if (count($scenes) <= self::PANEL_COUNT) {
            return $scenes;
        }

        $consolidated = [];
        $scenesPerPanel = ceil(count($scenes) / self::PANEL_COUNT);

        for ($i = 0; $i < self::PANEL_COUNT; $i++) {
            $start = $i * $scenesPerPanel;
            $slice = array_slice($scenes, $start, $scenesPerPanel);
            $consolidated[] = implode(' ', $slice);
        }

        return $consolidated;
    }

    /**
     * Calculate how to distribute scenes across panels
     */
    private function calculatePanelDistribution(int $sceneCount): array
    {
        $distribution = array_fill(0, self::PANEL_COUNT, floor($sceneCount / self::PANEL_COUNT));
        $remainder = $sceneCount % self::PANEL_COUNT;

        // Distribute remaining scenes
        for ($i = 0; $i < $remainder; $i++) {
            $distribution[$i]++;
        }

        return $distribution;
    }

    /**
     * Check if sentence indicates a scene break
     */
    private function isSceneBreak(string $sentence): bool
    {
        // Scene break indicators
        $breakPatterns = [
            '/meanwhile/i',
            '/later/i',
            '/suddenly/i',
            '/after that/i',
            '/next/i'
        ];

        foreach ($breakPatterns as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate final segmented scenes
     */
    private function validateSegments(array $segments): void
    {
        if (count($segments) !== self::PANEL_COUNT) {
            throw new RuntimeException("Failed to segment story into exactly " . self::PANEL_COUNT . " panels");
        }

        foreach ($segments as $index => $segment) {
            if (empty($segment)) {
                throw new RuntimeException("Panel {$index} is empty");
            }
            if (strlen($segment) < 10) {
                throw new RuntimeException("Panel {$index} content is too short");
            }
        }
    }

    /**
     * Process panel descriptions to ensure they are suitable for generation
     * @param array $descriptions Array of panel descriptions
     * @param array $options Processing options
     * @return array Processed panel descriptions
     */
    public function processPanelDescriptions(array $descriptions, array $options = []): array
    {
        $this->logger->info("Processing panel descriptions", [
            'description_count' => count($descriptions),
            'options' => $options
        ]);

        $processed = [];
        foreach ($descriptions as $index => $description) {
            // Clean and normalize description
            $cleaned = $this->cleanDescription($description);

            // Enhance description with style-specific terminology
            $enhanced = $this->enhanceDescription($cleaned, $options['style'] ?? 'default');

            // Validate description length and content
            $this->validateDescription($enhanced, $index);

            $processed[] = $enhanced;
        }

        return $processed;
    }

    /**
     * Get optimal number of panels based on story content
     * @param string $story Complete story text
     * @param array $options Analysis options
     * @return int Optimal number of panels (defaults to 4)
     */
    public function getOptimalPanelCount(string $story, array $options = []): int
    {
        $this->logger->info("Calculating optimal panel count", [
            'story_length' => strlen($story),
            'options' => $options
        ]);

        // For now, always return 4 as per requirements
        return self::PANEL_COUNT;
    }

    /**
     * Clean and normalize panel description
     */
    private function cleanDescription(string $description): string
    {
        // Remove excess whitespace
        $cleaned = trim(preg_replace('/\s+/', ' ', $description));

        // Ensure proper sentence ending
        if (!preg_match('/[.!?]$/', $cleaned)) {
            $cleaned .= '.';
        }

        return $cleaned;
    }

    /**
     * Enhance description with style-specific terminology
     */
    private function enhanceDescription(string $description, string $style): string
    {
        // Add style-specific enhancements
        switch ($style) {
            case 'manga':
                $description = $this->enhanceMangaStyle($description);
                break;
            case 'comic':
                $description = $this->enhanceComicStyle($description);
                break;
            case 'european':
                $description = $this->enhanceEuropeanStyle($description);
                break;
            default:
                // No specific enhancements for other styles
                break;
        }

        return $description;
    }

    /**
     * Validate panel description
     */
    private function validateDescription(string $description, int $index): void
    {
        if (strlen($description) < 10) {
            throw new RuntimeException("Panel {$index} description is too short");
        }

        if (strlen($description) > 500) {
            throw new RuntimeException("Panel {$index} description is too long");
        }

        if (!preg_match('/[a-zA-Z]/', $description)) {
            throw new RuntimeException("Panel {$index} description must contain text");
        }
    }

    /**
     * Enhance description for manga style
     */
    private function enhanceMangaStyle(string $description): string
    {
        // Add manga-specific visual terminology
        $mangaTerms = [
            '/\b(move|moving)\b/i' => 'action lines',
            '/\b(surprise|surprised|shocking)\b/i' => 'dramatic effect',
            '/\b(emotion|emotional)\b/i' => 'expressive features'
        ];

        return preg_replace(array_keys($mangaTerms), array_values($mangaTerms), $description);
    }

    /**
     * Enhance description for comic style
     */
    private function enhanceComicStyle(string $description): string
    {
        // Add comic-specific visual terminology
        $comicTerms = [
            '/\b(impact|hit|strike)\b/i' => 'pow effect',
            '/\b(fast|quick|rapid)\b/i' => 'speed lines',
            '/\b(loud|shouting)\b/i' => 'bold speech'
        ];

        return preg_replace(array_keys($comicTerms), array_values($comicTerms), $description);
    }

    /**
     * Enhance description for European comic style
     */
    private function enhanceEuropeanStyle(string $description): string
    {
        // Add European comic-specific visual terminology
        $europeanTerms = [
            '/\b(scene|setting)\b/i' => 'detailed background',
            '/\b(mood|atmosphere)\b/i' => 'atmospheric lighting',
            '/\b(gesture|gesturing)\b/i' => 'expressive pose'
        ];

        return preg_replace(array_keys($europeanTerms), array_values($europeanTerms), $description);
    }
}
