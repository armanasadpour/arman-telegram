<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MikroTik accounting (L2TP + WireGuard). The panel is the source of truth for limits (days, volume,
 * multi-login); the router only holds the PPP secret / WireGuard peer, enabled or disabled by the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mt_groups')) {
            Schema::create('mt_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
                $table->string('protocol', 12);                        // l2tp | wireguard
                $table->string('name', 80);
                $table->string('ppp_profile', 100)->nullable();        // l2tp: PPP profile on the router
                $table->string('wg_interface', 100)->nullable();       // wireguard: interface on the router
                $table->string('wg_network', 50)->nullable();          // e.g. 10.20.0.0/22 (from the interface address)
                $table->string('wg_server_ip', 50)->nullable();        // router's own address in that network
                $table->string('wg_server_public_key', 60)->nullable();
                $table->unsignedInteger('wg_listen_port')->nullable();
                $table->string('wg_allowed_ips', 200)->default('0.0.0.0/0, ::/0');
                $table->unsignedSmallInteger('wg_mtu')->nullable();
                $table->unsignedSmallInteger('wg_keepalive')->default(25);
                $table->string('endpoint_host', 255)->nullable();      // address customers connect to
                $table->string('dns', 200)->nullable();
                $table->string('rate_limit', 60)->nullable();          // wireguard: simple queue "up/down", e.g. 10M/20M
                $table->text('ipsec_psk')->nullable();                 // l2tp: shown to customers (encrypted)
                $table->unsignedSmallInteger('multi_login')->nullable();
                $table->unsignedInteger('default_days')->nullable();
                $table->decimal('default_volume_gb', 10, 2)->nullable();
                $table->boolean('start_on_first_use')->default(true);
                $table->boolean('is_active')->default(true);
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->unique(['router_id', 'protocol', 'name']);
            });
        }

        if (! Schema::hasTable('mt_accounts')) {
            Schema::create('mt_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('router_id')->constrained('routers');
                $table->foreignId('mt_group_id')->constrained('mt_groups');
                $table->string('protocol', 12);
                $table->string('username', 64);
                $table->string('create_key', 64)->unique();             // idempotency (job/sale/order key)
                $table->string('ros_id', 32)->nullable();               // .id of the secret / peer on the router
                $table->text('password')->nullable();                   // l2tp (encrypted)
                $table->text('wg_private_key')->nullable();             // encrypted
                $table->text('wg_preshared_key')->nullable();           // encrypted
                $table->string('wg_public_key', 60)->nullable();
                $table->string('wg_address', 50)->nullable();           // 10.20.0.7 (assigned)
                $table->string('status', 12)->default('pending');       // pending|active|disabled|expired|depleted|failed
                $table->boolean('router_disabled')->default(false);     // what the panel last set on the router
                $table->unsignedInteger('duration_days')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->unsignedBigInteger('volume_limit_bytes')->nullable();
                $table->unsignedBigInteger('used_bytes')->default(0);
                $table->unsignedBigInteger('upload_bytes')->default(0);
                $table->unsignedBigInteger('download_bytes')->default(0);
                $table->unsignedSmallInteger('multi_login')->nullable();
                $table->dateTime('first_used_at')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->boolean('is_online')->default(false);
                $table->unsignedSmallInteger('online_count')->default(0);
                $table->string('online_ip', 60)->nullable();
                $table->json('counters')->nullable();                   // last raw counters per session/peer
                $table->string('comment', 150)->nullable();
                $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
                $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('last_synced_at')->nullable();
                $table->string('error', 500)->nullable();
                $table->dateTime('alerted_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['router_id', 'protocol', 'username']);
                $table->unique(['router_id', 'wg_address']);
                $table->index(['status', 'expires_at']);
                $table->index('reseller_id');
            });
        }

        if (! Schema::hasTable('mt_usage_daily')) {
            Schema::create('mt_usage_daily', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mt_account_id')->constrained('mt_accounts')->cascadeOnDelete();
                $table->date('day');
                $table->unsignedBigInteger('upload_bytes')->default(0);
                $table->unsignedBigInteger('download_bytes')->default(0);
                $table->unique(['mt_account_id', 'day']);
            });
        }

        if (! Schema::hasTable('bot_mt_links')) {
            Schema::create('bot_mt_links', function (Blueprint $table) {
                $table->foreignId('bot_user_id')->constrained('bot_users')->cascadeOnDelete();
                $table->foreignId('mt_account_id')->constrained('mt_accounts')->cascadeOnDelete();
                $table->dateTime('created_at')->nullable();
                $table->primary(['bot_user_id', 'mt_account_id']);
            });
        }

        Schema::table('routers', function (Blueprint $table) {
            if (! Schema::hasColumn('routers', 'acct_state')) {
                $table->json('acct_state')->nullable();                 // uptime at last accounting (reboot detection)
                $table->dateTime('acct_at')->nullable();
            }
        });
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'protocol')) {
                $table->string('protocol', 12)->default('openvpn');     // openvpn | mikrotik
                $table->foreignId('mt_group_id')->nullable()->constrained('mt_groups')->nullOnDelete();
            }
        });
        Schema::table('reseller_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('reseller_sales', 'mt_account_id')) {
                $table->foreignId('mt_account_id')->nullable()->constrained('mt_accounts')->nullOnDelete();
            }
        });
        Schema::table('customer_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_orders', 'mt_account_id')) {
                $table->foreignId('mt_account_id')->nullable()->constrained('mt_accounts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mt_account_id');
        });
        Schema::table('reseller_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mt_account_id');
        });
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mt_group_id');
            $table->dropColumn('protocol');
        });
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['acct_state', 'acct_at']);
        });
        Schema::dropIfExists('bot_mt_links');
        Schema::dropIfExists('mt_usage_daily');
        Schema::dropIfExists('mt_accounts');
        Schema::dropIfExists('mt_groups');
    }
};
