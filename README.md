# Comic Strip Generator

A system for generating comic panels using AI image generation, built with PHP 8.3.

## Project Structure

```
├── api.php              # Main API endpoint
├── controllers/         # Application controllers
├── models/             # Data models
├── utils/              # Utility functions
├── workers/            # Background job workers
├── public/             # Public assets
├── types/              # Type definitions
├── interfaces/         # Interface definitions
├── config/             # Configuration files
└── logs/              # Application logs
```

## Technology Stack

- **Backend**: PHP 8.3
- **Queue System**: Redis
- **API Integration**: Replicate AI

## Setup & Development

1. Clone the repository and set up environment:
```bash
cp .env.example .env
# Edit .env with your settings
```

2. Install dependencies:
```bash
composer install
```

3. Start the Redis server

4. Start the worker process:
```bash
php workers/process.php
```

## API Endpoints

### Generate Comic Panel
```http
POST /api.php
Content-Type: application/json

{
    "action": "generate",
    "characters": [
        {
            "description": "A tall superhero with a red cape",
            "image": "optional-base64-or-url"
        }
    ],
    "scene_description": "Flying through a cityscape at night"
}
```

### Check Job Status
```http
GET /job_status.php?job_ids=job_123,job_456
```

## Configuration

- Environment variables: See `.env.example`
- Application config: `config/` directory

## Monitoring & Logs

- Application logs are stored in `logs/`
- System logs can be viewed in the logs directory

## Error Handling

- Failed jobs are automatically retried
- Comprehensive error logging
- Job status tracking through Redis
- Error notifications via configured channels

## Development

For detailed technical information and development guidelines, see:
- `TECH_STACK.md` - Technical architecture details

## License

This project is proprietary and confidential. 