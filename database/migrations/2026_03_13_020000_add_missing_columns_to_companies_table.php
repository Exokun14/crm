<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('id');
            $table->string('company_logo')->nullable()->after('company_name');
            $table->string('industry_type')->nullable()->after('company_logo');
            $table->string('email')->nullable()->after('industry_type');
            $table->unsignedBigInteger('alternate_contact_1')->nullable()->after('account_manager');
            $table->unsignedBigInteger('alternate_contact_2')->nullable()->after('alternate_contact_1');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'company_name',
                'company_logo',
                'industry_type',
                'email',
                'alternate_contact_1',
                'alternate_contact_2',
            ]);
        });
    }
};
