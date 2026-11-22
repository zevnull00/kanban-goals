import test, { beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import gateway from "../src/index.js";

class FakeD1 {
  constructor() {
    this.rows = new Map();
  }

  prepare(sql) {
    const db = this;
    let params = [];
    return {
      bind(...values) {
        params = values;
        return this;
      },
      async first() {
        if (sql.startsWith("SELECT 1")) return { ok: 1 };
        if (sql.startsWith("SELECT status, updated_at")) {
          const row = db.rows.get(params[0]);
          return row ? { status: row.status, updated_at: row.updated_at } : null;
        }
        throw new Error(`Unsupported fake SQL first(): ${sql}`);
      },
      async run() {
        if (sql.startsWith("DELETE FROM webhook_updates")) {
          let changes = 0;
          for (const [id, row] of db.rows) {
            if (row.created_at < params[0]) {
              db.rows.delete(id);
              changes += 1;
            }
          }
          return { meta: { changes } };
        }
        if (sql.startsWith("INSERT INTO webhook_updates")) {
          const [id, createdAt, updatedAt] = params;
          if (db.rows.has(id)) return { meta: { changes: 0 } };
          db.rows.set(id, { status: "processing", created_at: createdAt, updated_at: updatedAt, detail: null });
          return { meta: { changes: 1 } };
        }
        if (sql.startsWith("UPDATE webhook_updates SET status = 'processing'")) {
          const [updatedAt, id, oldStatus, oldUpdatedAt] = params;
          const row = db.rows.get(id);
          if (!row || row.status !== oldStatus || row.updated_at !== oldUpdatedAt) return { meta: { changes: 0 } };
          row.status = "processing";
          row.updated_at = updatedAt;
          row.detail = null;
          return { meta: { changes: 1 } };
        }
        if (sql.startsWith("UPDATE webhook_updates SET status = ?")) {
          const [status, updatedAt, detail, id] = params;
          const row = db.rows.get(id);
          if (!row) return { meta: { changes: 0 } };
          Object.assign(row, { status, updated_at: updatedAt, detail });
          return { meta: { changes: 1 } };
        }
        throw new Error(`Unsupported fake SQL run(): ${sql}`);
      },
    };
  }
}

const originalFetch = globalThis.fetch;
let calls;
let env;

beforeEach(() => {
  calls = [];
  env = {
    DB: new FakeD1(),
    TELEGRAM_BOT_TOKEN: "test-token",
    TELEGRAM_WEBHOOK_SECRET: "test-webhook-secret",
    GATEWAY_SHARED_SECRET: "test-gateway-secret",
    GITHUB_TOKEN: "test-github-token",
    GITHUB_OWNER: "zevnull00",
    GITHUB_REPO: "kanban-goals",
    GITHUB_WORKFLOW: "telegram-codex.yml",
    GITHUB_REF: "main",
    TELEGRAM_ALLOWED_USER_IDS: "848638447,663743323",
    ACCEPT_MESSAGES_AFTER: "0",
  };
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url: String(url), options });
    if (String(url).includes("api.telegram.org")) {
      return new Response(JSON.stringify({ ok: true, result: { message_id: 1 } }), { status: 200 });
    }
    if (String(url).includes("api.github.com")) return new Response(null, { status: 204 });
    throw new Error(`Unexpected fetch target: ${url}`);
  };
});

afterEach(() => {
  globalThis.fetch = originalFetch;
});

function makeUpdate(updateId, text, userId = 848638447, date = Math.floor(Date.now() / 1000)) {
  return {
    update_id: updateId,
    message: {
      message_id: updateId,
      date,
      text,
      chat: { id: userId, type: "private" },
      from: { id: userId },
    },
  };
}

function telegramRequest(update, secret = "test-webhook-secret") {
  return new Request("https://gateway.test/telegram", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "X-Telegram-Bot-Api-Secret-Token": secret,
    },
    body: JSON.stringify(update),
  });
}

test("healthz reports database and configured secrets", async () => {
  const response = await gateway.fetch(new Request("https://gateway.test/healthz"), env);
  assert.equal(response.status, 200);
  assert.deepEqual(await response.json(), {
    ok: true,
    service: "zevnull-telegram-gateway",
    database: "ok",
    secrets_configured: true,
  });
});

