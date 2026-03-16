<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// FIX: user_chapter_progress and user_module_progress were created with
// `completed` (tinyint) and `completed_at` (timestamp) columns, but the
// controllers write/read `done` (tinyint), `created_at`, and `updated_at`.
//
// This migration:
//   1. Renames `completed`    → `done`         on both tables
//   2. Renames `completed_at` → `created_at`   on both tables
//   3. Adds    `updated_at`   timestamp         on both tables
//
// Existing data is preserved — the rename keeps all rows intact.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        // ── user_chapter_progress ─────────────────────────────────────────────
        Schema::table('user_chapter_progress', function (Blueprint $table) {
            // Rename completed → done
            $table->renameColumn('completed', 'done');
        });

        Schema::table('user_chapter_progress', function (Blueprint $table) {
            // Rename completed_at → created_at
            $table->renameColumn('completed_at', 'created_at');
        });

        Schema::table('user_chapter_progress', function (Blueprint $table) {
            // Add updated_at (nullable so existing rows aren't rejected)
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        // Back-fill updated_at with created_at value for existing rows
        DB::table('user_chapter_progress')
            ->whereNull('updated_at')
            ->update(['updated_at' => DB::raw('created_at')]);

        // ── user_module_progress ──────────────────────────────────────────────
        Schema::table('user_module_progress', function (Blueprint $table) {
            $table->renameColumn('completed', 'done');
        });

        Schema::table('user_module_progress', function (Blueprint $table) {
            $table->renameColumn('completed_at', 'created_at');
        });

        Schema::table('user_module_progress', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        DB::table('user_module_progress')
            ->whereNull('updated_at')
            ->update(['updated_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // ── user_chapter_progress ─────────────────────────────────────────────
        Schema::table('user_chapter_progress', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });

        Schema::table('user_chapter_progress', function (Blueprint $table) {
            $table->renameColumn('created_at', 'completed_at');
        });

        Schema::table('user_chapter_progress', function (Blueprint $table) {
            $table->renameColumn('done', 'completed');
        });

        // ── user_module_progress ──────────────────────────────────────────────
        Schema::table('user_module_progress', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });

        Schema::table('user_module_progress', function (Blueprint $table) {
            $table->renameColumn('created_at', 'completed_at');
        });

        Schema::table('user_module_progress', function (Blueprint $table) {
            $table->renameColumn('done', 'completed');
        });
    }
};
