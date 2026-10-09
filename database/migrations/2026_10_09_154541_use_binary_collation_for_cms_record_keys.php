<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BINARY_COLLATION = 'utf8mb4_bin';

    private array $lookups = ['email', 'customer_id', 'user_id', 'invoice_id', 'conversation_id', 'status', 'type', 'employee_user_id'];

    /**
     * Record identifiers are compared case-sensitively on PostgreSQL and SQLite, so MySQL and MariaDB
     * must not treat "Abc" and "abc" as the same key.
     */
    public function up(): void
    {
        $this->applyCollation(self::BINARY_COLLATION);
    }

    public function down(): void
    {
        $this->applyCollation(DB::connection()->getConfig('collation') ?? 'utf8mb4_unicode_ci');
    }

    private function applyCollation(string $collation): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }
        Schema::table('cms_records', function (Blueprint $table) use ($collation, $driver): void {
            $table->string('collection', 64)->charset('utf8mb4')->collation($collation)->change();
            $table->string('id', 128)->charset('utf8mb4')->collation($collation)->change();
            if ($driver === 'mariadb') {
                foreach ($this->lookups as $field) {
                    $table->string('lookup_'.$field, 254)->nullable()->charset('utf8mb4')->collation($collation)->change();
                }
            }
        });
        Schema::table('cms_locks', function (Blueprint $table) use ($collation): void {
            $table->string('key', 64)->charset('utf8mb4')->collation($collation)->change();
        });
    }
};
