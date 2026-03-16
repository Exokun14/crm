<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_course_icons_table
 *
 * Stores user-specific course icon uploads.
 * Each row belongs to one user. The `path` column is the Storage disk path
 * (relative to the `public` disk), e.g. "course_icons/3/icon.png".
 *
 * Run:  php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_icons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('path');           // storage path on public disk
            $table->string('name')->nullable(); // original filename, for display
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade'); // icons deleted when user is deleted
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_icons');
    }
};
