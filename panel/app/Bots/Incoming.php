<?php

namespace App\Bots;

/** A normalised incoming private text message (button presses arrive as text on every platform). */
final class Incoming
{
    public function __construct(
        public readonly string $updateKey,
        public readonly string $chatId,
        public readonly string $text,
        public readonly ?string $firstName = null,
        public readonly ?string $handle = null,
        public readonly ?string $messageId = null,
    ) {}
}
