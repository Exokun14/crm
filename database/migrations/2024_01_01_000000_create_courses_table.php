<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('desc')->nullable();
            $table->string('time')->nullable();
            $table->string('cat')->nullable();
            $table->string('thumb')->nullable();
            $table->string('thumb_emoji')->nullable();
            $table->boolean('active')->default(false);
            $table->string('stage')->default('draft');
            $table->integer('progress')->default(0);
            $table->boolean('enrolled')->default(false);
            $table->boolean('completed')->default(false);
            $table->integer('time_spent')->default(0);
            $table->json('modules')->nullable();
            $table->json('companies')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
