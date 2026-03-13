<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('company_logo')->nullable();
            $table->string('industry_type');
            $table->string('contact_person');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('account_manager')->nullable();
            $table->unsignedBigInteger('alternate_contact_1')->nullable();
            $table->unsignedBigInteger('alternate_contact_2')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company');
    }
};
