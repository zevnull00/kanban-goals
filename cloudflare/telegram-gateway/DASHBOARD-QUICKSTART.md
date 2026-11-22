# Быстрая настройка через Cloudflare Dashboard

1. Открой Worker `zevnull-telegram-gateway` → Edit code / Quick edit. Вставь содержимое `src/index.js` из репозитория и нажми Deploy.
2. Открой D1 `zevnull-telegram-state` → Console. Выполни SQL из `migrations/0001_create_webhook_updates.sql`.
3. Worker → Settings → Bindings → D1 database. Binding name: `DB`; выбери `zevnull-telegram-state`; сохрани.
4. Worker → Settings → Variables and Secrets. Установи:
   - `GITHUB_OWNER=zevnull00`
   - `GITHUB_REPO=kanban-goals`
   - `GITHUB_WORKFLOW=telegram-codex.yml`
   - `GITHUB_REF=main`
   - `TELEGRAM_ALLOWED_USER_IDS=848638447,663743323`
   - `ACCEPT_MESSAGES_AFTER=0` до момента переключения.
5. Добавь Worker secrets:
   - `TELEGRAM_BOT_TOKEN`
   - `TELEGRAM_WEBHOOK_SECRET`
   - `GITHUB_TOKEN`
   - `GATEWAY_SHARED_SECRET`
6. Сохрани изменения и Deploy.
7. Перед переключением Telegram webhook выставь `ACCEPT_MESSAGES_AFTER` в актуальный Unix timestamp, затем Save and Deploy.
8. На Windows запусти `scripts/set-webhook.ps1` из PowerShell. На Linux/macOS можно запустить `bash scripts/set-webhook.sh`.
9. Старый `telegram-webhook.php` не удаляй до окончания периода наблюдения.

Секреты вводятся в Cloudflare напрямую и не должны попадать в Git или переписку.
