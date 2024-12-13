<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/CharacterProcessor.php';
require_once __DIR__ . '/ReplicateClient.php';

class ComicGenerator
{
    private LoggerInterface $logger;
    private Config $config;
    private CharacterProcessor $characterProcessor;
    private ReplicateClient $replicateClient;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::getInstance();
        $this->characterProcessor = new CharacterProcessor($logger);
        $this->replicateClient = new ReplicateClient($logger);
    }

    /**
     * Generate a comic panel with characters
     * @param array $characters Array of character data
     * @param string $sceneDescription Description of the scene
     * @return array Generated comic data
     */
    public function generatePanel(array $characters, string $sceneDescription): array
    {
        $this->logger->info("Starting comic panel generation", [
            'character_count' => count($characters),
            'description_length' => strlen($sceneDescription)
        ]);

        try {
            // Process each custom character
            $processedCharacters = [];
            foreach ($characters as $character) {
                if (!isset($character['image'])) {
                    throw new Exception("Character image is required");
                }
                $processedCharacter = $this->characterProcessor->processCharacter($character);
                $processedCharacters[] = $processedCharacter;
            }

            // Generate the panel
            $result = $this->replicateClient->generateImage([
                'prompt' => $sceneDescription,
                'characters' => $processedCharacters,
                'options' => [
                    'style' => $characters[0]['options']['style'] ?? 'modern'
                ]
            ]);

            $this->logger->info("Panel generation completed", [
                'result' => $result
            ]);

            return $result;
        } catch (Exception $e) {
            $this->logger->error("Panel generation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
