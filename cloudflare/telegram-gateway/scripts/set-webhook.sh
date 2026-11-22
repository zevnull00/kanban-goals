#!/usr/bin/env bash
set -euo pipefail

read -r -s -p "Telegram bot token (input hidden): " BOT_TOKEN
printf '\n'
read -r -s -p "Telegram webhook secret (input hidden): " WEBHOOK_SECRET
printf '\n'
read -r -p "Worker base URL (e.g. https://zevnull-telegram-gateway.YOUR-SUBDOMAIN.workers.dev): " WORKER_URL

[[ -n "$BOT_TOKEN" && -n "$WEBHOOK_SECRET" && -n "$WORKER_URL" ]] || {
  echo "All values are required." >&2
  exit 1
}

[[ "$WEBHOOK_SECRET" =~ ^[A-Za-z0-9_-]{1,256}$ ]] || {
  echo "Webhook secret must contain only A-Z, a-z, 0-9, underscore or hyphen (1-256 chars)." >&2
  exit 1
}

[[ "$WORKER_URL" == https://*.workers.dev ]] || {
  echo "Use the HTTPS workers.dev URL returned by Wrangler." >&2
  exit 1
}

WORKER_URL="${WORKER_URL%/}"

jq -n \
  --arg url "${WORKER_URL}/telegram" \
  --arg secret "$WEBHOOK_SECRET" \
  '{url:$url, secret_token:$secret, allowed_updates:["message"], max_connections:10, drop_pending_updates:false}' \
  | curl --fail-with-body --silent --show-error \
      "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
      -H 'Content-Type: application/json' \
      --data-binary @-

printf '\nWebhook registration request completed. Verify it with getWebhookInfo.\n'
