<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bots')) {
            Schema::create('bots', function (Blueprint $table) {
                $table->id();
                $table->string('platform', 16)->unique();           // telegram | bale | rubika
                $table->text('token')->nullable();                   // encrypted
                $table->string('hook_secret', 64);                   // random path segment of the webhook URL
                $table->boolean('is_active')->default(false);
                $table->string('relay_mode', 16)->default('direct'); // telegram: direct | cloudflare | gas
                $table->string('relay_url', 500)->nullable();
                $table->string('bot_username', 100)->nullable();
                $table->timestamp('webhook_set_at')->nullable();
                $table->timestamp('last_update_at')->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('bot_users')) {
            Schema::create('bot_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
                $table->string('chat_id', 64);
                $table->string('first_name', 120)->nullable();
                $table->string('handle', 120)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // panel user (reseller or staff)
                $table->string('state', 40)->nullable();
                $table->json('state_data')->nullable();
                $table->unsignedSmallInteger('failed_logins')->default(0);
                $table->timestamp('login_blocked_until')->nullable();
                $table->boolean('is_blocked')->default(false);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique(['bot_id', 'chat_id']);
            });
        }
        if (! Schema::hasTable('bot_account_links')) {
            Schema::create('bot_account_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bot_user_id')->constrained('bot_users')->cascadeOnDelete();
                $table->foreignId('vpn_account_id')->constrained('vpn_accounts')->cascadeOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->unique(['bot_user_id', 'vpn_account_id']);
            });
        }
        if (! Schema::hasTable('bot_updates')) {
            Schema::create('bot_updates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
                $table->string('update_key', 100);
                $table->timestamp('created_at')->nullable();
                $table->unique(['bot_id', 'update_key']);
            });
        }
        if (! Schema::hasTable('bot_outbox')) {
            Schema::create('bot_outbox', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
                $table->string('chat_id', 64);
                $table->text('text');
                $table->string('status', 16)->default('pending');   // pending | sent | failed
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->string('error', 500)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'next_attempt_at']);
            });
        }
        if (! Schema::hasTable('bot_link_codes')) {
            Schema::create('bot_link_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('code_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['bot_link_codes', 'bot_outbox', 'bot_updates', 'bot_account_links', 'bot_users', 'bots'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
