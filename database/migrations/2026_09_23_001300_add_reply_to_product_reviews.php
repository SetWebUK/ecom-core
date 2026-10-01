<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional public reply from the shop under a product review (written in the back office: Products › Reviews).
 * The storefront may show it as "Response from {store name}" below the review text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('product_reviews', 'reply')) {
                $table->text('reply')->nullable()->after('content');
            }
            if (! Schema::hasColumn('product_reviews', 'replied_at')) {
                $table->timestamp('replied_at')->nullable()->after('reply');
            }
            $table->index(['is_approved', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropIndex(['is_approved', 'created_at']);
            $table->dropColumn(['reply', 'replied_at']);
        });
    }
};
