# AI Comic Generator

A web application that generates comic strips from text stories using AI models.

## Project Structure

```
.
├── 404.html
├── 500.html
├── IMAGES.md
├── README.md
├── TECH_STACK.md
├── api.php
├── assets
│   ├── backgrounds
│   │   ├── city.png
│   │   ├── fantasy.png
│   │   ├── nature.png
│   │   └── space.png
│   ├── characters
│   ├── css
│   │   └── styles.css
│   ├── images
│   │   ├── hero-bg.jpg
│   │   ├── logo-white.png
│   │   ├── logo.png
│   │   ├── placeholder-character.png
│   │   ├── user1.jpg
│   │   └── user2.jpg
│   ├── js
│   │   ├── character-selector.js
│   │   ├── comic-generator.js
│   │   ├── config.js
│   │   ├── debug.js
│   │   ├── form-handler.js
│   │   ├── main.js
│   │   ├── sharing.js
│   │   ├── story-examples.js
│   │   └── ui-manager.js
│   └── styles
│       ├── cartoon.png
│       ├── classic.png
│       ├── manga.png
│       └── modern.png
├── assistant_snippet_syRMV
├── bootstrap.php
├── cache
├── comic.amertech.online.conf
├── config
│   └── config.php
├── controllers
│   └── ComicController.php
├── deploy.sh
├── index.html
├── input.html
├── interfaces
│   ├── LoggerInterface.php
│   └── StoryParserInterface.php
├── logs
│   ├── app.log
│   └── php_errors.log
├── models
│   ├── CacheManager.php
│   ├── CharacterProcessor.php
│   ├── ComicGenerator.php
│   ├── Config.php
│   ├── FileManager.php
│   ├── HttpClient.php
│   ├── ImageComposer.php
│   ├── Logger.php
│   ├── ReplicateClient.php
│   ├── StateManager.php
│   └── StoryParser.php
├── output
├── public
│   ├── generated
│   │   └── image.png
│   └── temp
│       └── nlp_cache
│           └── ab3ba504fd74a6991838c843d521b269.cache
├── services
│   └── Orchestrator.php
├── tests
│   └── config_test.php
├── utils
│   └── EnvLoader.php
└── webhook.php
```

## Features

- Story-to-comic generation using AI models
- Character customization and cartoonification
- Background generation with various styles
- Real-time progress tracking
- Webhook integration for AI model callbacks

## Requirements

- PHP 8.0 or higher
- Web server (Apache/Nginx)
- Composer for PHP dependencies
- Write permissions for `public/temp` and `public/output` directories

## Installation

1. Clone the repository:

```bash
git clone https://github.com/yourusername/comic-generator.git
cd comic-generator
```

2. Install dependencies:

```bash
composer install
```

3. Copy `.env.example` to `.env` and configure your environment:

```bash
cp .env.example .env
```

4. Ensure write permissions for storage directories:

```bash
chmod -R 755 public/temp public/output
```

## Key Features

- Simple file-based job state management
- Real-time progress tracking through polling
- Unified webhook handling for all AI model callbacks
- Clean separation of concerns with Orchestrator pattern
- Secure file locking for concurrent operations

## API Endpoints

- `POST /api.php`: Start a new comic generation job

  - Returns: `{"success": true, "jobId": "<id>"}`

- `GET /api.php?action=status&jobId=<id>`: Check job status

  - Returns: `{"status": "processing|completed|failed", "progress": 0-100, "output_url": "..."}`

- `POST /webhook.php`: Receive AI model callbacks
  - Handles: Cartoonification, background generation, and story segmentation results

## Configuration

Key configuration files:

- `.env`: Environment variables
- `config/config.php`: Application configuration

## Security

- All user uploads are validated and sanitized
- File permissions are strictly controlled
- No sensitive data in public directories
- Secure webhook handling with job ID validation

## Contributing

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

This project is licensed under the MIT License - see the LICENSE file for details.
