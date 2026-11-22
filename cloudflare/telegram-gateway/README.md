# Cloudflare Telegram Gateway

Транспортный шлюз Telegram для `zevnull.ru`. ИИ-провайдер и агент не меняются: Python-агент продолжает работать через REG.Cloud, GitHub Actions запускает `telegram-codex.yml`, публикация сайта остаётся на REG.RU.

## Состав

- `src/index.js` — Cloudflare Worker: принимает Telegram webhook, фильтрует пользователей, дедуплицирует updates через D1, запускает GitHub Actions и отправляет уведомления в Telegram.
- `migrations/0001_create_webhook_updates.sql` — таблица дедупликации.
- `wrangler.jsonc` — конфигурация Wrangler.
- `scripts/set-webhook.sh` — регистрация Telegram webhook с безопасным вводом токена.
- `tests/worker.test.js` — локальные тесты Worker.

## Уже существующий экземпляр

Worker: https://zevnull-telegram-gateway.volt7459.workers.dev

D1 binding: `DB`, database name: `zevnull-telegram-state`.

В Cloudflare должны быть secrets: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `GITHUB_TOKEN`, `GATEWAY_SHARED_SECRET`.

Variables: `GITHUB_OWNER=zevnull00`, `GITHUB_REPO=kanban-goals`, `GITHUB_WORKFLOW=telegram-codex.yml`, `GITHUB_REF=main`, `TELEGRAM_ALLOWED_USER_IDS=848638447,663743323`, `ACCEPT_MESSAGES_AFTER`.

## Что сделать один раз на компьютере

1. Склонировать репозиторий и перейти в каталог:

   ```bash
   git clone https://github.com/zevnull00/kanban-goals.git
   cd kanban-goals/cloudflare/telegram-gateway
   ```

2. В `wrangler.jsonc` заменить `REPLACE_WITH_ID_FROM_CLOUDFLARE_D1` на реальный UUID существующей D1 `zevnull-telegram-state` из Cloudflare Dashboard → D1 → database details.

3. Установить Node.js LTS, затем:

   ```bash
   npm install
   npx wrangler login
   ```

4. Секреты не помещать в Git. Если они уже заведены в Cloudflare Worker, повторно вводить их не нужно.

5. Для публикации Worker:

   ```bash
   npx wrangler deploy
   ```

   В Cloudflare должны оставаться привязка D1 `DB` и все четыре secrets.

## Последнее переключение Telegram

Перед переключением webhook установи Worker variable `ACCEPT_MESSAGES_AFTER` в Unix timestamp текущего времени, чтобы накопленные старые сообщения не стали новыми задачами. В PowerShell его можно получить командой:

```powershell
[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
```

Нажми Save and Deploy в Cloudflare. Затем, с включённым VPN, в Git Bash/Linux запусти:

```bash
bash scripts/set-webhook.sh
```

Скрипт запросит Telegram Bot Token, Webhook Secret и адрес Worker. Токены вводятся скрыто. Используется `drop_pending_updates=false`.

## GitHub Actions

В GitHub → Settings → Secrets and variables → Actions должны быть secrets:

- `TELEGRAM_GATEWAY_URL=https://zevnull-telegram-gateway.volt7459.workers.dev`
- `TELEGRAM_GATEWAY_SECRET` — то же значение, что Cloudflare secret `GATEWAY_SHARED_SECRET`.

Workflow `.github/workflows/telegram-codex.yml` отправляет итоговые уведомления через `/notify`. REG.Cloud и деплой на REG.RU не меняются.

## Важно

- Не коммитить bot token, webhook secret, GitHub token или gateway secret.
- Не удалять старый `telegram-webhook.php` до завершения миграции и периода наблюдения.
- При откате зарегистрировать webhook обратно на `https://zevnull.ru/telegram-webhook.php`.
