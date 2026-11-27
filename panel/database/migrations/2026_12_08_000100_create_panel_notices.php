<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** In-panel notifications (server / router / IBSng connection down and back up). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('panel_notices')) {
            Schema::create('panel_notices', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->index();          // e.g. router:3, ibs — one open problem per key
                $table->string('level', 10);                 // danger | success | warning | info
                $table->string('title', 200);
                $table->text('body')->nullable();
                $table->string('url', 300)->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }
        if (! Schema::hasColumn('users', 'notices_seen_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->dateTime('notices_seen_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_notices');
        if (Schema::hasColumn('users', 'notices_seen_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notices_seen_at'));
        }
    }
};