test("missing webhook secret fails closed", async () => {
  env.TELEGRAM_WEBHOOK_SECRET = "";
  const response = await gateway.fetch(telegramRequest(makeUpdate(1, "/start"), ""), env);
  assert.equal(response.status, 403);
  assert.equal(calls.length, 0);
  assert.equal(env.DB.rows.size, 0);
});

test("/start answers through Telegram and does not dispatch GitHub", async () => {
  const response = await gateway.fetch(telegramRequest(makeUpdate(10, "/start@our_kanban_helper_bot")), env);
  assert.equal(response.status, 200);
  assert.equal(calls.length, 1);
  assert.match(calls[0].url, /api\.telegram\.org/);
  assert.equal(env.DB.rows.get("10").status, "done");
});

test("/help answers through Telegram and does not dispatch GitHub", async () => {
  const response = await gateway.fetch(telegramRequest(makeUpdate(11, "/help")), env);
  assert.equal(response.status, 200);
  assert.equal(calls.length, 1);
  assert.match(calls[0].url, /api\.telegram\.org/);
  assert.equal(env.DB.rows.get("11").status, "done");
});

test("ordinary text dispatches one GitHub workflow and deduplicates retry", async () => {
  const update = makeUpdate(20, "Проверь текущую версию сайта, ничего не меняй");
  const first = await gateway.fetch(telegramRequest(update), env);
  assert.equal(first.status, 200);
  assert.equal(calls.length, 2);
  assert.match(calls[0].url, /api\.github\.com/);
  assert.match(calls[1].url, /api\.telegram\.org/);
  assert.equal(env.DB.rows.get("20").status, "done");
  assert.equal((await first.json()).acknowledgement_sent, true);

  const duplicate = await gateway.fetch(telegramRequest(update), env);
  assert.equal(duplicate.status, 200);
  assert.equal((await duplicate.json()).duplicate, true);
  assert.equal(calls.length, 2);
});

test("old queued task is acknowledged but not dispatched after cutover", async () => {
  env.ACCEPT_MESSAGES_AFTER = String(Math.floor(Date.now() / 1000));
  const oldDate = Number(env.ACCEPT_MESSAGES_AFTER) - 10;
  const response = await gateway.fetch(telegramRequest(makeUpdate(30, "СТАРАЯ ЗАДАЧА", 848638447, oldDate)), env);
  assert.equal(response.status, 200);
  assert.equal((await response.json()).reason, "before_cutover");
  assert.equal(calls.length, 0);
  assert.equal(env.DB.rows.get("30").status, "done");
});

test("notify endpoint rejects an empty configured secret", async () => {
  env.GATEWAY_SHARED_SECRET = "";
  const response = await gateway.fetch(new Request("https://gateway.test/notify", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ chat_id: "848638447", text: "test" }),
  }), env);
  assert.equal(response.status, 403);
  assert.equal(calls.length, 0);
});

test("notify sends a Telegram message only with the gateway secret", async () => {
  const response = await gateway.fetch(new Request("https://gateway.test/notify", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "X-Gateway-Secret": "test-gateway-secret",
    },
    body: JSON.stringify({ chat_id: "848638447", text: "Gateway test" }),
  }), env);
  assert.equal(response.status, 200);
  assert.equal((await response.json()).message_sent, true);
  assert.equal(calls.length, 1);
  assert.match(calls[0].url, /api\.telegram\.org/);
});

test("unauthorized Telegram user cannot write D1 or dispatch workflow", async () => {
  const response = await gateway.fetch(telegramRequest(makeUpdate(40, "do something", 111111111)), env);
  assert.equal(response.status, 200);
  assert.equal((await response.json()).reason, "unauthorized");
  assert.equal(env.DB.rows.size, 0);
  assert.equal(calls.length, 0);
});

test("unknown GitHub network outcome is not automatically dispatched twice", async () => {
  globalThis.fetch = async (url, options = {}) => {
    calls.push({ url: String(url), options });
    throw new TypeError("simulated network timeout");
  };
  const update = makeUpdate(50, "проверь сайт");
  const first = await gateway.fetch(telegramRequest(update), env);
  assert.equal(first.status, 200);
  assert.equal((await first.json()).accepted, "outcome_unknown");
  assert.equal(env.DB.rows.get("50").status, "uncertain");

  const duplicate = await gateway.fetch(telegramRequest(update), env);
  assert.equal(duplicate.status, 200);
  assert.equal((await duplicate.json()).duplicate, true);
  assert.equal(calls.length, 1);
});
