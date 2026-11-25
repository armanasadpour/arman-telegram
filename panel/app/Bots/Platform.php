<?php

namespace App\Bots;

interface Platform
{
    /** Normalise a webhook payload; null when it is not a private text message we handle. */
    public function parse(array $payload): ?Incoming;

    /**
     * @param  list<list<string>>|null  $keyboard  rows of button labels; [] removes the keyboard; null leaves it as is
     */
    public function send(string $chatId, string $text, ?array $keyboard = null): void;

    /** @return array{username: ?string, raw: mixed} */
    public function getMe(): array;

    /** Point the platform (or the relay) at the panel. Returns the URL that was registered. */
    public function setWebhook(): string;

    /** Best effort: remove a message the user sent (e.g. one containing a password). */
    public function deleteMessage(string $chatId, string $messageId): void;
}
