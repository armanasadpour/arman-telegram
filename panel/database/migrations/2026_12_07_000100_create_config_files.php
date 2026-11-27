<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Client config files (e.g. .ovpn) kept in the panel, each with a secret direct-download link. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('config_files')) {
            Schema::create('config_files', function (Blueprint $table) {
                $table->id();
                $table->string('name', 64);                  // shown in the panel and used as the file name
                $table->string('protocol', 12)->default('openvpn');
                $table->string('extension', 12)->default('ovpn');
                $table->longText('content');                 // encrypted (contains the client private key)
                $table->string('token', 64)->unique();       // public link /f/<token>
                $table->unsignedInteger('downloads')->default(0);
                $table->dateTime('last_download_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('config_files');
    }
};
