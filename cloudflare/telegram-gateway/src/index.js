const JSON_HEADERS = {
  "content-type": "application/json; charset=utf-8",
  "cache-control": "no-store",
};

const LOCK_STALE_MS = 120_000;

function json(data, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: JSON_HEADERS });
}

function safeEqual(left, right) {
  if (typeof left !== "string" || typeof right !== "string" || left.length !== right.length) {
    return false;
  }
  let diff = 0;
  for (let i = 0; i < left.length; i += 1) {
    diff |= left.charCodeAt(i) ^ right.charCodeAt(i);
  }
  return diff === 0;
}

function allowedUserIds(env) {
  return new Set(
    String(env.TELEGRAM_ALLOWED_USER_IDS || "")
      .split(",")
      .map((item) => item.trim())
      .filter(Boolean),
  );
}

async function readJson(request, maxBytes = 128 * 1024) {
  const declaredLength = Number(request.headers.get("content-length") || 0);
  if (declaredLength > maxBytes) {
    throw new Error("body_too_large");
  }
  const text = await request.text();
  if (new TextEncoder().encode(text).byteLength > maxBytes) {
    throw new Error("body_too_large");
  }
  try {
    return JSON.parse(text);
  } catch {
    throw new Error("invalid_json");
  }
}

async function telegramApi(env, method, payload) {
  if (!env.TELEGRAM_BOT_TOKEN) {
    return { ok: false, status: 500, description: "telegram_bot_token_missing" };
  }

  let response;
  try {
    response = await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/${method}`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(12_000),
    });
  } catch (error) {
    console.error("telegram_api_network_error", String(error?.name || "Error"));
    return { ok: false, status: 0, description: "telegram_api_network_error" };
  }

  let result;
  try {
    result = await response.json();
  } catch {
    result = null;
  }

  return {
    ok: response.ok && result?.ok === true,
    status: response.status,
    description: typeof result?.description === "string" ? result.description.slice(0, 300) : null,
  };
}

async function markUpdate(env, updateId, status, detail = null) {
  await env.DB.prepare(
    "UPDATE webhook_updates SET status = ?, updated_at = ?, detail = ? WHERE update_id = ?",
  )
    .bind(status, new Date().toISOString(), detail, updateId)
    .run();
}

async function claimUpdate(env, updateId) {
  const retentionCutoff = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString();
  await env.DB.prepare("DELETE FROM webhook_updates WHERE created_at < ?")
    .bind(retentionCutoff)
    .run();

  const now = new Date();
  const nowIso = now.toISOString();
  const inserted = await env.DB.prepare(
    "INSERT INTO webhook_updates (update_id, status, created_at, updated_at) VALUES (?, 'processing', ?, ?) ON CONFLICT(update_id) DO NOTHING",
  )
    .bind(updateId, nowIso, nowIso)
    .run();

  if ((inserted.meta?.changes || 0) === 1) {
    return { claimed: true, duplicate: false };
  }

  const existing = await env.DB.prepare(
    "SELECT status, updated_at FROM webhook_updates WHERE update_id = ?",
  )
    .bind(updateId)
    .first();

  if (!existing) {
    throw new Error("dedupe_record_missing");
  }

  if (existing.status === "done" || existing.status === "uncertain") {
    return { claimed: false, duplicate: true, status: existing.status };
  }

  const age = Date.now() - Date.parse(existing.updated_at || "1970-01-01T00:00:00Z");
  if (existing.status === "processing" && age < LOCK_STALE_MS) {
    return { claimed: false, duplicate: true, status: "processing" };
  }

  const reclaimed = await env.DB.prepare(
    "UPDATE webhook_updates SET status = 'processing', updated_at = ?, detail = NULL WHERE update_id = ? AND status = ? AND updated_at = ?",
  )
    .bind(nowIso, updateId, existing.status, existing.updated_at)
    .run();

  return {
    claimed: (reclaimed.meta?.changes || 0) === 1,
    duplicate: (reclaimed.meta?.changes || 0) !== 1,
    status: existing.status,
  };
}

async function sendMessage(env, chatId, text) {
  return telegramApi(env, "sendMessage", {
    chat_id: String(chatId),
    text,
    disable_web_page_preview: true,
  });
}

async function handleTelegram(request, env) {
  const receivedSecret = request.headers.get("X-Telegram-Bot-Api-Secret-Token") || "";
  const expectedSecret = String(env.TELEGRAM_WEBHOOK_SECRET || "");
  if (!expectedSecret || !receivedSecret || !safeEqual(receivedSecret, expectedSecret)) {
    return json({ ok: false, error: "forbidden" }, 403);
  }

  let update;
  try {
    update = await readJson(request);
  } catch (error) {
    const message = String(error.message || "");
    return json({ ok: false, error: message || "invalid_request" }, message === "body_too_large" ? 413 : 400);
  }

  if (!update || !Number.isSafeInteger(update.update_id)) {
    return json({ ok: true, ignored: true, reason: "missing_update_id" });
  }

  const message = update.message;
  if (!message || !message.chat || !message.from) {
    return json({ ok: true, ignored: true, reason: "not_a_message_update" });
  }

  const chatId = String(message.chat.id ?? "");
  const userId = String(message.from.id ?? "");
  if (message.chat.type !== "private" || !chatId || !userId || chatId !== userId) {
    return json({ ok: true, ignored: true, reason: "not_a_private_chat" });
  }

  const allowlist = allowedUserIds(env);
  if (!allowlist.has(userId)) {
    return json({ ok: true, ignored: true, reason: "unauthorized" });
  }

  const updateId = String(update.update_id);
  let claim;
  try {
    claim = await claimUpdate(env, updateId);
  } catch (error) {
    console.error("dedupe_error", String(error?.message || error));
    return json({ ok: false, error: "dedupe_unavailable" }, 503);
  }

  if (!claim.claimed) {
    return json({ ok: true, duplicate: true, status: claim.status || "already_processed" });
  }

  const text = String(message.text || "").trim();
  const commandToken = (text.split(/\s+/, 1)[0] || "").replace(/@\w+$/, "");
  const messageDate = Number(message.date || 0);
  const acceptAfter = Number(env.ACCEPT_MESSAGES_AFTER || 0);

  if (acceptAfter > 0 && messageDate > 0 && messageDate < acceptAfter) {
    await markUpdate(env, updateId, "done", "before_cutover_ignored");
    return json({ ok: true, ignored: true, reason: "before_cutover" });
  }

  let outgoing;
  if (commandToken === "/start" || commandToken === "/help") {
    outgoing = await sendMessage(
      env,
      chatId,
      "Привет! Я помощник сайта zevnull.ru.\n\nНапиши обычным сообщением, что нужно изменить на сайте. Например:\n«Сделай заголовок главной страницы крупнее и поменяй текст приветствия».\n\nЗадачу обработает Python-агент через REG.Cloud, затем GitHub проверит изменения и при успехе опубликует сайт на REG.RU.",
    );
    if (!outgoing.ok) {
      await markUpdate(env, updateId, "retry", `telegram_send_${outgoing.status}`);
      return json({ ok: false, error: "telegram_reply_failed" }, 502);
    }
    await markUpdate(env, updateId, "done", commandToken.slice(1));
    return json({ ok: true, message_sent: true });
  }

  if (!text) {
    outgoing = await sendMessage(env, chatId, "Пока принимаю только текстовые задачи. Напиши словами, что нужно изменить.");
    if (!outgoing.ok) {
      await markUpdate(env, updateId, "retry", `telegram_send_${outgoing.status}`);
      return json({ ok: false, error: "telegram_reply_failed" }, 502);
    }
    await markUpdate(env, updateId, "done", "empty_text");
    return json({ ok: true, message_sent: true });
  }

  if (Array.from(text).length > 3000) {
    outgoing = await sendMessage(env, chatId, "Задача слишком длинная. Разбей её на несколько сообщений (до 3000 символов каждое).");
    if (!outgoing.ok) {
      await markUpdate(env, updateId, "retry", `telegram_send_${outgoing.status}`);
      return json({ ok: false, error: "telegram_reply_failed" }, 502);
    }
    await markUpdate(env, updateId, "done", "text_too_long");
    return json({ ok: true, message_sent: true });
  }

  const owner = String(env.GITHUB_OWNER || "");
  const repo = String(env.GITHUB_REPO || "");
  const workflow = String(env.GITHUB_WORKFLOW || "telegram-codex.yml");
  const ref = String(env.GITHUB_REF || "main");
  if (!owner || !repo || !env.GITHUB_TOKEN || !/^[A-Za-z0-9._-]+$/.test(workflow)) {
    await markUpdate(env, updateId, "retry", "github_configuration_missing");
    return json({ ok: false, error: "github_configuration_missing" }, 503);
  }

  const dispatchUrl = `https://api.github.com/repos/${encodeURIComponent(owner)}/${encodeURIComponent(repo)}/actions/workflows/${encodeURIComponent(workflow)}/dispatches`;
  let githubResponse;
  try {
    githubResponse = await fetch(dispatchUrl, {
      method: "POST",
      headers: {
        Accept: "application/vnd.github+json",
        Authorization: `Bearer ${env.GITHUB_TOKEN}`,
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "zevnull-telegram-gateway",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        ref,
        inputs: {
          task: text,
          telegram_chat_id: chatId,
          telegram_user_id: userId,
        },
      }),
      signal: AbortSignal.timeout(12_000),
    });
  } catch (error) {
    console.error("github_dispatch_outcome_unknown", String(error?.name || "Error"));
    await markUpdate(env, updateId, "uncertain", "github_network_outcome_unknown");
    return json({ ok: true, accepted: "outcome_unknown", update_id: updateId });
  }

  if (githubResponse.status !== 204) {
    const responseText = await githubResponse.text().catch(() => "");
    const detail = `github_http_${githubResponse.status}:${responseText.slice(0, 180)}`;
    console.error("github_dispatch_rejected", githubResponse.status, responseText.slice(0, 180));
    await markUpdate(env, updateId, "retry", detail);
    return json({ ok: false, error: "github_dispatch_failed", status: githubResponse.status }, 502);
  }

  await markUpdate(env, updateId, "done", "github_workflow_dispatched");

  const acknowledgement = await sendMessage(
    env,
    chatId,
    "Принял задачу. Запускаю ИИ-агента REG.Cloud. Он проверит изменение, а после завершения я пришлю сюда результат.",
  );
  if (!acknowledgement.ok) {
    console.error("task_acknowledgement_failed", acknowledgement.status, acknowledgement.description || "");
  }

  return json({ ok: true, workflow_dispatched: true, acknowledgement_sent: acknowledgement.ok });
}

