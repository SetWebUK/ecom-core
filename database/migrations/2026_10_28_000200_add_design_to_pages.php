<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages designed in the visual builder (Admin › Pages › Design page, Pine\Commerce\Content\PageDesign):
 *  - pages.design       the builder's project data (components + styles) it reopens with
 *  - pages.design_html  the page body it produced (cleaned), shown instead of pages.content
 *  - pages.design_css   its styles, scoped to .pb-page
 * Additive: existing pages have no design and keep showing their content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'design')) {
                $table->longText('design')->nullable()->after('content');
                $table->longText('design_html')->nullable()->after('design');
                $table->longText('design_css')->nullable()->after('design_html');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['design', 'design_html', 'design_css']);
        });
    }
};
