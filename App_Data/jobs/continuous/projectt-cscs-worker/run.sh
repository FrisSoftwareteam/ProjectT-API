#!/usr/bin/env bash
set -euo pipefail

PROJECTT_APP_ROOT="${PROJECTT_APP_ROOT:-/home/site/wwwroot}"
PROJECTT_CSCS_TIMEOUT="${CSCS_IMPORT_JOB_TIMEOUT:-3600}"

cd "$PROJECTT_APP_ROOT"

if [[ ! -f artisan ]]; then
    echo "CSCS worker could not find Laravel artisan in $PROJECTT_APP_ROOT" >&2
    exit 1
fi

exec php artisan queue:work \
    --queue="${CSCS_QUEUE:-cscs}" \
    --sleep=1 \
    --tries=1 \
    --timeout="$PROJECTT_CSCS_TIMEOUT" \
    --max-time=3600 \
    --no-interaction
