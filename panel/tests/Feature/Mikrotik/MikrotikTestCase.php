<?php

namespace Tests\Feature\Mikrotik;

use Tests\TestCase;

abstract class MikrotikTestCase extends TestCase
{
    use MikrotikFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMikrotik();
    }
}
