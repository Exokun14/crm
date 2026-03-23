<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat Messages — Production-Ready Schema
 *
 * Key design decisions:
 *
 * 1. session_id (varchar 64) groups messages into discrete conversations.
 *    Without it, all of a user's messages are one flat unordered list —
 *    impossible to render separate chat threads. Generate a UUID per session
 *    on the app side (e.g. Str::uuid()) and pass it with every message.
 *
 * 2. user_id is integer (not bigint) to match CRM's users.id type.
 *    Using bigint here would cause implicit casting on every FK join.
 *
 * 3. role enum('user','assistant') — the only two valid senders. Keeping
 *    this as an enum prevents garbage values and keeps the column indexed
 *    efficiently. If you add tool/system roles later, add them to the enum.
 *
 * 4. content is text (not varchar) — AI responses can be long.
 *
 * 5. Index on (user_id, session_id) — the most common query pattern is
 *    "fetch all messages for this user in this session", ordered by created_at.
 *
 * 6. created_at defaults to CURRENT_TIMESTAMP at the DB level so messages
 *    are always timestamped even if the app forgets to set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();

            // FK to CRM users — integer matches users.id type in CRM
            $table->integer('user_id');

            // Groups messages into discrete conversation threads
            $table->string('session_id', 64);

            // Who sent this message
            $table->enum('role', ['user', 'assistant']);

            // Message body — text to handle long AI responses
            $table->text('content');

            // DB-level defaults so timestamps are always set
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // FK — cascade delete so messages are cleaned up when user is removed
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            // Primary query pattern: all messages for a user in a session
            $table->index(['user_id', 'session_id'], 'chat_messages_user_session_index');

            // Secondary: fetch all sessions for a user (e.g. sidebar list)
            $table->index('session_id', 'chat_messages_session_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
