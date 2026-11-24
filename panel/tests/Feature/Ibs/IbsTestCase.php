<?php

namespace Tests\Feature\Ibs;

use App\Ibs\IbsConnector;
use App\Ibs\Transport;
use App\Services\Ibs\AccountActions;
use App\Services\Ibs\AccountSync;
use App\Services\Ibs\Provisioning;
use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakeIbs;
use Tests\TestCase;

abstract class IbsTestCase extends TestCase
{
    protected FakeIbs $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->useFake(new FakeIbs('system', 'secret'));
        $s = app(Settings::class);
        $s->set('ibs.host', '127.0.0.1');
        $s->set('ibs.username', 'system');
        $s->set('ibs.password', 'secret', encrypt: true);
    }

    protected function useFake(FakeIbs $fake): void
    {
        $this->fake = $fake;
        $this->app->instance(Transport::class, $fake);
        $this->app->forgetInstance(IbsConnector::class);
        foreach ([AccountSync::class, Provisioning::class, AccountActions::class] as $c) {
            $this->app->forgetInstance($c);
        }
    }

    protected function syncCatalog(): void
    {
        app(AccountSync::class)->syncCatalog();
    }

    /** Create a user directly in the fake IBSng (as if made outside the panel). */
    protected function ibsUser(string $username, string $group = 'test', float $credit = 0, string $password = 'pw123456'): int
    {
        $id = $this->fake->nextId++;
        $this->fake->users[$id] = ['username' => $username, 'password' => $password, 'group' => $group, 'credit' => $credit,
            'attrs' => [], 'first_login' => null, 'created' => time()];

        return $id;
    }
}
