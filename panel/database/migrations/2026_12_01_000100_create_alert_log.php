<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('alert_log')) {
            Schema::create('alert_log', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 32);
                $table->unsignedBigInteger('ref');          // account / reseller id (0 for system-wide)
                $table->string('marker', 64);               // e.g. expiry date + threshold, report date
                $table->dateTime('created_at')->nullable();
                $table->unique(['kind', 'ref', 'marker']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_log');
    }
};
