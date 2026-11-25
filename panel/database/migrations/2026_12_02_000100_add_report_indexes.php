<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Indexes for dashboards, reports and alerts on large installs. */
return new class extends Migration
{
    private const INDEXES = [
        'reseller_sales' => [['status', 'created_at'], ['reseller_id', 'created_at']],
        'customer_orders' => [['status', 'updated_at']],
        'payments' => [['status', 'paid_at'], ['created_at']],
        'ledger_entries' => [['created_at']],
        'vpn_accounts' => [['expires_at'], ['is_online'], ['source', 'created_at']],
        'audit_logs' => [['action', 'created_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $cols) {
                $name = $table.'_'.implode('_', $cols).'_rpt';
                if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $cols) {
                $name = $table.'_'.implode('_', $cols).'_rpt';
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