async function handleNotify(request, env) {
  const receivedSecret = request.headers.get("X-Gateway-Secret") || "";
  const expectedSecret = String(env.GATEWAY_SHARED_SECRET || "");
  if (!expectedSecret || !receivedSecret || !safeEqual(receivedSecret, expectedSecret)) {
    return json({ ok: false, error: "forbidden" }, 403);
  }

  let payload;
  try {
    payload = await readJson(request, 32 * 1024);
  } catch (error) {
    const message = String(error.message || "");
    return json({ ok: false, error: message || "invalid_request" }, message === "body_too_large" ? 413 : 400);
  }

  const chatId = String(payload.chat_id || "");
  const text = String(payload.text || "");
  if (!chatId || !allowedUserIds(env).has(chatId) || !text || Array.from(text).length > 3500) {
    return json({ ok: false, error: "invalid_notification" }, 400);
  }

  const result = await sendMessage(env, chatId, text);
  if (!result.ok) {
    console.error("notification_delivery_failed", result.status, result.description || "");
    return json({ ok: false, error: "telegram_delivery_failed", status: result.status }, 502);
  }
  return json({ ok: true, message_sent: true });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method === "GET" && url.pathname === "/") {
      return json({ ok: true, service: "zevnull-telegram-gateway", endpoints: ["/healthz", "/telegram", "/notify"] });
    }

    if (request.method === "GET" && url.pathname === "/healthz") {
      try {
        await env.DB.prepare("SELECT 1 AS ok").first();
        const requiredSecrets = [
          "TELEGRAM_BOT_TOKEN",
          "TELEGRAM_WEBHOOK_SECRET",
          "GITHUB_TOKEN",
          "GATEWAY_SHARED_SECRET",
        ];
        const missingSecrets = requiredSecrets.filter((key) => !String(env[key] || ""));
        if (missingSecrets.length > 0) {
          return json({
            ok: false,
            service: "zevnull-telegram-gateway",
            database: "ok",
            secrets_configured: false,
            missing_secrets: missingSecrets,
          }, 503);
        }
        return json({
          ok: true,
          service: "zevnull-telegram-gateway",
          database: "ok",
          secrets_configured: true,
        });
      } catch (error) {
        console.error("healthcheck_database_error", String(error?.message || error));
        return json({ ok: false, service: "zevnull-telegram-gateway", database: "error" }, 503);
      }
    }

    if (request.method === "POST" && url.pathname === "/telegram") {
      return handleTelegram(request, env);
    }

    if (request.method === "POST" && url.pathname === "/notify") {
      return handleNotify(request, env);
    }

    return json({ ok: false, error: "not_found" }, 404);
  },
};
