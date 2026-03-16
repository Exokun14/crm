<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DunkinDonutsUsersSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = DB::table('company')->where('company_name', 'Dunkin\' Donuts')->value('id');

        $users = [
            [
                'full_name'              => 'Anna Cruz',
                'name'                   => 'anna.cruz',
                'email'                  => 'anna.cruz@dunkin.com',
                'phone_number'           => '+63 917 111 2222',
                'phone'                  => '+63 917 111 2222',
                'position'               => 'Manager',
                'status'                 => 'active',
                'password_hash'          => Hash::make('password'),
                'password'               => Hash::make('password'),
                'role'                   => 'manager',
                'industry'               => 'fnb',
                'company_id'             => $companyId,
                'position_title'         => 'Operations Manager',
                'access_level'           => 'manager',
                'account_type'           => 'account_manager',
                'role_assigned_at'       => now(),
                'email_verified_at'      => now(),
                'remember_token'         => Str::random(10),
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'full_name'              => 'Carlos Mendoza',
                'name'                   => 'carlos.mendoza',
                'email'                  => 'carlos.mendoza@dunkin.com',
                'phone_number'           => '+63 918 333 4444',
                'phone'                  => '+63 918 333 4444',
                'position'               => 'Staff',
                'status'                 => 'active',
                'password_hash'          => Hash::make('password'),
                'password'               => Hash::make('password'),
                'role'                   => 'user',
                'industry'               => 'fnb',
                'company_id'             => $companyId,
                'position_title'         => 'HR Staff',
                'access_level'           => 'user',
                'account_type'           => 'user',
                'role_assigned_at'       => now(),
                'email_verified_at'      => now(),
                'remember_token'         => Str::random(10),
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
        ];

        DB::table('users')->insert($users);

        $this->command->info("Dunkin' Donuts users seeded successfully!");
        $this->command->table(
            ['Name', 'Email', 'Role', 'Password'],
            [
                ['Anna Cruz',     'anna.cruz@dunkin.com',    'manager', 'password'],
                ['Carlos Mendoza','carlos.mendoza@dunkin.com','user',   'password'],
            ]
        );
    }
}
