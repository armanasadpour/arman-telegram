<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Permanent username prefix for every account a reseller sells (set once, never changed). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resellers', 'account_prefix')) {
            Schema::table('resellers', function (Blueprint $t) {
                $t->string('account_prefix', 12)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('resellers', 'account_prefix')) {
            Schema::table('resellers', function (Blueprint $t) {
                $t->dropUnique(['account_prefix']);
                $t->dropColumn('account_prefix');
            });
        }
    }
};
