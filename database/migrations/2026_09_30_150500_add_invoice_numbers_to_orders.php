<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequential invoice numbers (v1.1, Pine\Commerce\Services\Invoices\InvoiceNumbers):
 *  - orders.invoice_number / orders.invoice_date – set once when the order is paid (or completed, per the setting),
 *    never changed or reused afterwards. Nullable, so every existing order stays valid (no number until it is issued).
 *  - sequences – named counters; the "invoice" row holds the last number issued and is locked while a number is taken.
 * Purely additive: no existing column or row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'invoice_number')) {
                $table->string('invoice_number', 60)->nullable()->unique()->after('number');
            }
            if (! Schema::hasColumn('orders', 'invoice_date')) {
                $table->timestamp('invoice_date')->nullable()->after('invoice_number');
            }
        });

        if (! Schema::hasTable('sequences')) {
            Schema::create('sequences', function (Blueprint $table) {
                $table->string('name', 60)->primary();
                $table->unsignedBigInteger('value')->default(0); // last value issued
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['invoice_number']);
            $table->dropColumn(['invoice_number', 'invoice_date']);
        });
        Schema::dropIfExists('sequences');
    }
};
