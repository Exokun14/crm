<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─────────────────────────────────────────────────────────────────────────────
// FIX: The `courses` table has per-user columns (enrolled, progress,
// time_spent, completed) that were written to by the old updateProgress
// logic, causing one user's completion to appear for every other user.
//
// These columns have been replaced by user_course_progress (per-user table).
// Removing them from the shared table prevents any future accidental writes.
// ─────────────────────────────────────────────────────────────────────────────

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['enrolled', 'progress', 'time_spent', 'completed']);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('enrolled')->default(false)->after('cat');
            $table->integer('progress')->default(0)->after('enrolled');
            $table->integer('time_spent')->nullable()->default(0)->after('progress');
            $table->boolean('completed')->nullable()->default(false)->after('time_spent');
        });
    }
};
