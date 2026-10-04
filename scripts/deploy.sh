#!/usr/bin/env bash
# Production deploy. Run ON THE SERVER as user "ubuntu": scripts/deploy.sh
# Optional env: DEPLOY_URL (default https://13-50-9-19.sslip.io)
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

DEPLOY_URL="${DEPLOY_URL:-https://13-50-9-19.sslip.io}"

echo "==> Checking working tree"
dirty="$(git status --short)"
if [ -n "$dirty" ]; then
    echo "$dirty"
    echo "ERROR: local modifications found, refusing to deploy." >&2
    exit 1
fi

previous="$(git rev-parse HEAD)"
tmp="$(mktemp -d)"
body="$(mktemp)"
trap 'rm -rf "$tmp" "$body"' EXIT

echo "==> Fetching"
git fetch
if [ "$(git rev-parse '@{u}')" = "$previous" ]; then
    echo "Already up to date"
else
    if ! git merge-base --is-ancestor HEAD '@{u}'; then
        echo "ERROR: cannot fast-forward, history diverged. Nothing was changed." >&2
        exit 1
    fi

    echo "==> Linting incoming PHP files"
    git archive '@{u}' src public | tar -x -C "$tmp"
    failed=0
    while IFS= read -r -d '' file; do
        out="$(php -l "$file" 2>&1)" || { echo "$out" >&2; failed=1; }
    done < <(find "$tmp" -name '*.php' -print0)
    if [ "$failed" -ne 0 ]; then
        echo "ERROR: PHP syntax errors, the new code was NOT applied." >&2
        exit 1
    fi

    echo "==> Updating (fast-forward only)"
    git merge --ff-only '@{u}'

    echo "==> Installing dependencies"
    composer install --no-dev --optimize-autoloader --no-interaction
fi
current="$(git rev-parse HEAD)"

echo "==> Reloading Apache (clears APCu and OPcache)"
sudo systemctl reload apache2

echo "==> Warming up via $DEPLOY_URL"
status="$(curl -sS -G --max-time 30 -o "$body" -w '%{http_code}' \
    --data-urlencode 'q=נעליים' "$DEPLOY_URL/api/search" || true)"
if [ "$status" != "200" ]; then
    echo "ERROR: warm-up returned HTTP ${status:-000}. Apache was already reloaded: the new code is LIVE. Check the logs or roll back." >&2
    exit 1
fi

if grep -Eq '"semantic" *: *true' "$body"; then
    echo "Semantic search OK"
else
    echo "WARNING: semantic is false, lexical fallback is active (Voyage may be failing)" >&2
fi
took="$(grep -Eo '"took_ms" *: *[0-9.]+' "$body" | grep -Eo '[0-9.]+$' || true)"
echo "took_ms: ${took:-unknown}"

echo "==> Done: $(git rev-parse --short "$previous") -> $(git rev-parse --short "$current")"
echo "Manual rollback: git reset --hard $(git rev-parse --short "$previous") && composer install --no-dev --optimize-autoloader --no-interaction && sudo systemctl reload apache2"
