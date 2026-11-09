<?php
declare(strict_types=1);

namespace PM\Messaging;

/** The client's device list is out of date: it must refresh keys and re-encrypt. */
final class DeviceMismatch extends \RuntimeException
{
    /** @param list<string> $missing @param list<string> $stale */
    public function __construct(public readonly array $missing, public readonly array $stale)
    {
        parent::__construct('devices_mismatch');
    }
}
