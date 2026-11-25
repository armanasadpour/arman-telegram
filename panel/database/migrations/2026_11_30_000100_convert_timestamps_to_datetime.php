<?php

use App\Support\DatetimeColumns;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DatetimeColumns::convert();
    }

    public function down(): void
    {
        // DATETIME is strictly safer; nothing to undo.
    }
};
