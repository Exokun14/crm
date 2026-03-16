<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DunkinDonutsSeeder extends Seeder
{
    public function run(): void
    {
        // Step 1: Insert into 'companies' table
        $companyId = DB::table('companies')->insertGetId([
            'company_name'       => 'Dunkin\' Donuts',
            'company_logo'       => null,
            'industry_type'      => 'fnb',
            'email'              => 'contact@dunkin.com',
            'name'               => 'Dunkin\' Donuts Philippines',
            'store_name'         => 'Dunkin\' Donuts',
            'industry'           => 'fnb',
            'contact_email'      => 'contact@dunkin.com',
            'phone'              => '+63 2 8888 5555',
            'contact_person'     => 'John Santos',
            'alt_contact_person' => 'Anna Cruz',
            'alt_contact_email'  => 'anna.cruz@dunkin.com',
            'alt_contact_phone'  => '+63 917 111 2222',
            'account_manager'    => 'Maria Reyes',
            'msa_start'          => '2024-01-01',
            'msa_end'            => '2026-01-01',
            'active'             => true,
            'cover_photo_path'   => null,
            'brand_color'        => '#FF671F',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Step 2: Insert manager and user accounts
        DB::table('users')->insert([
            [
                'full_name'          => 'Anna Cruz',
                'name'               => 'anna.cruz',
                'email'              => 'anna.cruz@dunkin.com',
                'phone_number'       => '+63 917 111 2222',
                'phone'              => '+63 917 111 2222',
                'position'           => 'Manager',
                'position_title'     => 'Operations Manager',
                'status'             => 'active',
                'password_hash'      => Hash::make('password'),
                'password'           => Hash::make('password'),
                'role'               => 'manager',
                'industry'           => 'fnb',
                'company_id'         => $companyId,
                'access_level'       => 'manager',
                'account_type'       => 'account_manager',
                'email_verified_at'  => null,
                'role_assigned_at'   => now(),
                'remember_token'     => Str::random(10),
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'full_name'          => 'Carlos Mendoza',
                'name'               => 'carlos.mendoza',
                'email'              => 'carlos.mendoza@dunkin.com',
                'phone_number'       => '+63 918 333 4444',
                'phone'              => '+63 918 333 4444',
                'position'           => 'Staff',
                'position_title'     => 'HR Staff',
                'status'             => 'active',
                'password_hash'      => Hash::make('password'),
                'password'           => Hash::make('password'),
                'role'               => 'user',
                'industry'           => 'fnb',
                'company_id'         => $companyId,
                'access_level'       => 'user',
                'account_type'       => 'user',
                'email_verified_at'  => null,
                'role_assigned_at'   => now(),
                'remember_token'     => Str::random(10),
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);

        $this->command->info("Dunkin' Donuts seeded successfully into 'companies' (ID: {$companyId})");
        $this->command->table(
            ['Name', 'Email', 'Role', 'Access Level', 'Account Type', 'Password'],
            [
                ['Anna Cruz',      'anna.cruz@dunkin.com',      'manager', 'manager', 'account_manager', 'password'],
                ['Carlos Mendoza', 'carlos.mendoza@dunkin.com', 'user',    'user',    'user',            'password'],
            ]
        );
    }
}
