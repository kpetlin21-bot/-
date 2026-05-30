#!/bin/bash
# Deploy to IHC server via scp over SSH
# Usage: bash deploy.sh

set -e

REMOTE_USER="p837136"
REMOTE_HOST="p837136.ftp.ihc.ru"
REMOTE_PORT="22"
REMOTE_PATH="/home/p837136/www/api.cleansyst.ru/"
SSH_KEY="$HOME/.ssh/ihc_cursor_deploy_key"
DIR="$(cd "$(dirname "$0")" && pwd)"

if [ ! -f "$SSH_KEY" ]; then
  echo "ERROR: SSH key not found at $SSH_KEY"
  exit 1
fi

echo "=== Deploying to $REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH ==="

scp -i "$SSH_KEY" -P "$REMOTE_PORT" -o StrictHostKeyChecking=no -o ConnectTimeout=15 \
  "$DIR/index.html" \
  "$DIR/proxy.php" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

echo ""
echo "=== Deploy complete! ==="
ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no "$REMOTE_USER@$REMOTE_HOST" \
  "ls -lh ${REMOTE_PATH}index.html ${REMOTE_PATH}proxy.php"
echo "Dashboard: https://api.cleansyst.ru/index.html"
echo "Proxy:     https://api.cleansyst.ru/proxy.php?action=help"
