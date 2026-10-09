<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_records', function (Blueprint $table): void {
            $table->string('collection', 64);
            $table->string('id', 128);
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->string('created_at', 32);
            $table->string('updated_at', 32);
            $table->primary(['collection', 'id']);
            $table->index(['collection', 'created_at']);
        });
        Schema::create('cms_locks', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_records');
        Schema::dropIfExists('cms_locks');
    }
};
