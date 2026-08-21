#!/usr/bin/env bash
set -euo pipefail

PROJECTT_APP_ROOT="${PROJECTT_APP_ROOT:-/home/site/wwwroot}"

cd "$PROJECTT_APP_ROOT"

if [[ ! -f artisan ]]; then
    echo "Notification worker could not find Laravel artisan in $PROJECTT_APP_ROOT" >&2
    exit 1
fi

exec php artisan queue:work \
    --queue=default \
    --sleep=1 \
    --tries=3 \
    --timeout=120 \
    --max-time=3600 \
    --no-interaction
