// Story examples for the comic generator
const storyExamples = [
    {
        title: "Manga Style: Training Journey",
        text: `In a misty mountain dojo, Young Kai nervously grips a wooden sword while Master Chen watches calmly. Master Chen steps forward, his movements fluid and graceful as he demonstrates an ancient sword technique, energy visibly swirling around his blade. Kai attempts to copy the move but stumbles, frustration clear on his face. Master Chen gently corrects Kai's stance, speaking words of encouragement. With renewed determination, Kai tries again, and this time his wooden sword glows with inner power as he perfectly executes the technique. Master Chen smiles proudly at his student's achievement.`,
        style: "manga",
        characters: [
            {
                name: "Kai",
                role: "protagonist",
                description: "A young, determined student in training gear",
                emotions: ["nervous", "frustrated", "determined", "triumphant"]
            },
            {
                name: "Master Chen",
                role: "mentor",
                description: "A wise, elderly martial arts master in traditional robes",
                emotions: ["calm", "focused", "encouraging", "proud"]
            }
        ],
        styleParams: {
            line_weight: "dynamic",
            shading_style: "cel",
            color_palette: "vibrant",
            background_detail: "medium"
        }
    },
    {
        title: "European Comic: The Mystery Unfolds",
        text: `Detective Sarah Morgan carefully examines mysterious footprints in the grand library, while her partner Tom Watson photographs the evidence. A librarian, Ms. Reed, anxiously points them to a dusty book on a high shelf. Sarah climbs the ladder and discovers an ancient map with a cryptic symbol, her eyes widening with recognition. Tom notices a pattern in the floor tiles matching the symbol, leading them to a hidden mechanism. Together, Sarah and Tom activate the mechanism, revealing a secret door behind the bookshelf. Ms. Reed gasps as the door swings open, revealing a chamber where a mysterious artifact glows on a pedestal.`,
        style: "european",
        characters: [
            {
                name: "Sarah Morgan",
                role: "protagonist",
                description: "Sharp-eyed detective in a classic trench coat",
                emotions: ["focused", "intrigued", "excited", "triumphant"]
            },
            {
                name: "Tom Watson",
                role: "partner",
                description: "Methodical detective with camera equipment",
                emotions: ["curious", "analytical", "alert", "amazed"]
            },
            {
                name: "Ms. Reed",
                role: "supporting",
                description: "Elderly librarian with glasses and cardigan",
                emotions: ["worried", "helpful", "nervous", "astonished"]
            }
        ],
        styleParams: {
            line_weight: "clean",
            shading_style: "detailed",
            color_palette: "muted",
            background_detail: "high"
        }
    },
    {
        title: "Modern Comic: Team Victory",
        text: `Captain Nova hovers above the city, coordinating her team as a massive robot rampages through downtown. Speedster zips between the robot's legs, creating a vortex, while Techna hacks into its systems from a nearby rooftop. The robot staggers as its systems conflict, giving Strongarm the opening to leap from a building and deliver a powerful punch. Captain Nova descends next to her team as they gather together, the malfunctioning robot sparking behind them. The team shares a victorious moment as the sun sets, while city workers begin cleanup in the background. A mysterious figure watches from the shadows, suggesting this isn't over.`,
        style: "modern",
        characters: [
            {
                name: "Captain Nova",
                role: "leader",
                description: "Commanding hero in sleek cosmic-powered suit",
                emotions: ["determined", "focused", "confident", "proud"]
            },
            {
                name: "Speedster",
                role: "team_member",
                description: "Young hero in aerodynamic racing suit",
                emotions: ["energetic", "playful", "focused", "triumphant"]
            },
            {
                name: "Techna",
                role: "team_member",
                description: "Tech genius with holographic interfaces",
                emotions: ["concentrated", "strategic", "satisfied", "relieved"]
            },
            {
                name: "Strongarm",
                role: "team_member",
                description: "Powerful hero in reinforced armor",
                emotions: ["eager", "intense", "powerful", "celebratory"]
            }
        ],
        styleParams: {
            line_weight: "bold",
            shading_style: "modern",
            color_palette: "vibrant",
            background_detail: "high",
            lighting_scheme: "dramatic"
        }
    }
];

// Helper function to get character emotions for a specific panel
export function getCharacterEmotionsForPanel(story, panelIndex, totalPanels) {
    const progressPercentage = panelIndex / (totalPanels - 1);

    return story.characters.map(character => {
        // Map panel position to emotion index
        const emotionIndex = Math.min(
            Math.floor(progressPercentage * character.emotions.length),
            character.emotions.length - 1
        );
        return {
            name: character.name,
            emotion: character.emotions[emotionIndex]
        };
    });
}

// Helper function to get style parameters for a specific panel
export function getStyleParamsForPanel(story, panelIndex, totalPanels) {
    const baseParams = story.styleParams || {};
    const panelPosition = panelIndex / (totalPanels - 1);

    // Adjust parameters based on panel position
    const adjustedParams = {
        ...baseParams,
        lighting_intensity: panelPosition < 0.5 ? 'normal' : 'dramatic',
        perspective: panelIndex === 0 ? 'wide-shot' :
            panelIndex === totalPanels - 1 ? 'dramatic-angle' : 'dynamic',
        focus_point: panelIndex === 0 ? 'scene-setting' :
            panelIndex === totalPanels - 1 ? 'climactic' : 'action'
    };

    return adjustedParams;
}

// Helper function to analyze scene transitions
export function analyzeSceneTransition(story, fromPanel, toPanel) {
    return {
        type: 'sequential',  // or 'parallel', 'flashback', etc.
        locationChange: false,
        timeChange: false,
        emotionShift: getEmotionShift(fromPanel, toPanel),
        continuityNotes: []
    };
}

// Helper function to get emotion shift between panels
function getEmotionShift(fromPanel, toPanel) {
    // Analyze emotional changes between panels
    return {
        intensity: 'gradual',  // or 'dramatic', 'subtle'
        direction: 'positive'  // or 'negative', 'neutral'
    };
}

export default storyExamples;