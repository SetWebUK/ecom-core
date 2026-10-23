<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat (feature "live_chat"): one row per storefront conversation, one per message in it (customer, assistant,
 * staff, notices, and the assistant's hidden tool calls/results).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();          // the visitor's secret handle (browser storage)
            $table->string('status', 16)->default('ai');     // ai | waiting | human | closed
            $table->foreignId('user_id')->nullable()->index(); // signed-in customer
            $table->foreignId('staff_id')->nullable();       // staff member who replied last
            $table->string('name', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('page_url', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('ai_replies')->default(0);
            $table->timestamp('handoff_at')->nullable();      // customer or assistant asked for a person
            $table->timestamp('staff_notified_at')->nullable();
            $table->timestamp('email_requested_at')->nullable(); // nobody answered: customer asked for an email address
            $table->foreignId('form_submission_id')->nullable(); // details left → Inbox › Form submissions
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('staff_read_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('role', 16);                      // customer | assistant | staff | notice | tool
            $table->text('body')->nullable();
            $table->json('meta')->nullable();                // assistant: tool_calls, provider, usage; tool: results
            $table->foreignId('user_id')->nullable();        // staff author
            $table->timestamp('created_at')->nullable();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
