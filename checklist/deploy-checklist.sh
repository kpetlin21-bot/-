#!/bin/bash
set -e

REMOTE_USER="p837136"
REMOTE_HOST="p837136.ftp.ihc.ru"
REMOTE_CHECKLIST="/home/p837136/www/api.cleansyst.ru/checklist/"
REMOTE_ROOT="/home/p837136/www/api.cleansyst.ru/"
# На Mac ключ часто: ~/.ssh/ihc_deploy_key (создан 30.05)
DEFAULT_KEY=""
for candidate in "$HOME/.ssh/ihc_deploy_key" "$HOME/.ssh/ihc_cursor_deploy_key"; do
  if [ -f "$candidate" ]; then DEFAULT_KEY="$candidate"; break; fi
done
SSH_KEY="${SSH_KEY:-${DEFAULT_KEY:-$HOME/.ssh/ihc_cursor_deploy_key}}"
DIR="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"

# Cloud Agent secret (вариант B): IHC_SSH_PRIVATE_KEY
SECRET_KEY="${IHC_SSH_PRIVATE_KEY:-${IHC_DEPLOY_KEY:-}}"
if [ ! -f "$SSH_KEY" ] && [ -n "$SECRET_KEY" ]; then
  python3 - "$SSH_KEY" <<'PY'
import os, re, sys, textwrap
path = sys.argv[1]
raw = os.environ.get("IHC_SSH_PRIVATE_KEY") or os.environ.get("IHC_DEPLOY_KEY") or ""
m = re.search(r"BEGIN OPENSSH PRIVATE KEY-----\s*(.+?)\s*-----END", raw, re.S)
if m:
    b64 = re.sub(r"\s+", "", m.group(1))
    body = "\n".join(textwrap.wrap(b64, 70))
    pem = "-----BEGIN OPENSSH PRIVATE KEY-----\n" + body + "\n-----END OPENSSH PRIVATE KEY-----\n"
else:
    pem = raw if raw.endswith("\n") else raw + "\n"
open(path, "w").write(pem)
os.chmod(path, 0o600)
PY
  echo "Using key from IHC_SSH_PRIVATE_KEY secret"
fi

SSH_OPTS=(-o StrictHostKeyChecking=no -o ConnectTimeout=15)
if [ -f "$SSH_KEY" ]; then
  SSH_CMD=(ssh -i "$SSH_KEY" "${SSH_OPTS[@]}")
  RSYNC_SSH="ssh -i $SSH_KEY -o StrictHostKeyChecking=no -o ConnectTimeout=15"
elif [ -n "${SSH_AUTH_SOCK:-}" ] && [ -S "$SSH_AUTH_SOCK" ]; then
  SSH_CMD=(ssh "${SSH_OPTS[@]}")
  RSYNC_SSH="ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15"
else
  echo "ERROR: SSH key not found at $SSH_KEY"
  echo "Set IHC_SSH_PRIVATE_KEY, place key at ~/.ssh/ihc_cursor_deploy_key, or use SSH_AUTH_SOCK"
  exit 1
fi

echo "=== Rsync checklist to server ==="
rsync -avz -e "$RSYNC_SSH" --exclude='.git' --exclude='_gen_index.py' \
  "$DIR/" \
  "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_CHECKLIST}"

echo "=== Deploy proxy.php (houses action) ==="
"${SSH_CMD[@]}" "${REMOTE_USER}@${REMOTE_HOST}" "cat > ${REMOTE_ROOT}proxy.php" < "$(dirname "$DIR")/proxy.php"

echo "=== Create DB tables ==="
"${SSH_CMD[@]}" "${REMOTE_USER}@${REMOTE_HOST}" \
  "mysql -h p837136.mysql.ihc.ru -u p837136_dashbrd -p'Dashboard123' p837136_dashbrd < ${REMOTE_CHECKLIST}sql/schema.sql"

echo "=== Ensure uploads writable ==="
"${SSH_CMD[@]}" "${REMOTE_USER}@${REMOTE_HOST}" \
  "mkdir -p ${REMOTE_CHECKLIST}uploads && chmod 755 ${REMOTE_CHECKLIST}uploads"

echo "=== Done ==="
echo "Checklist: https://api.cleansyst.ru/checklist/"
echo "API save:  https://api.cleansyst.ru/checklist/api/checklist/save.php"
