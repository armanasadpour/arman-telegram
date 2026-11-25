<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WireGuard accounts with more than one device: device 1 lives on mt_accounts, devices 2..N here (own key, IP and peer). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mt_devices')) {
            Schema::create('mt_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mt_account_id')->constrained('mt_accounts')->cascadeOnDelete();
                $table->foreignId('router_id')->constrained('routers');
                $table->unsignedSmallInteger('idx');
                $table->string('ros_id', 32)->nullable();
                $table->text('wg_private_key')->nullable();
                $table->text('wg_preshared_key')->nullable();
                $table->string('wg_public_key', 60);
                $table->string('wg_address', 50)->nullable();
                $table->json('counters')->nullable();
                $table->boolean('is_online')->default(false);
                $table->string('online_ip', 60)->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique(['mt_account_id', 'idx']);
                $table->unique(['router_id', 'wg_address']);
                $table->unique('wg_public_key');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mt_devices');
    }
};
