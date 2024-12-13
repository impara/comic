<?php

require_once __DIR__ . '/../interfaces/LoggerInterface.php';

class DialogueExtractor
{
    private LoggerInterface $logger;

    // Speech patterns and verbs
    private const SPEECH_VERBS = [
        'said',
        'says',
        'asked',
        'asks',
        'shouted',
        'shouts',
        'whispered',
        'whispers',
        'replied',
        'replies',
        'declared',
        'declares',
        'announced',
        'announces',
        'muttered',
        'mutters',
        'exclaimed',
        'exclaims',
        'remarked',
        'remarks',
        'responded',
        'responds',
        'called',
        'calls',
        'yelled',
        'yells'
    ];

    // Different types of quotes by style
    private const QUOTE_PATTERNS_BY_STYLE = [
        'manga' => [
            '[「」].*?[「」]',   // Japanese quotes
            '[『』].*?[『』]',   // Japanese double quotes
            '[（）].*?[（）]'    // Japanese parentheses
        ],
        'comic' => [
            '["].*?["]',         // Standard double quotes
            "['].*?[']"          // Single quotes
        ],
        'european' => [
            '[«»].*?[«»]',       // French/Russian-style quotes
            '[„"].*?["]',        // German-style quotes
            "['].*?[']"           // Single quotes
        ],
        'default' => [
            '["].*?["]',         // Standard double quotes
            "['].*?[']",         // Single quotes
            '[「」].*?[「」]'      // Also support Japanese quotes for flexibility
        ]
    ];

    // Thought patterns
    private const THOUGHT_PATTERNS = [
        'thought to (?:himself|herself|themselves)',
        'wondered',
        'realized',
        'remembered',
        'pondered',
        'contemplated',
        'mused',
        'reflected'
    ];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Extract dialogues from story and associate them with characters
     * 
     * @param string $story The story text
     * @param array $characters Array of character data
     * @param string $artStyle Art style for quote handling (manga, comic, european, or default)
     * @return array Array of dialogues indexed by character index
     */
    public function extractDialogues(string $story, array $characters, string $artStyle = 'default'): array
    {
        $dialogues = [];
        $characterNames = array_map(function ($char) {
            return is_array($char) && isset($char['name']) ? $char['name'] : '';
        }, $characters);

        try {
            // Get appropriate quote patterns for the art style
            $quotePatterns = self::QUOTE_PATTERNS_BY_STYLE[$artStyle] ?? self::QUOTE_PATTERNS_BY_STYLE['default'];

            // Split story into sentences while preserving line breaks
            $sentences = $this->splitIntoSentences($story);

            foreach ($sentences as $sentence) {
                // Extract all types of dialogues using style-specific patterns
                $extractedDialogues = $this->extractAllDialogues($sentence, $quotePatterns);

                foreach ($extractedDialogues as $dialogue) {
                    // Try to associate dialogue with a character
                    $speakingCharacter = $this->findSpeakingCharacter($sentence, $characterNames);

                    if ($speakingCharacter !== false) {
                        if (!isset($dialogues[$speakingCharacter])) {
                            $dialogues[$speakingCharacter] = [
                                'speech' => [],
                                'thoughts' => []
                            ];
                        }

                        // Determine if it's a thought or speech
                        if ($this->isThought($sentence)) {
                            $dialogues[$speakingCharacter]['thoughts'][] = $dialogue;
                        } else {
                            $dialogues[$speakingCharacter]['speech'][] = $dialogue;
                        }
                    }
                }
            }

            // Process dialogues into final format
            $processedDialogues = $this->processDialogues($dialogues);

            $this->logger->info("Dialogues extracted successfully", [
                'num_dialogues' => count($processedDialogues['dialogues']),
                'art_style' => $artStyle
            ]);

            return $processedDialogues;
        } catch (Exception $e) {
            $this->logger->error("Failed to extract dialogues", [
                'error' => $e->getMessage(),
                'art_style' => $artStyle
            ]);
            return ['dialogues' => [], 'thoughts' => []];
        }
    }

