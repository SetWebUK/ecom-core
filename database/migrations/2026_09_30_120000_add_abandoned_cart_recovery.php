<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abandoned-cart recovery emails (Pine\Commerce\Services\Recovery\AbandonedCartRecovery):
 *  - carts: why the reminder sequence stopped, and the order a reminder won back (all nullable – existing rows are
 *    unchanged);
 *  - cart_recovery_emails: one row per reminder sent (step, address, the single-use coupon, first click / clicks);
 *  - email_unsubscribes: addresses that asked for no more emails of a kind ("list" = abandoned_cart).
 * Additive only: new nullable columns and new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            if (! Schema::hasColumn('carts', 'recovery_stopped_at')) {
                $table->timestamp('recovery_stopped_at')->nullable()->after('abandoned_email_sent_at');
            }
            if (! Schema::hasColumn('carts', 'recovery_stop_reason')) {
                $table->string('recovery_stop_reason', 40)->nullable()->after('recovery_stopped_at');
            }
            if (! Schema::hasColumn('carts', 'recovered_order_id')) {
                $table->unsignedBigInteger('recovered_order_id')->nullable()->index()->after('recovery_stop_reason');
            }
            if (! Schema::hasColumn('carts', 'recovered_at')) {
                $table->timestamp('recovered_at')->nullable()->index()->after('recovered_order_id');
            }
        });

        if (! Schema::hasTable('cart_recovery_emails')) {
            Schema::create('cart_recovery_emails', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('step');
                $table->string('email');
                $table->string('subject')->nullable();
                $table->string('coupon_code')->nullable();
                $table->timestamp('sent_at')->nullable()->index();
                $table->timestamp('clicked_at')->nullable();
                $table->unsignedInteger('clicks')->default(0);
                $table->timestamps();
                $table->unique(['cart_id', 'step']);
                $table->index('email');
            });
        }

        if (! Schema::hasTable('email_unsubscribes')) {
            Schema::create('email_unsubscribes', function (Blueprint $table) {
                $table->id();
                $table->string('email');
                $table->string('list', 40);
                $table->string('source', 40)->nullable();
                $table->timestamps();
                $table->unique(['email', 'list']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_unsubscribes');
        Schema::dropIfExists('cart_recovery_emails');
        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex(['recovered_order_id']);
            $table->dropIndex(['recovered_at']);
            $table->dropColumn(['recovery_stopped_at', 'recovery_stop_reason', 'recovered_order_id', 'recovered_at']);
        });
    }
};
