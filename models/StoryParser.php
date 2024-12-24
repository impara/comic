<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';

class StoryParser
{
    private LoggerInterface $logger;
    private Config $config;
    private const PANEL_COUNT = 4;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
    }

    /**
     * Segment a story into panels and trigger webhook callback
     */
    public function segmentStory(string $story, array $options = []): array
    {
        $this->logger->info("Starting story segmentation", [
            'story_length' => strlen($story),
            'target_panels' => self::PANEL_COUNT,
            'story_content' => $story,
            'options' => $options
        ]);

        try {
            // For now, use simple sentence-based segmentation
            $segments = $this->simpleSegmentation($story);

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

            // Process panel descriptions
            $processedSegments = $this->processPanelDescriptions($segments, $options);

            $this->logger->info("Story segmentation completed", [
                'final_segment_count' => count($processedSegments),
                'segments' => array_map(fn($s) => [
                    'length' => strlen($s),
                    'content' => $s
                ], $processedSegments)
            ]);

            // Prepare webhook payload
            $webhookPayload = [
                'type' => 'llama_complete',
                'job_id' => $options['job_id'] ?? null,
                'segments' => $processedSegments,
                'status' => 'completed'
            ];

            // If webhook URL is provided, send the callback
            if (!empty($options['webhook'])) {
                $this->sendWebhookCallback($options['webhook'], $webhookPayload);
            }

            return $webhookPayload;
        } catch (Exception $e) {
            $this->logger->error("Story segmentation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'story_length' => strlen($story)
            ]);

            // Prepare error webhook payload
            $webhookPayload = [
                'type' => 'llama_complete',
                'job_id' => $options['job_id'] ?? null,
                'status' => 'failed',
                'error' => $e->getMessage()
            ];

            // If webhook URL is provided, send the error callback
            if (!empty($options['webhook'])) {
                $this->sendWebhookCallback($options['webhook'], $webhookPayload);
            }

            throw $e;
        }
    }

    /**
     * Send webhook callback
     */
    private function sendWebhookCallback(string $webhookUrl, array $payload): void
    {
        try {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: ComicGenerator/1.0'
                ],
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($statusCode >= 400) {
                throw new Exception("Webhook request failed with status code: $statusCode");
            }

            $this->logger->debug("Webhook callback sent", [
                'url' => $webhookUrl,
                'status_code' => $statusCode,
                'response' => $response
            ]);
        } catch (Exception $e) {
            $this->logger->error("Failed to send webhook callback", [
                'url' => $webhookUrl,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Simple segmentation based on sentences and paragraphs
     */
    private function simpleSegmentation(string $story): array
    {
        // Split into sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($story));
        $sentences = array_filter($sentences); // Remove empty sentences
        $sentences = array_values($sentences); // Re-index array

        $this->logger->debug('Segmenting story', [
            'sentence_count' => count($sentences),
            'sentences' => $sentences
        ]);

        // If we have fewer sentences than needed panels, duplicate some
        while (count($sentences) < self::PANEL_COUNT) {
            $sentences[] = end($sentences);
        }

        // Calculate sentences per segment
        $sentencesPerSegment = ceil(count($sentences) / self::PANEL_COUNT);

        // Group sentences into segments
        $segments = [];
        for ($i = 0; $i < self::PANEL_COUNT; $i++) {
            $start = $i * $sentencesPerSegment;
            $length = min($sentencesPerSegment, count($sentences) - $start);

            if ($length > 0) {
                $segmentSentences = array_slice($sentences, $start, $length);
                $segments[] = implode(' ', $segmentSentences);
            }
        }

        // If we still don't have enough segments, duplicate the last one
        while (count($segments) < self::PANEL_COUNT) {
            $segments[] = end($segments);
        }

        // If we have too many segments, merge the last ones
        if (count($segments) > self::PANEL_COUNT) {
            $extraSegments = array_slice($segments, self::PANEL_COUNT - 1);
            $segments = array_slice($segments, 0, self::PANEL_COUNT - 1);
            $segments[] = implode(' ', $extraSegments);
        }

        $this->logger->debug('Segmentation result', [
            'segment_count' => count($segments),
            'segments' => $segments
        ]);

        return $segments;
    }

    /**
     * Expand scenes to reach target panel count
     */
    private function expandScenes(array $segments): array
    {
        $this->logger->debug('Expanding scenes', [
            'initial_count' => count($segments)
        ]);

        while (count($segments) < self::PANEL_COUNT) {
            // Find the longest segment to split
            $maxLength = 0;
            $maxIndex = 0;
            foreach ($segments as $i => $segment) {
                if (strlen($segment) > $maxLength) {
                    $maxLength = strlen($segment);
                    $maxIndex = $i;
                }
            }

            // Split the longest segment
            $sentences = preg_split('/(?<=[.!?])\s+/', $segments[$maxIndex]);
            if (count($sentences) < 2) {
                // If we can't split further, duplicate the segment
                $segments[] = $segments[$maxIndex];
            } else {
                $midpoint = ceil(count($sentences) / 2);
                $firstHalf = implode(' ', array_slice($sentences, 0, $midpoint));
                $secondHalf = implode(' ', array_slice($sentences, $midpoint));
                array_splice($segments, $maxIndex, 1, [$firstHalf, $secondHalf]);
            }
        }

        $this->logger->debug('Scene expansion result', [
            'final_count' => count($segments),
            'segments' => $segments
        ]);

        return $segments;
    }

    /**
     * Consolidate scenes to reach target panel count
     */
    private function consolidateScenes(array $segments): array
    {
        $this->logger->debug('Consolidating scenes', [
            'initial_count' => count($segments)
        ]);

        while (count($segments) > self::PANEL_COUNT) {
            // Find the shortest adjacent segments to merge
            $minLength = PHP_INT_MAX;
            $minIndex = 0;
            for ($i = 0; $i < count($segments) - 1; $i++) {
                $combinedLength = strlen($segments[$i]) + strlen($segments[$i + 1]);
                if ($combinedLength < $minLength) {
                    $minLength = $combinedLength;
                    $minIndex = $i;
                }
            }

            // Merge the segments
            $merged = $segments[$minIndex] . ' ' . $segments[$minIndex + 1];
            array_splice($segments, $minIndex, 2, [$merged]);
        }

        $this->logger->debug('Scene consolidation result', [
            'final_count' => count($segments),
            'segments' => $segments
        ]);

        return $segments;
    }

    /**
     * Validate the final segments
     */
    private function validateSegments(array $segments): void
    {
        if (count($segments) !== self::PANEL_COUNT) {
            throw new Exception("Invalid segment count: " . count($segments));
        }

        foreach ($segments as $segment) {
            if (empty(trim($segment))) {
                throw new Exception("Empty segment detected");
            }
        }
    }

    /**
     * Process panel descriptions to enhance them for image generation
     */
    public function processPanelDescriptions(array $segments, array $options = []): array
    {
        $style = $options['style'] ?? 'default';
        $characters = $options['characters'] ?? [];

        return array_map(function ($segment) use ($style, $characters) {
            $characterNames = implode(', ', array_map(fn($c) => $c['name'], $characters));
            $stylePrefix = $style ? "In $style style: " : '';
            $characterInfo = $characterNames ? " Characters present: $characterNames. " : '';

            return $stylePrefix . $segment . $characterInfo;
        }, $segments);
    }
}
