#!/bin/sh
set -e

# Write environment variables to a file readable only by appuser.
# This avoids the old pattern of dumping everything into /etc/environment (world-readable).
ENV_FILE=/app/.env.runtime
printenv > "$ENV_FILE"
chown appuser:appuser "$ENV_FILE"
chmod 0600 "$ENV_FILE"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] [INFO] Container started — cron scheduled hourly"

exec cron -f
