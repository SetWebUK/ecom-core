<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin › Updates (1.3): audit log of every update check, approved core update and skeleton file update – who did
 * it, from/to versions, files applied, status, timestamps and the full log. Additive: a new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_updates')) {
            return;
        }
        Schema::create('platform_updates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();             // check | core | skeleton
            $table->string('status', 20)->index();           // checked | approved | running | succeeded | failed | planned | applied
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_email')->nullable();       // who checked / approved (kept when the account is deleted)
            $table->string('via', 20)->nullable();          // admin | cli | schedule
            $table->string('from_version', 64)->nullable();
            $table->string('to_version', 64)->nullable();
            $table->string('step', 40)->nullable();         // current / failing step of a core update
            $table->json('result')->nullable();             // check result, skeleton plan
            $table->json('files')->nullable();              // skeleton files applied
            $table->json('meta')->nullable();               // backup path, maintenance secret, pid, doctor counts …
            $table->longText('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_updates');
    }
};
