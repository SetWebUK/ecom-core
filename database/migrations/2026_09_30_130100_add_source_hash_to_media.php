<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product CSV import (1.1): images downloaded from a URL remember it (sha1 of the URL), so importing the same file
 * again reuses the media-library image instead of downloading a second copy. Additive + nullable: existing rows stay
 * as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media', 'source_hash')) {
            Schema::table('media', function (Blueprint $table) {
                $table->string('source_hash', 40)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('media', 'source_hash')) {
            Schema::table('media', function (Blueprint $table) {
                $table->dropIndex(['source_hash']);
                $table->dropColumn('source_hash');
            });
        }
    }
};
