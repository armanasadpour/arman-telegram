<?php

namespace App\Bots;

use App\Models\Bot;

class Platforms
{
    public function for(Bot $bot): Platform
    {
        return $bot->platform === 'rubika' ? new Rubika($bot) : new TelegramLike($bot);
    }
}
