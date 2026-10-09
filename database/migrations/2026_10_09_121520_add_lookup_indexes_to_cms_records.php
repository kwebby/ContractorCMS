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
    private array $fields = ['email', 'customer_id', 'user_id', 'invoice_id', 'conversation_id', 'status', 'type', 'employee_user_id'];

    public function up(): void
    {
        Schema::table('cms_records', function (Blueprint $table) {
            foreach ($this->fields as $field) {
                $column = $table->string('lookup_'.$field, 254)->nullable();
                if (DB::getDriverName() === 'mysql') {
                    $column->collation('utf8mb4_bin');
                }
                $table->index(['collection', 'lookup_'.$field], 'cms_lookup_'.$field);
            }
        });
        DB::table('cms_records')->orderBy('collection')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode($row->data, true);
                $lookups = [];
                foreach ($this->fields as $field) {
                    $value = $data[$field] ?? null;
                    $lookups['lookup_'.$field] = is_string($value) ? mb_substr($value, 0, 254) : null;
                }
                DB::table('cms_records')->where('collection', $row->collection)->where('id', $row->id)->update($lookups);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cms_records', function (Blueprint $table) {
            foreach ($this->fields as $field) {
                $table->dropIndex('cms_lookup_'.$field);
                $table->dropColumn('lookup_'.$field);
            }
        });
    }
};
