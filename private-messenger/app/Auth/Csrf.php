<?php
declare(strict_types=1);

namespace PM\Auth;

use PM\Http\Request;
use PM\Http\Response;
use PM\Support\Random;

/** Double-submit CSRF token bound to a __Host- cookie, plus an Origin/Referer same-origin check. */
final class Csrf
{
    public const COOKIE = '__Host-csrf';
    public const FIELD = '_csrf';

    public static function token(Request $req): string
    {
        if (isset($req->attributes['csrf'])) {
            return $req->attributes['csrf'];
        }
        $c = $req->cookie(self::COOKIE);
        if ($c === null || !preg_match('/^[A-Za-z0-9_-]{43}$/', $c)) {
            $c = Random::token(32);
            $req->attributes['csrf_new'] = true;
        }
        return $req->attributes['csrf'] = $c;
    }

    public static function attach(Request $req, Response $res): Response
    {
        if (!empty($req->attributes['csrf_new'])) {
            $res->cookie(self::COOKIE, (string)$req->attributes['csrf'], ['samesite' => 'Lax']);
        }
        return $res;
    }

    public static function valid(Request $req): bool
    {
        $c = $req->cookie(self::COOKIE);
        $f = $req->input(self::FIELD);
        return $c !== null && $f !== '' && hash_equals($c, $f) && $req->isSameOrigin();
    }
}