    /**
     * Split text into sentences while preserving line breaks
     * 
     * @param string $text Text to split
     * @return array Array of sentences
     */
    private function splitIntoSentences(string $text): array
    {
        // Split by sentence endings but preserve line breaks
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])|(?<=[\n\r])/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_map('trim', $sentences);
    }

    /**
     * Extract all types of dialogues from a sentence using style-specific patterns
     * 
     * @param string $sentence The sentence to analyze
     * @param array $quotePatterns Array of quote patterns to use
     * @return array Array of extracted dialogues
     */
    private function extractAllDialogues(string $sentence, array $quotePatterns): array
    {
        $dialogues = [];

        // Try each quote pattern for the current style
        foreach ($quotePatterns as $pattern) {
            if (preg_match_all("/$pattern/u", $sentence, $matches)) {
                foreach ($matches[0] as $match) {
                    // Clean up the matched dialogue
                    $dialogue = $this->cleanDialogue($match);
                    if (!empty($dialogue)) {
                        $dialogues[] = $dialogue;
                    }
                }
            }
        }

        return $dialogues;
    }

    /**
     * Clean up extracted dialogue by removing quotes and extra whitespace
     * 
     * @param string $dialogue Raw dialogue text
     * @return string Cleaned dialogue text
     */
    private function cleanDialogue(string $dialogue): string
    {
        // Remove various types of quotes based on all supported styles
        $quotes = ['""', "''", '„"', '«»', '『』', '「」', '（）'];
        foreach ($quotes as $quoteSet) {
            $dialogue = trim($dialogue, $quoteSet);
        }

        // Clean up whitespace
        return trim($dialogue);
    }

    /**
     * Check if a sentence contains thought indicators
     * 
     * @param string $sentence The sentence to analyze
     * @return bool True if the sentence indicates thoughts
     */
    private function isThought(string $sentence): bool
    {
        $sentence = strtolower($sentence);

        foreach (self::THOUGHT_PATTERNS as $pattern) {
            if (preg_match("/\b$pattern\b/i", $sentence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find which character is speaking in a sentence
     * 
     * @param string $sentence The sentence to analyze
     * @param array $characterNames Array of character names
     * @return int|false Character index or false if no character found
     */
    private function findSpeakingCharacter(string $sentence, array $characterNames): int|false
    {
        $sentence = strtolower($sentence);

        foreach ($characterNames as $index => $name) {
            if (empty($name)) continue;

            $nameLower = strtolower($name);

            // Check for character name with speech verb
            foreach (self::SPEECH_VERBS as $verb) {
                $patterns = [
                    "/\b" . preg_quote($nameLower, '/') . "\s+" . preg_quote($verb, '/') . "\b/",
                    "/\b" . preg_quote($nameLower, '/') . ",\s+" . preg_quote($verb, '/') . "\b/",
                    "/\b" . preg_quote($verb, '/') . "\b.*?\b" . preg_quote($nameLower, '/') . "\b/",
                    "/\b" . preg_quote($verb, '/') . "\b.*?the\s+" . preg_quote($nameLower, '/') . "\b/"
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $sentence)) {
                        return $index;
                    }
                }
            }

            // Check for character name at start of sentence with quotes following
            if (preg_match("/^" . preg_quote($nameLower, '/') . "\b.*?[\"'「『]/i", $sentence)) {
                return $index;
            }

            // Check for character name before any quote
            if (preg_match("/\b" . preg_quote($nameLower, '/') . "\b.*?[\"'「『]/i", $sentence)) {
                return $index;
            }
        }

        return false;
    }

    /**
     * Process extracted dialogues into final format
     * 
     * @param array $rawDialogues Raw extracted dialogues
     * @return array Processed dialogues
     */
    private function processDialogues(array $rawDialogues): array
    {
        $processed = ['dialogues' => [], 'thoughts' => []];

        foreach ($rawDialogues as $charIndex => $dialogueTypes) {
            // Join multiple speech lines with newlines
            if (!empty($dialogueTypes['speech'])) {
                $processed['dialogues'][$charIndex] = implode("\n", $dialogueTypes['speech']);
            }

            // Join multiple thought lines with newlines
            if (!empty($dialogueTypes['thoughts'])) {
                $processed['thoughts'][$charIndex] = implode("\n", $dialogueTypes['thoughts']);
            }
        }

        return $processed;
    }
}
