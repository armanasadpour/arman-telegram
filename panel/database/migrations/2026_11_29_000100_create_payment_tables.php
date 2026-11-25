<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('method', 16);                       // zarinpal|sep|mellat|sadad|card|usdt
                $table->foreignId('wallet_id')->constrained('wallets');
                $table->unsignedBigInteger('amount_rial');
                $table->string('status', 16)->default('pending');   // pending|redirected|review|paid|failed|rejected|expired
                $table->string('purpose', 16)->default('topup');    // topup|order
                $table->string('gateway_token', 191)->nullable();   // authority / token / RefId
                $table->string('gateway_ref', 100)->nullable();     // bank reference (ref_id, RRN, SaleReferenceId)
                $table->string('card_pan', 32)->nullable();         // masked
                $table->string('usdt_network', 8)->nullable();
                $table->decimal('usdt_amount', 20, 6)->nullable();
                $table->string('tx_hash', 100)->nullable()->unique();
                $table->string('payer_note', 300)->nullable();      // card-to-card: tracking no., last digits
                $table->string('receipt_path', 300)->nullable();
                $table->string('error', 500)->nullable();
                $table->json('meta')->nullable();
                $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('review_reason', 300)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('bot_user_id')->nullable()->constrained('bot_users')->nullOnDelete();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->unique(['method', 'gateway_ref']);
                $table->index(['status', 'method']);
            });
        }
        if (! Schema::hasTable('customer_orders')) {
            Schema::create('customer_orders', function (Blueprint $table) {
                $table->id();
                $table->uuid('order_key')->unique();
                $table->foreignId('bot_user_id')->constrained('bot_users');
                $table->foreignId('package_id')->constrained('packages');
                $table->string('type', 16);
                $table->json('price_snapshot');
                $table->unsignedBigInteger('price_rial');
                $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->foreignId('vpn_account_id')->nullable()->constrained('vpn_accounts')->nullOnDelete();
                $table->foreignId('provisioning_job_id')->nullable()->constrained('provisioning_jobs')->nullOnDelete();
                $table->foreignId('debit_entry_id')->nullable()->constrained('ledger_entries');
                $table->foreignId('refund_entry_id')->nullable()->constrained('ledger_entries');
                $table->string('status', 20)->default('awaiting_payment'); // awaiting_payment|pending|completed|refunded|needs_review|cancelled
                $table->text('secret')->nullable();                 // encrypted password until delivered to the customer
                $table->string('error', 500)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('bot_outbox', 'sensitive')) {
            Schema::table('bot_outbox', function (Blueprint $table) {
                $table->boolean('sensitive')->default(false);   // text is wiped once sent (credentials)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bot_outbox', 'sensitive')) {
            Schema::table('bot_outbox', fn (Blueprint $t) => $t->dropColumn('sensitive'));
        }
        Schema::dropIfExists('customer_orders');
        Schema::dropIfExists('payments');
    }
};
