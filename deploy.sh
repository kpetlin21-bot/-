#!/bin/bash
# Deploy to IHC server via rsync over SSH
# Usage: bash deploy.sh

set -e

REMOTE_USER="p837136"
REMOTE_HOST="p837136.ftp.ihc.ru"
REMOTE_PORT="22"
REMOTE_PATH="/home/p837136/www/api.cleansyst.ru/"
SSH_KEY="$HOME/.ssh/ihc_cursor_deploy_key"

echo "=== Deploying to $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH ==="

rsync -avz --progress \
  -e "ssh -i $SSH_KEY -p $REMOTE_PORT -o StrictHostKeyChecking=no -o ConnectTimeout=15" \
  --include="index.html" \
  --include="proxy.php" \
  --exclude="*" \
  /workspace/ \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

echo ""
echo "=== Deploy complete! ==="
echo "Dashboard: https://api.cleansyst.ru/index.html"
echo "Proxy:     https://api.cleansyst.ru/proxy.php?action=help"
