<?php

namespace Tests\Feature\Ibs;

use App\Ibs\ErrorMessages;
use App\Ibs\HttpTransport;
use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Ibs\IbsNotConfiguredException;
use App\Ibs\IbsUnavailableException;
use App\Ibs\Transport;
use App\Ibs\XmlRpcCodec;
use App\Models\Setting;
use App\Services\Settings;

class ConnectorTest extends IbsTestCase
{
    public function test_reads_and_missing_username_returns_null(): void
    {
        $ibs = app(IbsConnector::class);
        $this->assertSame(['1-Month-kerio', 'test', 'vol-30d'], $ibs->listGroups());
        $this->assertNull($ibs->userByUsername('nobody'));
        $this->assertTrue($ibs->usernameAvailable('free_name'));
        $this->ibsUser('taken');
        $this->assertFalse($ibs->usernameAvailable('taken'));
    }

    public function test_wrong_admin_password_maps_to_persian_message(): void
    {
        app(Settings::class)->set('ibs.password', 'wrong', encrypt: true);
        try {
            app(IbsConnector::class)->listGroups();
            $this->fail('expected fault');
        } catch (IbsException $e) {
            $this->assertSame('INCORRECT_PASSWORD', $e->ibsCode);
            $this->assertSame('رمز مدیر IBSng نادرست است.', ErrorMessages::for($e));
        }
    }

    public function test_reads_are_retried_on_transport_errors(): void
    {
        $this->fake->failNext('group.listGroups', new IbsUnavailableException('timeout'));
        $this->assertCount(3, app(IbsConnector::class)->listGroups());
        $this->assertSame(['group.listGroups', 'group.listGroups'], $this->fake->calls);
    }

    public function test_writes_are_never_retried(): void
    {
        $this->fake->failNext('user.addNewUsers', new IbsUnavailableException('timeout'));
        try {
            app(IbsConnector::class)->addRawUser('test', 0, 'x');
            $this->fail('expected exception');
        } catch (IbsUnavailableException) {
        }
        $this->assertSame(['user.addNewUsers'], $this->fake->calls);
        $this->assertCount(0, $this->fake->users);
    }

    public function test_breaker_opens_after_repeated_failures_and_fails_fast(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->fake->failNext('user.addNewUsers', new IbsUnavailableException('down'));
        }
        $ibs = app(IbsConnector::class);
        for ($i = 0; $i < 3; $i++) {
            try {
                $ibs->addRawUser('test', 0, 'x');
            } catch (IbsUnavailableException) {
            }
        }
        $this->assertTrue($ibs->health()['breaker_open']);
        $before = count($this->fake->calls);
        try {
            $ibs->listGroups();
            $this->fail('breaker should block');
        } catch (IbsUnavailableException $e) {
            $this->assertStringContainsString('موقتاً', $e->getMessage());
        }
        $this->assertSame($before, count($this->fake->calls), 'no network call while breaker is open');
        $ibs->resetBreaker();
        $this->assertCount(3, $ibs->listGroups());
    }

    public function test_http_transport_to_closed_port_fails_quickly(): void
    {
        $t = microtime(true);
        try {
            (new HttpTransport('127.0.0.1', 1, 3, 2))->call('group.listGroups', []);
            $this->fail('expected exception');
        } catch (IbsUnavailableException) {
        }
        $this->assertLessThan(3, microtime(true) - $t);
    }

    public function test_codec_round_trip_and_fault_parsing(): void
    {
        [$m, $p] = XmlRpcCodec::decodeRequest(XmlRpcCodec::encodeRequest('user.getUserInfo', [(object) ['a' => [1, 'ب'], 'e' => new \stdClass, 'f' => 0.0009765625]]));
        $this->assertSame('user.getUserInfo', $m);
        $this->assertSame(['a' => [1, 'ب'], 'e' => [], 'f' => 0.0009765625], $p[0]);
        try {
            XmlRpcCodec::decodeResponse(XmlRpcCodec::encodeFault(1, 'USERID_DOESNT_EXISTS|User with user id 5 does not exists'));
            $this->fail('expected fault');
        } catch (IbsException $e) {
            $this->assertSame('USERID_DOESNT_EXISTS', $e->ibsCode);
        }
        $this->expectException(IbsUnavailableException::class);
        XmlRpcCodec::decodeResponse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><methodResponse/>');
    }

    public function test_not_configured_gives_clear_error(): void
    {
        Setting::query()->whereIn('key', ['ibs.host', 'ibs.password'])->delete();
        $this->app->forgetInstance(Settings::class);
        $this->app->forgetInstance(IbsConnector::class);
        $this->app->offsetUnset(Transport::class);
        $ibs = app(IbsConnector::class);
        $this->assertFalse($ibs->isConfigured());
        $this->expectException(IbsNotConfiguredException::class);
        $ibs->listGroups();
    }
}
