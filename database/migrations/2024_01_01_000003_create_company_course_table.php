<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();

            // A course can only be assigned to a company once
            $table->unique(['company_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_course');
    }
};
