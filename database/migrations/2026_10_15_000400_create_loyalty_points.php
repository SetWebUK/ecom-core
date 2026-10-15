<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty points (feature "loyalty", Services\Loyalty\Loyalty): a ledger, one row per change, so a balance is always
 * the sum of its rows and every change says why (signup bonus, earned on an order, spent at checkout, given back,
 * reversed, adjusted by staff, imported from the old shop). carts.loyalty_points = points the customer chose to spend.
 * Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_points')) {
            Schema::create('loyalty_points', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->integer('points');                      // + earned / given, − spent / taken back
                $table->string('type', 20);                     // signup | earn | redeem | refund | reverse | adjust | import
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable(); // staff member for "adjust"
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
                $table->unique(['order_id', 'type']);           // each order earns / spends / is reversed once
            });
        }
        Schema::table('carts', function (Blueprint $table) {
            if (! Schema::hasColumn('carts', 'loyalty_points')) {
                $table->unsignedInteger('loyalty_points')->nullable()->after('coupon_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('loyalty_points');
        });
        Schema::dropIfExists('loyalty_points');
    }
};
