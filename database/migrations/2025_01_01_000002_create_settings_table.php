<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed default categories so the wizard isn't empty on first load
        $defaults = ['POS Training', 'Food Safety', 'Customer Service', 'HR & Compliance', 'Sales', 'Operations', 'Finance', 'Leadership'];
        foreach ($defaults as $cat) {
            DB::table('settings')->insert([
                'key'        => 'category',
                'value'      => $cat,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
