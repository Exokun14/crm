<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Flexible role column — add new roles by inserting new users/updating records,
            // no schema change needed. Current roles: 'admin', 'manager', 'user'
            $table->string('role')->default('user')->after('email');

            // Optional: track who assigned the role and when
            $table->timestamp('role_assigned_at')->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'role_assigned_at']);
        });
    }
};
