<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which IPs each account connected from (all three protocols), seen by the panel's online/accounting sync. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('connection_logs')) {
            Schema::create('connection_logs', function (Blueprint $table) {
                $table->id();
                $table->string('protocol', 12);
                $table->string('account_key', 24);          // v:<vpn_account_id> | m:<mt_account_id>
                $table->string('ip', 64);
                $table->unsignedSmallInteger('device')->nullable();
                $table->unsignedInteger('connections')->default(1);
                $table->dateTime('first_seen_at');
                $table->dateTime('last_seen_at');
                $table->unique(['account_key', 'ip']);
                $table->index('last_seen_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_logs');
    }
};
