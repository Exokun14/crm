<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DunkinDonutsClientSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = DB::table('company')->insertGetId([
            'company_name'        => 'Dunkin\' Donuts',
            'company_logo'        => null,
            'industry_type'       => 'fnb',
            'contact_person'      => 'John Santos',
            'email'               => 'john.santos@dunkin.com',
            'phone'               => '+63 912 345 6789',
            'account_manager'     => 'Maria Reyes',
            'alternate_contact_1' => null, // will update after inserting alternate contacts
            'alternate_contact_2' => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $altContact1Id = DB::table('company_alternate_contact')->insertGetId([
            'company_id'     => $companyId,
            'company_under'  => 'Dunkin\' Donuts - Operations',
            'contact_person' => 'Anna Cruz',
            'email'          => 'anna.cruz@dunkin.com',
            'phone'          => '+63 917 111 2222',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $altContact2Id = DB::table('company_alternate_contact')->insertGetId([
            'company_id'     => $companyId,
            'company_under'  => 'Dunkin\' Donuts - HR',
            'contact_person' => 'Carlos Mendoza',
            'email'          => 'carlos.mendoza@dunkin.com',
            'phone'          => '+63 918 333 4444',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Link alternate contacts back to the company record
        DB::table('company')
            ->where('id', $companyId)
            ->update([
                'alternate_contact_1' => $altContact1Id,
                'alternate_contact_2' => $altContact2Id,
                'updated_at'          => now(),
            ]);

        $this->command->info("Dunkin' Donuts client seeded successfully (company ID: {$companyId})");
    }
}
