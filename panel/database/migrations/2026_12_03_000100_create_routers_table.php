<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routers')) {
            Schema::create('routers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('host', 255);
                $table->string('transport', 10)->default('rest');   // rest | api-ssl | api
                $table->unsignedInteger('port')->default(443);
                $table->string('username', 100);
                $table->text('password')->nullable();               // encrypted
                $table->string('cert_pin', 80)->nullable();          // sha256//base64 (rest, api-ssl)
                $table->boolean('is_active')->default(true);
                $table->boolean('l2tp_enabled')->default(false);
                $table->boolean('wireguard_enabled')->default(false);
                $table->json('info')->nullable();                    // last identity/version/resources snapshot
                $table->json('inventory')->nullable();               // last readiness check
                $table->dateTime('last_ok_at')->nullable();
                $table->dateTime('last_error_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->unsignedInteger('last_latency_ms')->nullable();
                $table->string('health', 10)->nullable();            // up | down (for transition alerts)
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
