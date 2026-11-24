<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cache of IBSng charges (tariffs). cpk = credit per kilobyte; > 0 means volume-limited.
        Schema::create('ibs_charges', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->decimal('cpk', 20, 12)->default(0);
            $table->decimal('cpm', 20, 12)->default(0);
            $table->json('rules')->nullable();
            $table->boolean('present')->default(true);   // false when removed from IBSng
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // Cache of IBSng groups and their effective attributes.
        Schema::create('ibs_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedBigInteger('ibs_group_id')->nullable();
            $table->unsignedBigInteger('rel_exp_seconds')->nullable(); // validity counted from first login
            $table->unsignedSmallInteger('multi_login')->nullable();
            $table->string('charge_name', 100)->nullable();
            $table->string('comment', 255)->nullable();
            $table->json('raw_attrs')->nullable();
            $table->boolean('present')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // Local mirror of IBSng accounts. IBSng stays the source of truth; every field here
        // is a cached copy with last_synced_at, except panel-only data (volume_total_bytes, notes).
        Schema::create('vpn_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ibs_user_id')->unique();
            $table->string('username', 64)->unique();
            $table->string('group_name', 100)->nullable()->index();
            $table->string('owner_name', 100)->nullable();
            $table->decimal('credit', 24, 6)->default(0);
            $table->unsignedBigInteger('rel_exp_seconds')->nullable();   // user-level override, else group's
            $table->unsignedSmallInteger('multi_login')->nullable();     // user-level override, else group's
            $table->string('first_login_ibs', 40)->nullable();          // as reported by IBSng (its own clock)
            $table->timestamp('expires_at')->nullable()->index();       // real time = sync time + time_to_nearest_exp
            $table->boolean('locked')->default(false)->index();
            $table->string('lock_reason', 255)->nullable();
            $table->string('comment', 255)->nullable();
            $table->boolean('is_online')->default(false)->index();
            $table->unsignedSmallInteger('online_sessions')->default(0);
            $table->json('sessions')->nullable();                       // live session snapshot (ras, bytes, since)
            $table->unsignedBigInteger('volume_total_bytes')->nullable(); // known only for volume sold through the panel
            $table->string('ibs_created_at', 40)->nullable();
            $table->string('source', 20)->default('panel');             // panel | imported
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->string('sync_error', 255)->nullable();
            $table->timestamp('ibs_deleted_at')->nullable();            // deleted in IBSng
            $table->timestamps();
            $table->softDeletes();
        });

        // Two-step account creation (addNewUsers -> updateUserAttrs) tracked as an idempotent saga.
        Schema::create('provisioning_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_key')->unique();
            $table->string('type', 30)->default('create');
            $table->string('username', 64)->index();
            $table->string('status', 30)->index(); // pending|raw_created|completed|compensated|needs_review|failed
            $table->unsignedBigInteger('ibs_user_id')->nullable();
            $table->json('spec');                  // non-secret request (group, days, volume, comment)
            $table->text('password_encrypted')->nullable(); // kept only until completion, then wiped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('vpn_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_jobs');
        Schema::dropIfExists('vpn_accounts');
        Schema::dropIfExists('ibs_groups');
        Schema::dropIfExists('ibs_charges');
    }
};
