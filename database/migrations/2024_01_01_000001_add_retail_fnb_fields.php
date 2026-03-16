<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds fields required for Retail Pro and F&B portal features:
     *
     * branches:
     *   - date_of_implementation  date of branch go-live
     *   - activation_code         per-branch activation code (F&B / Retail)
     *   - krunch_id               per-branch Krunch POS identifier
     *   - active                  whether branch is currently active
     *
     * licenses:
     *   - activation_code         license-level activation code
     *   - seats                   max number of branches/seats allowed under this license
     *
     * pos_devices:
     *   - under_warranty          explicit warranty status flag for tracking
     *                             (separate from warranty_end so it can be manually overridden)
     */
    public function up(): void
    {
        // ── branches ─────────────────────────────────────────────────────────
        Schema::table('branches', function (Blueprint $table) {
            $table->date('date_of_implementation')
                  ->nullable()
                  ->after('license_tag')
                  ->comment('Date the branch went live / was implemented');

            $table->string('activation_code', 100)
                  ->nullable()
                  ->after('date_of_implementation')
                  ->comment('Per-branch activation code');

            $table->string('krunch_id', 100)
                  ->nullable()
                  ->after('activation_code')
                  ->comment('Per-branch Krunch POS identifier');

            $table->boolean('active')
                  ->default(true)
                  ->after('krunch_id')
                  ->comment('Whether this branch is currently active');
        });

        // ── licenses ─────────────────────────────────────────────────────────
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('activation_code', 100)
                  ->nullable()
                  ->after('license_key')
                  ->comment('License-level activation code');

            $table->unsignedSmallInteger('seats')
                  ->nullable()
                  ->after('activation_code')
                  ->comment('Maximum number of branches/seats allowed under this license');
        });

        // ── pos_devices ───────────────────────────────────────────────────────
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->boolean('under_warranty')
                  ->nullable()
                  ->after('warranty_end')
                  ->comment('Explicit warranty status — nullable means not yet assessed, true = under warranty, false = out of warranty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_implementation',
                'activation_code',
                'krunch_id',
                'active',
            ]);
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn([
                'activation_code',
                'seats',
            ]);
        });

        Schema::table('pos_devices', function (Blueprint $table) {
            $table->dropColumn('under_warranty');
        });
    }
};
