<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Router;
use Tests\Feature\Finance\ResellerTestCase;

/** Walks every parameterless GET page as guest, as a user without permissions, as a reseller and as super admin. */
class RouteSecurityTest extends ResellerTestCase
{
    private const PUBLIC = ['login', 'up', 'install', 'manifest.webmanifest'];   // the app manifest is public by design (no data)

    /** Pages any logged-in user may open (personal pages). */
    private const PERSONAL = ['dashboard', 'profile/password', 'bot-link', 'confirm-password'];

    /** @return list<string> */
    private function pages(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{') && ! str_starts_with($r->uri(), '_'))
            ->map(fn (Route $r) => $r->uri())->unique()->values()->all();
    }

    public function test_guest_is_sent_to_login_everywhere(): void
    {
        foreach ($this->pages() as $uri) {
            if (in_array($uri, self::PUBLIC, true)) {
                continue;
            }
            $res = $this->get('/'.ltrim($uri, '/'));
            $this->assertTrue($res->isRedirect(route('login')) || $res->status() === 404, "guest got {$res->status()} on /$uri");
        }
    }

    public function test_user_without_permissions_is_refused_on_admin_pages(): void
    {
        $u = $this->userWith([]);
        foreach ($this->pages() as $uri) {
            if (in_array($uri, [...self::PUBLIC, ...self::PERSONAL], true) || str_starts_with($uri, 'r') && ($uri === 'r' || str_starts_with($uri, 'r/'))) {
                continue;
            }
            $s = $this->actingAs($u)->get('/'.$uri)->status();
            $this->assertContains($s, [403, 302], "no-permission user got $s on /$uri");
        }
        $this->actingAs($u)->get('/')->assertForbidden();
    }

    public function test_reseller_only_reaches_portal_and_personal_pages(): void
    {
        $r = $this->reseller();
        foreach ($this->pages() as $uri) {
            if (in_array($uri, self::PUBLIC, true)) {
                continue;
            }
            $s = $this->actingAs($r->user)->get('/'.$uri)->status();
            if ($uri === 'r' || str_starts_with($uri, 'r/') || in_array($uri, self::PERSONAL, true)) {
                $this->assertContains($s, [200, 302], "reseller got $s on own page /$uri");
            } else {
                $this->assertContains($s, [403, 302], "reseller got $s on admin page /$uri");
            }
        }
    }

    public function test_every_admin_page_renders_for_super_admin(): void
    {
        Http::fake();
        $admin = $this->superAdmin();
        foreach ($this->pages() as $uri) {
            if (in_array($uri, ['logout', 'install', 'r'], true) || str_starts_with($uri, 'r/') || str_starts_with($uri, 'cron')) {
                continue;
            }
            $res = $this->actingAs($admin)->confirmed()->get('/'.$uri);
            $this->assertContains($res->status(), [200, 302], "super admin got {$res->status()} on /$uri");
        }
    }
}
