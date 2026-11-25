<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThrottleTest extends TestCase
{
    public function test_limits_are_counted_per_route_not_shared(): void
    {
        Http::fake();
        $u = $this->superAdmin();
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($u)->post('/bot-link')->assertRedirect();   // throttle:10,1
        }
        $this->actingAs($u)->post('/bot-link')->assertStatus(429);
        // another throttled route of the same user is unaffected
        $this->actingAs($u)->from('/bots')->post('/bots/reach')->assertRedirect();
    }
}
