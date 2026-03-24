<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch Migration — user_course_progress & related tables
 *
 * Fully idempotent — safe to run multiple times.
 *
 * Adds missing columns to user_course_progress:
 *   - enrolled       boolean  (derived from progress > 0, kept for explicit tracking)
 *   - completed      date     (date the course was completed)
 *   - time_spent     int      (cumulative minutes spent)
 *   - status         varchar  (Not Started | In Progress | Completed)
 *   - assessment_score int    (best assessment score)
 *
 * Also adds indexes optimised for:
 *   - Per-user course list queries   (user_id, status)
 *   - Per-course progress reporting  (course_id, status)
 *   - Completion leaderboards        (completed, progress)
 *   - Admin dashboards               (status)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── user_course_progress — add missing columns ────────────────────────
        Schema::table('user_course_progress', function (Blueprint $table) {

            if (!Schema::hasColumn('user_course_progress', 'enrolled')) {
                // Explicit enrolment flag — set to true the moment a user
                // starts or is assigned a course (separate from progress %).
                $table->boolean('enrolled')->default(false)->after('course_id');
            }

            if (!Schema::hasColumn('user_course_progress', 'status')) {
                // Denormalised status string — kept in sync by the controller
                // so admin dashboards can filter without computing from %.
                $table->string('status', 20)->default('Not Started')->after('progress');
            }

            if (!Schema::hasColumn('user_course_progress', 'completed')) {
                // NULL until the course is completed; then set to the date.
                $table->date('completed')->nullable()->after('status');
            }

            if (!Schema::hasColumn('user_course_progress', 'time_spent')) {
                // Cumulative seconds spent in the course viewer.
                $table->unsignedInteger('time_spent')->default(0)->after('completed');
            }

            if (!Schema::hasColumn('user_course_progress', 'assessment_score')) {
                // Best assessment score (0–100). NULL = not yet attempted.
                $table->unsignedTinyInteger('assessment_score')->nullable()->after('time_spent');
            }
        });

        // ── Indexes — tuned for high-volume queries ───────────────────────────

        // Most common query: "show all courses for user X"
        $this->addCompositeIndexSafe(
            'user_course_progress',
            ['user_id', 'status'],
            'ucp_user_status_idx'
        );

        // Admin dashboard: "show all users on course Y"
        $this->addCompositeIndexSafe(
            'user_course_progress',
            ['course_id', 'status'],
            'ucp_course_status_idx'
        );

        // Completion reports: "who finished course Y and when?"
        $this->addCompositeIndexSafe(
            'user_course_progress',
            ['course_id', 'completed'],
            'ucp_course_completed_idx'
        );

        // Quick enrolled lookup
        $this->addCompositeIndexSafe(
            'user_course_progress',
            ['user_id', 'enrolled'],
            'ucp_user_enrolled_idx'
        );

        // Unique constraint — one row per (user, course)
        $this->addUniqueConstraintSafe(
            'user_course_progress',
            ['user_id', 'course_id'],
            'ucp_user_course_unique'
        );

        // ── user_chapter_progress — ensure indexes exist ──────────────────────
        if (Schema::hasTable('user_chapter_progress')) {
            $this->addCompositeIndexSafe(
                'user_chapter_progress',
                ['user_id', 'done'],
                'uchapt_user_done_idx'
            );
            $this->addCompositeIndexSafe(
                'user_chapter_progress',
                ['chapter_id', 'done'],
                'uchapt_chapter_done_idx'
            );
        }

        // ── user_module_progress — ensure indexes exist ───────────────────────
        if (Schema::hasTable('user_module_progress')) {
            $this->addCompositeIndexSafe(
                'user_module_progress',
                ['user_id', 'done'],
                'umod_user_done_idx'
            );
        }
    }

    public function down(): void
    {
        Schema::table('user_course_progress', function (Blueprint $table) {
            $this->dropColumnIfExists('user_course_progress', 'enrolled');
            $this->dropColumnIfExists('user_course_progress', 'status');
            $this->dropColumnIfExists('user_course_progress', 'completed');
            $this->dropColumnIfExists('user_course_progress', 'time_spent');
            $this->dropColumnIfExists('user_course_progress', 'assessment_score');
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function addCompositeIndexSafe(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (\Exception) {}
    }

    private function addUniqueConstraintSafe(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
        } catch (\Exception) {}
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
        }
    }
};
