<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds branding columns to the companies table:
     *  - cover_photo_path  : stored path to the uploaded cover/banner image
     *  - brand_color       : hex colour chosen by the user (e.g. "#f97316")
     *                        If null the frontend derives the colour from the logo automatically.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cover_photo_path')->nullable()->after('logo_path');
            $table->string('brand_color', 9)->nullable()->after('cover_photo_path');
            // 9 chars supports  #RRGGBB  and  #RRGGBBAA  formats
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cover_photo_path', 'brand_color']);
        });
    }
};
