<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal fields migration.
 *
 * Fully idempotent — every column/table addition is guarded with
 * hasColumn() / hasTable() so running this on an existing DB is safe.
 *
 * Run with:
 *   php artisan migrate --path=database/migrations/2026_03_12_000001_add_portal_fields.php
 */
return new class extends Migration
{
    // -------------------------------------------------------------------------
    public function up(): void
    {
        // ── 1. users — add role, industry (may already exist), plus new fields
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role'))
                $table->string('role')->default('user')->after('password'); // 'admin' | 'user'

            if (!Schema::hasColumn('users', 'industry'))
                $table->string('industry')->nullable()->after('role'); // 'fnb' | 'retail' | 'warehouse'

            if (!Schema::hasColumn('users', 'company_id'))
                $table->foreignId('company_id')->nullable()->after('industry')
                      ->constrained('companies')->nullOnDelete();

            if (!Schema::hasColumn('users', 'phone'))
                $table->string('phone', 30)->nullable()->after('email');

            if (!Schema::hasColumn('users', 'position'))
                $table->string('position')->nullable()->after('phone'); // job title e.g. "Manager"

            if (!Schema::hasColumn('users', 'status'))
                $table->string('status')->default('active')->after('position'); // 'active' | 'inactive'
        });

        // ── 2. companies — extend with portal fields
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'store_name'))
                $table->string('store_name')->nullable()->after('name'); // display name shown in UI hero

            if (!Schema::hasColumn('companies', 'phone'))
                $table->string('phone', 30)->nullable()->after('contact_email');

            if (!Schema::hasColumn('companies', 'contact_person'))
                $table->string('contact_person')->nullable()->after('phone');

            if (!Schema::hasColumn('companies', 'alt_contact_person'))
                $table->string('alt_contact_person')->nullable()->after('contact_person');

            if (!Schema::hasColumn('companies', 'alt_contact_email'))
                $table->string('alt_contact_email')->nullable()->after('alt_contact_person');

            if (!Schema::hasColumn('companies', 'alt_contact_phone'))
                $table->string('alt_contact_phone', 30)->nullable()->after('alt_contact_email');

            if (!Schema::hasColumn('companies', 'account_manager'))
                $table->string('account_manager')->nullable()->after('alt_contact_phone'); // e.g. "Renz Talentino"

            if (!Schema::hasColumn('companies', 'msa_start'))
                $table->date('msa_start')->nullable()->after('account_manager');

            if (!Schema::hasColumn('companies', 'msa_end'))
                $table->date('msa_end')->nullable()->after('msa_start'); // MSA expiry shown on overview
        });

        // ── 3. branches — per-company locations
        if (!Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name');                    // e.g. "SM Mall", "Makati"
                $table->string('site')->nullable();        // full site label shown in UI hero
                $table->unsignedInteger('seats')->default(0); // licensed seat count
                $table->string('license_tag')->nullable(); // e.g. "LIC-NKE-SM-0601"
                $table->timestamps();
            });
        }

        // ── 4. pos_devices — POS terminals per branch
        if (!Schema::hasTable('pos_devices')) {
            Schema::create('pos_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('status')->default('active'); // 'active' | 'offline' | 'maintenance'
                $table->string('model')->nullable();          // e.g. "Epson TM-T82X"
                $table->string('serial')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('os')->nullable();             // e.g. "Windows 10 IoT"
                $table->date('msa_start')->nullable();
                $table->date('msa_end')->nullable();
                $table->date('warranty_end')->nullable();
                $table->timestamps();
            });
        }

        // ── 5. licenses — software license records per company
        if (!Schema::hasTable('licenses')) {
            Schema::create('licenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('license_key')->nullable();  // e.g. "LIC-POP-2024-0601"
                $table->date('sa_start')->nullable();       // software assurance start
                $table->date('sa_end')->nullable();         // software assurance end
                $table->string('krunch_version')->nullable(); // e.g. "v3.2.1"
                $table->timestamps();
            });
        }

        // ── 6. tickets — support tickets per company/branch
        if (!Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // submitter
                $table->string('subject');
                $table->text('description')->nullable();
                $table->string('status')->default('open');    // 'open' | 'pending' | 'closed'
                $table->string('priority')->default('normal'); // 'low' | 'normal' | 'high' | 'critical'
                $table->string('category')->nullable();        // maps to ticket categories in UI
                $table->timestamps();
            });
        }

        // ── 7. notifications — per-user/company notification feed
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
                $table->string('type')->default('info');  // 'info' | 'warning' | 'alert'
                $table->string('title')->nullable();
                $table->text('message');
                $table->boolean('read')->default(false);
                $table->timestamps();
            });
        }
    }

    // -------------------------------------------------------------------------
    public function down(): void
    {
        // Drop new tables in reverse FK order
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('pos_devices');
        Schema::dropIfExists('branches');

        // Strip added columns from companies
        Schema::table('companies', function (Blueprint $table) {
            $cols = ['store_name','phone','contact_person','alt_contact_person',
                     'alt_contact_email','alt_contact_phone','account_manager','msa_start','msa_end'];
            foreach ($cols as $col)
                if (Schema::hasColumn('companies', $col)) $table->dropColumn($col);
        });

        // Strip added columns from users (only ones we may have added)
        Schema::table('users', function (Blueprint $table) {
            foreach (['phone','position','status'] as $col)
                if (Schema::hasColumn('users', $col)) $table->dropColumn($col);
        });
    }
};
