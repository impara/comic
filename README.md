# AI Comic Generator

A web application that generates comic strips from text stories using AI models.

## Project Structure

```
├── api.php             # Main API endpoint
├── webhook.php         # Webhook handler for AI model callbacks
├── models/             # Core business logic
├── services/          # Service layer (Orchestrator, etc.)
├── public/            # Public assets and generated files
│   ├── temp/         # Temporary files and job states
│   └── output/       # Generated comic outputs
├── config/           # Configuration files
└── assets/           # Frontend assets
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
