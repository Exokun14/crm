<?php
/* Migration: add_missing_columns_to_users_table
 * Place in: database/migrations/
 * Run with: php artisan migrate
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds all columns expected by Add_User_Controller that may not exist yet.
     * Uses addColumnIfNotExists pattern so it is safe to run on a partially
     * migrated database.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            // Profile photo — stored as a relative path, e.g. user_profiles/foo.jpg
            if (!Schema::hasColumn('users', 'profile_photo')) {
                $table->string('profile_photo')->nullable()->after('id');
            }

            // Full name (replaces Laravel's default name column if absent)
            if (!Schema::hasColumn('users', 'full_name')) {
                $table->string('full_name', 150)->after('profile_photo');
            }

            // Phone number
            if (!Schema::hasColumn('users', 'phone_number')) {
                $table->string('phone_number', 30)->nullable()->after('email');
            }

            // Company FK — references the company table
            if (!Schema::hasColumn('users', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable()->after('phone_number');
                $table->foreign('company_id')
                      ->references('id')
                      ->on('company')
                      ->nullOnDelete();
            }

            // Job title
            if (!Schema::hasColumn('users', 'position_title')) {
                $table->string('position_title', 150)->nullable()->after('company_id');
            }

            // Access level enum
            if (!Schema::hasColumn('users', 'access_level')) {
                $table->enum('access_level', ['super_admin', 'system_admin', 'manager', 'user'])
                      ->default('user')
                      ->after('position_title');
            }

            // Account type enum
            if (!Schema::hasColumn('users', 'account_type')) {
                $table->enum('account_type', ['admin', 'account_manager', 'user'])
                      ->default('user')
                      ->after('access_level');
            }

            // Status enum
            if (!Schema::hasColumn('users', 'status')) {
                $table->enum('status', ['active', 'inactive'])
                      ->default('inactive')
                      ->after('account_type');
            }

            // Password hash — separate column so it does not collide with
            // Laravel's built-in `password` column if that also exists.
            if (!Schema::hasColumn('users', 'password_hash')) {
                $table->string('password_hash')->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop foreign key before dropping the column
            if (Schema::hasColumn('users', 'company_id')) {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            }

            foreach ([
                'profile_photo',
                'full_name',
                'phone_number',
                'position_title',
                'access_level',
                'account_type',
                'status',
                'password_hash',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
