<?php

namespace App\Bots;

use App\Models\Bot;

/** Ready-to-paste relay programs for Telegram (outside Iran). Secrets are embedded; shown only to bots.sensitive. */
class RelayCode
{
    public static function cloudflare(Bot $bot): string
    {
        $secret = $bot->relaySecret();
        $hook = $bot->hookUrl();

        return <<<JS
// Telegram relay for the sales panel — Cloudflare Workers (paste as the whole worker script)
const SECRET = "{$secret}";
const PANEL_HOOK = "{$hook}";

export default {
  async fetch(request) {
    const url = new URL(request.url);
    if (request.method !== "POST") return new Response("ok");

    // Telegram -> panel
    if (url.pathname === "/hook") {
      if (request.headers.get("X-Telegram-Bot-Api-Secret-Token") !== SECRET) return new Response("forbidden", { status: 403 });
      const r = await fetch(PANEL_HOOK, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Relay-Secret": SECRET },
        body: await request.text(),
      });
      return new Response("ok", { status: r.ok ? 200 : 502 });
    }

    // panel -> Telegram
    const m = url.pathname.match(/^\/api\/bot([^/]+)\/([A-Za-z]+)$/);
    if (m) {
      if (request.headers.get("X-Relay-Secret") !== SECRET) return new Response("forbidden", { status: 403 });
      const r = await fetch("https://api.telegram.org/bot" + m[1] + "/" + m[2], {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: await request.text(),
      });
      return new Response(await r.text(), { status: r.status, headers: { "Content-Type": "application/json" } });
    }
    return new Response("not found", { status: 404 });
  },
};
JS;
    }

    public static function appsScript(Bot $bot): string
    {
        $secret = $bot->relaySecret();
        $hook = $bot->hookUrl();

        return <<<JS
// Telegram relay for the sales panel — Google Apps Script (Deploy > New deployment > Web app,
// Execute as: Me, Who has access: Anyone). Paste the /exec URL into the panel.
var SECRET = "{$secret}";
var PANEL_HOOK = "{$hook}";

function doPost(e) {
  var body = (e && e.postData) ? e.postData.contents : "";
  var data = {};
  try { data = JSON.parse(body); } catch (x) {}

  // panel -> Telegram
  if (data && data.s !== undefined) {
    if (data.s !== SECRET) return json_({ ok: false, description: "forbidden" });
    var r = UrlFetchApp.fetch("https://api.telegram.org/bot" + data.token + "/" + data.method, {
      method: "post", contentType: "application/json", payload: JSON.stringify(data.params || {}), muteHttpExceptions: true
    });
    return ContentService.createTextOutput(r.getContentText()).setMimeType(ContentService.MimeType.JSON);
  }

  // Telegram -> panel
  if (!e.parameter || e.parameter.s !== SECRET) return json_({ ok: false });
  UrlFetchApp.fetch(PANEL_HOOK, {
    method: "post", contentType: "application/json", headers: { "X-Relay-Secret": SECRET }, payload: body, muteHttpExceptions: true
  });
  return json_({ ok: true });
}

function doGet() { return json_({ ok: true }); }

function json_(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}
JS;
    }
}
