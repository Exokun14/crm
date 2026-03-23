<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Patch Migration — Safe Incremental Update
 *
 * All changes are guarded with hasTable() / hasColumn() / try-catch so this
 * migration is fully idempotent. Run it as many times as you like without
 * breaking anything that already exists.
 *
 * What this does:
 *  1. courses          — adds stage, thumb_emoji columns + missing indexes
 *  2. company_course   — creates pivot table if missing
 *  3. modules          — adds order column + composite index if missing
 *  4. chapters         — adds type column + indexes if missing
 *  5. activities       — adds media_* columns + indexes if missing
 *  6. chapter_activities — creates pivot table if missing
 *  7. user_course_progress — adds course_id FK, unique constraint, indexes
 *  8. user_chapter_progress — creates if missing
 *  9. user_module_progress  — creates if missing
 * 10. settings_categories  — creates + seeds if missing
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. courses ────────────────────────────────────────────────────────
        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'thumb_emoji')) {
                $table->string('thumb_emoji')->nullable()->default('📚')->after('thumb');
            }
            if (!Schema::hasColumn('courses', 'stage')) {
                $table->enum('stage', [
                    'draft', 'review_ready', 'published', 'unpublished', 'template',
                ])->default('draft')->after('active');
            }
        });

        // Add indexes only if they don't already exist
        $this->addIndexSafe('courses', 'stage',           'courses_stage_index');
        $this->addIndexSafe('courses', 'active',          'courses_active_index');
        $this->addIndexSafe('courses', 'cat',             'courses_cat_index');
        $this->addCompositeIndexSafe('courses', ['stage', 'active'], 'courses_stage_active_index');

        // ── 2. company_course pivot ───────────────────────────────────────────
        if (!Schema::hasTable('company_course')) {
            Schema::create('company_course', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id');
                $table->integer('company_id');
                $table->timestamp('assigned_at')->useCurrent();

                $table->unique(['course_id', 'company_id']);

                $table->foreign('course_id')
                      ->references('id')->on('courses')->onDelete('cascade');
                $table->foreign('company_id')
                      ->references('id')->on('company')->onDelete('cascade');
            });
        }

        // ── 3. modules ────────────────────────────────────────────────────────
        Schema::table('modules', function (Blueprint $table) {
            if (!Schema::hasColumn('modules', 'order')) {
                $table->unsignedInteger('order')->default(0)->after('title');
            }
        });
        $this->addCompositeIndexSafe('modules', ['course_id', 'order'], 'modules_course_id_order_index');

        // ── 4. chapters ───────────────────────────────────────────────────────
        Schema::table('chapters', function (Blueprint $table) {
            if (!Schema::hasColumn('chapters', 'type')) {
                $table->enum('type', ['lesson', 'quiz', 'assessment'])
                      ->default('lesson')->after('title');
            }
            if (!Schema::hasColumn('chapters', 'order')) {
                $table->unsignedInteger('order')->default(0)->after('type');
            }
            if (!Schema::hasColumn('chapters', 'content')) {
                $table->json('content')->nullable()->after('order');
            }
        });
        $this->addCompositeIndexSafe('chapters', ['module_id', 'order'], 'chapters_module_id_order_index');
        $this->addIndexSafe('chapters', 'type', 'chapters_type_index');

        // ── 5. activities ─────────────────────────────────────────────────────
        Schema::table('activities', function (Blueprint $table) {
            if (!Schema::hasColumn('activities', 'media_url')) {
                $table->string('media_url')->nullable()->after('data');
            }
            if (!Schema::hasColumn('activities', 'media_type')) {
                $table->enum('media_type', ['image', 'video', 'file'])
                      ->nullable()->after('media_url');
            }
            if (!Schema::hasColumn('activities', 'media_name')) {
                $table->string('media_name')->nullable()->after('media_type');
            }
            if (!Schema::hasColumn('activities', 'data')) {
                $table->json('data')->nullable()->after('status');
            }
        });
        $this->addIndexSafe('activities', 'status', 'activities_status_index');
        $this->addIndexSafe('activities', 'type',   'activities_type_index');

        // ── 6. chapter_activities pivot ───────────────────────────────────────
        if (!Schema::hasTable('chapter_activities')) {
            Schema::create('chapter_activities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('chapter_id');
                $table->string('activity_id');
                $table->unsignedInteger('order')->default(0);

                $table->unique(['chapter_id', 'activity_id']);

                $table->foreign('chapter_id')
                      ->references('id')->on('chapters')->onDelete('cascade');
                $table->foreign('activity_id')
                      ->references('activity_id')->on('activities')->onDelete('cascade');
            });
        }

        // ── 7. user_course_progress ───────────────────────────────────────────
        Schema::table('user_course_progress', function (Blueprint $table) {
            // Add course_id FK column if the table was using a string/name before
            if (!Schema::hasColumn('user_course_progress', 'course_id')) {
                $table->unsignedBigInteger('course_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('user_course_progress', 'time_spent')) {
                $table->unsignedInteger('time_spent')->default(0)->after('status');
            }
            if (!Schema::hasColumn('user_course_progress', 'assessment_score')) {
                $table->integer('assessment_score')->nullable()->after('time_spent');
            }
        });

        // Add FK on course_id if not already there
        $this->addForeignKeySafe(
            'user_course_progress',
            'course_id',
            'user_course_progress_course_id_foreign',
            'courses', 'id'
        );

        // Add UNIQUE(user_id, course_id) if not already there
        $this->addUniqueConstraintSafe(
            'user_course_progress',
            ['user_id', 'course_id'],
            'user_course_progress_user_id_course_id_unique'
        );

        $this->addCompositeIndexSafe(
            'user_course_progress',
            ['user_id', 'status'],
            'user_course_progress_user_id_status_index'
        );
        $this->addIndexSafe(
            'user_course_progress',
            'course_id',
            'user_course_progress_course_id_index'
        );

        // ── 8. user_chapter_progress ──────────────────────────────────────────
        if (!Schema::hasTable('user_chapter_progress')) {
            Schema::create('user_chapter_progress', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id');
                $table->unsignedBigInteger('chapter_id');
                $table->boolean('done')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'chapter_id']);

                $table->foreign('user_id')
                      ->references('id')->on('users')->onDelete('cascade');
                $table->foreign('chapter_id')
                      ->references('id')->on('chapters')->onDelete('cascade');
            });
        }

        // ── 9. user_module_progress ───────────────────────────────────────────
        if (!Schema::hasTable('user_module_progress')) {
            Schema::create('user_module_progress', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id');
                $table->unsignedBigInteger('module_id');
                $table->boolean('done')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'module_id']);

                $table->foreign('user_id')
                      ->references('id')->on('users')->onDelete('cascade');
                $table->foreign('module_id')
                      ->references('id')->on('modules')->onDelete('cascade');
            });
        }

        // ── 10. settings_categories ───────────────────────────────────────────
        if (!Schema::hasTable('settings_categories')) {
            Schema::create('settings_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        // Seed only the rows that don't exist yet
        $defaults = [
            'POS Training', 'Food Safety', 'Customer Service',
            'HR & Compliance', 'Operations',
        ];
        foreach ($defaults as $name) {
            DB::table('settings_categories')->insertOrIgnore([
                'name'       => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Only drop things this migration created — don't touch pre-existing tables
        Schema::dropIfExists('user_module_progress');
        Schema::dropIfExists('user_chapter_progress');
        Schema::dropIfExists('chapter_activities');
        Schema::dropIfExists('company_course');

        // Reverse column additions on pre-existing tables
        Schema::table('user_course_progress', function (Blueprint $table) {
            $this->dropForeignIfExists('user_course_progress', 'user_course_progress_course_id_foreign');
            $this->dropColumnIfExists('user_course_progress', 'course_id');
            $this->dropColumnIfExists('user_course_progress', 'time_spent');
            $this->dropColumnIfExists('user_course_progress', 'assessment_score');
        });

        Schema::table('courses', function (Blueprint $table) {
            $this->dropColumnIfExists('courses', 'stage');
            $this->dropColumnIfExists('courses', 'thumb_emoji');
        });

        Schema::table('chapters', function (Blueprint $table) {
            $this->dropColumnIfExists('chapters', 'type');
            $this->dropColumnIfExists('chapters', 'order');
            $this->dropColumnIfExists('chapters', 'content');
        });

        Schema::table('modules', function (Blueprint $table) {
            $this->dropColumnIfExists('modules', 'order');
        });

        Schema::table('activities', function (Blueprint $table) {
            $this->dropColumnIfExists('activities', 'media_url');
            $this->dropColumnIfExists('activities', 'media_type');
            $this->dropColumnIfExists('activities', 'media_name');
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function addIndexSafe(string $table, string $column, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($column, $indexName) {
                $t->index($column, $indexName);
            });
        } catch (\Exception $e) {
            // Index already exists — safe to ignore
        }
    }

    private function addCompositeIndexSafe(string $table, array $columns, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->index($columns, $indexName);
            });
        } catch (\Exception $e) {
            // Already exists
        }
    }

    private function addUniqueConstraintSafe(string $table, array $columns, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->unique($columns, $indexName);
            });
        } catch (\Exception $e) {
            // Already exists
        }
    }

    private function addForeignKeySafe(
        string $table,
        string $column,
        string $fkName,
        string $refTable,
        string $refColumn
    ): void {
        try {
            Schema::table($table, function (Blueprint $t) use ($column, $fkName, $refTable, $refColumn) {
                $t->foreign($column, $fkName)
                  ->references($refColumn)->on($refTable)->onDelete('cascade');
            });
        } catch (\Exception $e) {
            // FK already exists or column is nullable with no data yet
        }
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
        }
    }

    private function dropForeignIfExists(string $table, string $fkName): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($fkName));
        } catch (\Exception $e) {
            // Didn't exist
        }
    }
};
