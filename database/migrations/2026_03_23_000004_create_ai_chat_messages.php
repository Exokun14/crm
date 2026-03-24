<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Future-proof ai_chat_messages table
 *
 * Designed for tens of thousands of users with high chat volume.
 *
 * Key design decisions:
 *  - bigIncrements id        — safe for billions of rows
 *  - MEDIUMTEXT content      — handles long AI replies without overflow
 *  - session_id grouping     — paginate per conversation window
 *  - archived_at soft-delete — compliance, never deleted
 *  - token_count             — track Ollama usage at scale
 *  - No FK on user_id        — avoids lock contention at scale
 *  - ROW_FORMAT=DYNAMIC      — better InnoDB compression for text columns
 *
 * Covering indexes optimised for:
 *  1. Active history per user   (user_id, archived_at, created_at)
 *  2. Per-session load          (session_id, created_at)
 *  3. Audit date range queries  (archived_at, created_at)
 *  4. All messages per user     (user_id, created_at)
 *  5. Role filter per user      (user_id, role, created_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->bigIncrements('id');

            // integer — matches users.id type. No FK to avoid lock contention at scale.
            $table->integer('user_id');

            // Groups messages into a conversation session (UUID from frontend).
            // Enables per-session pagination and grouped history display.
            $table->string('session_id', 36)->nullable();

            $table->enum('role', ['user', 'assistant']);

            // MEDIUMTEXT: up to 16MB — future-safe for long AI responses
            $table->mediumText('content');

            // Optional: track tokens per message for usage monitoring
            $table->unsignedSmallInteger('token_count')->nullable();

            // NULL   = active (visible in UI)
            // SET    = archived (user cleared chat — kept forever for compliance)
            $table->timestamp('archived_at')->nullable()->default(null);

            $table->timestamps();
        });

        // ── Covering indexes ──────────────────────────────────────────────────

        // 1. Primary query: active history for user ordered by time
        DB::statement('CREATE INDEX aim_user_active_idx ON ai_chat_messages (user_id, archived_at, created_at)');

        // 2. Load a specific session window
        DB::statement('CREATE INDEX aim_session_time_idx ON ai_chat_messages (session_id, created_at)');

        // 3. Compliance audit: archived messages in date range
        DB::statement('CREATE INDEX aim_archived_date_idx ON ai_chat_messages (archived_at, created_at)');

        // 4. Admin: all messages for a user across all time
        DB::statement('CREATE INDEX aim_user_time_idx ON ai_chat_messages (user_id, created_at)');

        // 5. Role filter per user (e.g. only assistant replies)
        DB::statement('CREATE INDEX aim_user_role_idx ON ai_chat_messages (user_id, role, created_at)');

        // ── Storage optimisation ──────────────────────────────────────────────
        // DYNAMIC row format gives better compression for MEDIUMTEXT at scale
        DB::statement('ALTER TABLE ai_chat_messages ROW_FORMAT=DYNAMIC');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
