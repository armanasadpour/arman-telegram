<?php

namespace App\Bots;

use App\Models\Bot;

/**
 * Relay programs for Telegram (run outside Iran). Same protocol as the news plugin on this host, so an existing
 * Worker / Apps Script from that plugin works as-is. They hold no secret: the bot token travels with each call,
 * over HTTPS, and the panel fetches messages itself (getUpdates) — Telegram never has to reach this host.
 */
class RelayCode
{
    public static function cloudflare(Bot $bot): string
    {
        return <<<'JS'
// Telegram relay — Cloudflare Workers (Workers & Pages > Create Worker > Edit code > paste > Deploy)
// Transparent: https://<worker>/bot<token>/<method>  ==  https://api.telegram.org/bot<token>/<method>
export default {
  async fetch(request) {
    const url = new URL(request.url);
    if (!url.pathname.startsWith("/bot")) return new Response("ok");
    const targetUrl = "https://api.telegram.org" + url.pathname + url.search;
    return fetch(new Request(targetUrl, request));
  },
};
JS;
    }

    public static function appsScript(Bot $bot): string
    {
        return <<<'JS'
// Telegram relay — Google Apps Script
// Deploy > New deployment > Web app; Execute as: Me; Who has access: Anyone. Paste the /exec URL into the panel.
function doGet(e) { return requestHandler(e); }
function doPost(e) { return requestHandler(e); }

function requestHandler(e) {
  return ContentService.createTextOutput(handleRequest(e)).setMimeType(ContentService.MimeType.JSON);
}

function handleRequest(e) {
  if (!e || !e.parameter || typeof e.parameter.bot_token === 'undefined') return JSON.stringify({ ok: true, relay: 'ready' });
  if (typeof e.parameter.method === 'undefined') return JSON.stringify({ ok: false, description: 'method missing' });
  var data = { method: 'post', muteHttpExceptions: true, contentType: 'application/json' };
  if (typeof e.parameter.args !== 'undefined') data.payload = e.parameter.args;
  var res = UrlFetchApp.fetch('https://api.telegram.org/bot' + e.parameter.bot_token + '/' + e.parameter.method, data);
  return res.getContentText();
}
JS;
    }
}
