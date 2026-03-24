<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Learning Module — Production-Ready Schema
 *
 * Key design decisions:
 *
 * 1. user_course_progress uses course_id (FK) not a course name string.
 *    Renaming a course won't orphan progress records. company is derived
 *    from users.company_id — never stored as a duplicate string.
 *
 * 2. UNIQUE(user_id, course_id) on user_course_progress prevents duplicate
 *    rows from race conditions or double API calls.
 *
 * 3. Indexes on courses(stage, active, cat) so catalog queries never do
 *    a full table scan regardless of how many courses exist.
 *
 * 4. chapter_activities pivot links activities to chapters so activities
 *    are never floating orphan rows.
 *
 * 5. chapters.content stays JSON but type is a proper enum column with an
 *    index — filter chapters by type without scanning the content blob.
 *
 * 6. media_url is varchar(500) — real-world video/CDN URLs regularly exceed
 *    the 255-char limit that the LMS schema used.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── courses ───────────────────────────────────────────────────────────
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('desc')->nullable();
            $table->string('time')->default('');
            $table->string('cat')->default('');
            $table->string('thumb')->nullable();
            $table->string('thumb_emoji')->nullable()->default('📚');
            $table->boolean('active')->default(false);
            $table->enum('stage', [
                'draft', 'review_ready', 'published', 'unpublished', 'template',
            ])->default('draft');
            $table->timestamps();

            $table->index('stage');
            $table->index('active');
            $table->index('cat');
            $table->index(['stage', 'active']);
        });

        // ── company_course pivot ──────────────────────────────────────────────
        Schema::create('company_course', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->integer('company_id');
            $table->timestamp('assigned_at')->useCurrent();

            $table->unique(['course_id', 'company_id']);

            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('company')->onDelete('cascade');
        });

        // ── modules ───────────────────────────────────────────────────────────
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->index(['course_id', 'order']);
        });

        // ── chapters ──────────────────────────────────────────────────────────
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('module_id');
            $table->string('title');
            $table->enum('type', ['lesson', 'quiz', 'assessment'])->default('lesson');
            $table->unsignedInteger('order')->default(0);
            $table->json('content')->nullable();
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');
            $table->index(['module_id', 'order']);
            $table->index('type');
        });

        // ── activities ────────────────────────────────────────────────────────
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('activity_id')->unique();
            $table->enum('type', [
                'accordion', 'flashcard', 'checklist', 'matching', 'fillblank', 'hotspot',
            ]);
            $table->string('title');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->json('data')->nullable();
            // FIX: varchar(500) — CDN/video URLs regularly exceed 255 chars
            $table->string('media_url', 500)->nullable();
            $table->enum('media_type', ['image', 'video', 'file'])->nullable();
            $table->string('media_name')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });

        // ── chapter_activities pivot ──────────────────────────────────────────
        Schema::create('chapter_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chapter_id');
            $table->string('activity_id');
            $table->unsignedInteger('order')->default(0);

            $table->unique(['chapter_id', 'activity_id']);

            $table->foreign('chapter_id')->references('id')->on('chapters')->onDelete('cascade');
            $table->foreign('activity_id')->references('activity_id')->on('activities')->onDelete('cascade');
        });

        // ── course_icons ──────────────────────────────────────────────────────
        Schema::create('course_icons', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('path');
            $table->string('name');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // ── user_course_progress ──────────────────────────────────────────────
        Schema::create('user_course_progress', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('started')->nullable();
            $table->date('completed')->nullable();
            $table->enum('status', ['Not Started', 'In Progress', 'Completed'])
                  ->default('Not Started');
            $table->unsignedInteger('time_spent')->default(0);
            $table->integer('assessment_score')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');

            $table->index(['user_id', 'status']);
            $table->index('course_id');
        });

        // ── user_chapter_progress ─────────────────────────────────────────────
        Schema::create('user_chapter_progress', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->unsignedBigInteger('chapter_id');
            $table->boolean('done')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'chapter_id']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('chapter_id')->references('id')->on('chapters')->onDelete('cascade');
        });

        // ── user_module_progress ──────────────────────────────────────────────
        Schema::create('user_module_progress', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->unsignedBigInteger('module_id');
            $table->boolean('done')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'module_id']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');
        });

        // ── settings_categories ───────────────────────────────────────────────
        Schema::create('settings_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        DB::table('settings_categories')->insert([
            ['name' => 'POS Training',     'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Food Safety',      'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Customer Service', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'HR & Compliance',  'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Operations',       'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_progress');
        Schema::dropIfExists('user_chapter_progress');
        Schema::dropIfExists('user_course_progress');
        Schema::dropIfExists('chapter_activities');
        Schema::dropIfExists('course_icons');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('chapters');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('company_course');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('settings_categories');
    }
};
