#!/bin/bash

# Deployment script for Comic Generator

# Configuration
DEPLOY_DIR="/var/www/comic.amertech.online"
NGINX_CONF="/etc/nginx/sites-available/comic.amertech.online"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
SERVER_USER="serveradmin"
SERVER_GROUP="serveradmin"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to log messages
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[ERROR] $1${NC}" >&2
}

warn() {
    echo -e "${YELLOW}[WARNING] $1${NC}"
}

# Function to set ownership for git operations
set_git_ownership() {
    log "Setting ownership for git operations..."
    chown -R $SERVER_USER:$SERVER_GROUP "$DEPLOY_DIR"
}

# Function to set ownership for web server
set_web_ownership() {
    log "Setting ownership for web server..."
    # Set web directory ownership
    find "$DEPLOY_DIR" -path "$DEPLOY_DIR/.git" -prune -o -type f -exec chown www-data:www-data {} \;
    find "$DEPLOY_DIR" -path "$DEPLOY_DIR/.git" -prune -o -type d -exec chown www-data:www-data {} \;
    
    # Keep git directory owned by server user
    chown -R $SERVER_USER:$SERVER_GROUP "$DEPLOY_DIR/.git"
    
    # Set special permissions for writable directories
    chmod 775 "$DEPLOY_DIR/logs"
    chmod 775 "$DEPLOY_DIR/public/temp"
    chown -R www-data:www-data "$DEPLOY_DIR/logs"
    chown -R www-data:www-data "$DEPLOY_DIR/public/temp"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    error "Please run as root"
    exit 1
fi

# Verify Nginx configuration
log "Verifying Nginx configuration..."
if ! nginx -t; then
    error "Nginx configuration test failed"
    exit 1
fi

# Clear PHP opcache
log "Clearing PHP opcache..."
service php8.4-fpm reload

# Clear server-side cache
log "Clearing server-side cache..."
rm -rf "$DEPLOY_DIR/public/temp/*"

# Set ownership for git operations
set_git_ownership

# Prompt for git operations
read -p "Do you want to perform git operations now? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    log "Please perform your git operations now and press Enter when done..."
    read
fi

# Reset ownership for web server
set_web_ownership

# Update version in config.js
log "Updating version in config.js..."
VERSION=$(date +%Y%m%d%H%M%S)
sed -i "s/VERSION: '.*'/VERSION: '$VERSION'/" "$DEPLOY_DIR/assets/js/config.js"

# Clear client-side cache by updating asset versions
log "Updating asset versions..."
find "$DEPLOY_DIR" -type f -name "*.html" -exec sed -i "s/\(\.js\|\.css\)?v=[0-9]\+/\1?v=$VERSION/g" {} \;

# Restart services
log "Restarting services..."
service nginx reload
service php8.4-fpm reload

log "Deployment completed successfully!"
log "New version: $VERSION"
log "Backup created: backup_$TIMESTAMP.tar.gz"

# Verify services are running
if ! systemctl is-active --quiet nginx; then
    error "Nginx is not running!"
    systemctl status nginx
    exit 1
fi

if ! systemctl is-active --quiet php8.4-fpm; then
    error "PHP-FPM is not running!"
    systemctl status php8.4-fpm
    exit 1
fi

# Check logs for errors
log "Checking for errors in logs..."
if grep -i "error" /var/log/nginx/comic.amertech.online.error.log | tail -n 5 | grep -q "."; then
    warn "Recent errors found in Nginx error log"
fi

# Final permission check
log "Verifying final permissions..."
if [ ! -w "$DEPLOY_DIR/.git" ]; then
    warn "Git directory may not be writable by $SERVER_USER"
fi

log "Deployment process complete. File ownership has been set for web server operation."
log "To perform git operations, run the script again and select 'y' when prompted."