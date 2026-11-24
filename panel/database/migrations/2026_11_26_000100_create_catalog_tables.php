<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('package_categories')) {
            Schema::create('package_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('description', 255)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('packages')) {
            Schema::create('packages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->nullable()->constrained('package_categories')->nullOnDelete();
                $table->string('name', 120);
                $table->string('type', 20)->index();                 // new | renewal | topup
                $table->decimal('volume_gb', 10, 2)->nullable();     // null = no volume (unlimited / not applicable)
                $table->unsignedSmallInteger('duration_days')->nullable();
                $table->unsignedSmallInteger('multi_login')->nullable(); // null = IBSng group default
                $table->string('ibs_group_name', 100)->nullable();   // required for "new" packages
                $table->unsignedBigInteger('price_rial');
                $table->string('description', 500)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();                                // keep history for old orders
            });
        }

        if (! Schema::hasTable('discounts')) {
            Schema::create('discounts', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('scope', 20)->index();                // all | category | package
                $table->foreignId('category_id')->nullable()->constrained('package_categories')->cascadeOnDelete();
                $table->foreignId('package_id')->nullable()->constrained('packages')->cascadeOnDelete();
                $table->string('kind', 20);                          // percent | amount
                $table->unsignedBigInteger('value');                 // percent (1..100) or rial amount
                $table->smallInteger('priority')->default(0);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('package_categories');
    }
};
