<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resellers')) {
            Schema::create('resellers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); // login account
                $table->string('code', 20)->unique();                                     // public id, e.g. R1001
                $table->string('name', 120);
                $table->string('phone', 20)->nullable();
                $table->string('telegram', 64)->nullable();
                $table->foreignId('parent_id')->nullable()->constrained('resellers')->nullOnDelete(); // future: sub-resellers (disabled)
                $table->decimal('global_discount_percent', 5, 2)->default(0);
                $table->string('status', 20)->default('active');                           // active | suspended
                $table->string('notes', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->id();
                $table->string('owner_type', 30);                // reseller (customer wallets in phase 6)
                $table->unsignedBigInteger('owner_id');
                $table->bigInteger('balance')->default(0);       // cache of SUM(ledger_entries.amount); rial
                $table->timestamps();
                $table->unique(['owner_type', 'owner_id']);
            });
        }

        // Append-only. A correction is a new reversing row, never an edit.
        if (! Schema::hasTable('ledger_entries')) {
            Schema::create('ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
                $table->string('type', 30)->index();             // manual_credit|manual_debit|topup|purchase|refund|reversal
                $table->bigInteger('amount');                    // + credit / - debit (rial)
                $table->bigInteger('balance_after');
                $table->string('idempotency_key', 120)->unique();
                $table->string('reference_type', 40)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('description', 255)->nullable();
                $table->string('reason', 500)->nullable();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reverses_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
                $table->timestamp('created_at')->index();
                $table->index(['reference_type', 'reference_id']);
            });
        }

        if (! Schema::hasTable('reseller_prices')) {
            Schema::create('reseller_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
                $table->foreignId('package_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('price_rial');
                $table->timestamps();
                $table->unique(['reseller_id', 'package_id']);
            });
        }

        if (! Schema::hasTable('reseller_category_discounts')) {
            Schema::create('reseller_category_discounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('package_categories')->cascadeOnDelete();
                $table->decimal('percent', 5, 2);
                $table->timestamps();
                $table->unique(['reseller_id', 'category_id']);
            });
        }

        if (! Schema::hasTable('reseller_sales')) {
            Schema::create('reseller_sales', function (Blueprint $table) {
                $table->id();
                $table->uuid('sale_key')->unique();               // idempotency key from the sell form
                $table->foreignId('reseller_id')->constrained()->restrictOnDelete();
                $table->foreignId('package_id')->constrained()->restrictOnDelete();
                $table->string('type', 20);                       // new | renewal | topup
                $table->json('price_snapshot');
                $table->unsignedBigInteger('cost_rial');          // what the reseller paid
                $table->unsignedBigInteger('sale_price_rial');    // what the reseller charges the customer
                $table->bigInteger('profit_rial');
                $table->string('customer_name', 120)->nullable();
                $table->string('customer_phone', 20)->nullable();
                $table->foreignId('vpn_account_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('provisioning_job_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('debit_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
                $table->foreignId('refund_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
                $table->string('status', 20)->index();            // pending|completed|refunded|needs_review|failed
                $table->string('error', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('vpn_accounts', 'reseller_id')) {
            Schema::table('vpn_accounts', function (Blueprint $table) {
                $table->foreignId('reseller_id')->nullable()->after('source')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vpn_accounts', 'reseller_id')) {
            Schema::table('vpn_accounts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reseller_id');
            });
        }
        Schema::dropIfExists('reseller_sales');
        Schema::dropIfExists('reseller_category_discounts');
        Schema::dropIfExists('reseller_prices');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('resellers');
    }
};
