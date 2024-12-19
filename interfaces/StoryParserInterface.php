<?php

interface StoryParserInterface
{
    /**
     * Segments a story into multiple panels using NLP
     * 
     * @param string $story The complete story to segment
     * @param array $options Optional parameters to control segmentation
     * @return array Array of panel descriptions
     * @throws RuntimeException If story segmentation fails
     */
    public function segmentStory(string $story, array $options = []): array;

    /**
     * Validates and processes panel descriptions for consistency
     * 
     * @param array $panels Array of panel descriptions
     * @return array Processed panel descriptions
     */
    public function processPanelDescriptions(array $panels): array;

    /**
     * Gets the optimal number of panels for a story
     * 
     * @param string $story The story to analyze
     * @return int Recommended number of panels
     */
    public function getOptimalPanelCount(string $story): int;
}
