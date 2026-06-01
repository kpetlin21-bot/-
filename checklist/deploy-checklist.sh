#!/bin/bash
set -e

REMOTE_USER="p837136"
REMOTE_HOST="p837136.ftp.ihc.ru"
REMOTE_CHECKLIST="/home/p837136/www/api.cleansyst.ru/checklist/"
REMOTE_ROOT="/home/p837136/www/api.cleansyst.ru/"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/ihc_cursor_deploy_key}"
DIR="$(cd "$(dirname "$0")" && pwd)"

RSYNC_SSH="ssh -i $SSH_KEY -o StrictHostKeyChecking=no -o ConnectTimeout=15"

if [ ! -f "$SSH_KEY" ]; then
  echo "ERROR: SSH key not found at $SSH_KEY"
  exit 1
fi

echo "=== Rsync checklist to server ==="
rsync -avz -e "$RSYNC_SSH" --exclude='.git' --exclude='_gen_index.py' \
  "$DIR/" \
  "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_CHECKLIST}"

echo "=== Deploy proxy.php (houses action) ==="
scp -i "$SSH_KEY" -o StrictHostKeyChecking=no \
  "$(dirname "$DIR")/proxy.php" \
  "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_ROOT}proxy.php"

echo "=== Create DB tables ==="
ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no "${REMOTE_USER}@${REMOTE_HOST}" \
  "mysql -h p837136.mysql.ihc.ru -u p837136_dashbrd -p'Dashboard123' p837136_dashbrd < ${REMOTE_CHECKLIST}sql/schema.sql"

echo "=== Ensure uploads writable ==="
ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no "${REMOTE_USER}@${REMOTE_HOST}" \
  "mkdir -p ${REMOTE_CHECKLIST}uploads && chmod 755 ${REMOTE_CHECKLIST}uploads"

echo "=== Done ==="
echo "Checklist: https://api.cleansyst.ru/checklist/"
echo "API save:  https://api.cleansyst.ru/checklist/api/checklist/save.php"
