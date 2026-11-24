<?php

namespace App\Ibs;

use RuntimeException;

/** IBSng answered with an application error ("CODE|message" fault). */
class IbsException extends RuntimeException
{
    public function __construct(public readonly string $ibsCode, string $message, public readonly string $raw = '')
    {
        parent::__construct($message);
    }
}
