<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Company;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Admin user (no company) ────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'       => 'Admin User',
                'password'   => Hash::make('password'),
                'role'       => 'admin',
                'industry'   => null,
                'company_id' => null,
            ]
        );

        echo "✅ Admin created: admin@example.com / password\n";

        // ── 2. Sample companies ───────────────────────────────────────────────
        $companies = [
            [
                'name'          => 'Popeyes Manila',
                'industry'      => 'fnb',
                'contact_email' => 'popeyes@example.com',
            ],
            [
                'name'          => 'Acme Retail',
                'industry'      => 'retail',
                'contact_email' => 'acme@example.com',
            ],
            [
                'name'          => 'Summit Warehouse',
                'industry'      => 'warehouse',
                'contact_email' => 'summit@example.com',
            ],
        ];

        foreach ($companies as $data) {
            $company = Company::updateOrCreate(
                ['name' => $data['name']],
                $data
            );
            echo "✅ Company: {$company->name} (id: {$company->id})\n";
        }

        // ── 3. One client user per company ────────────────────────────────────
        $clientUsers = [
            [
                'name'    => 'FnB Client',
                'email'   => 'client.fnb@example.com',
                'company' => 'Popeyes Manila',
            ],
            [
                'name'    => 'Retail Client',
                'email'   => 'client.retail@example.com',
                'company' => 'Acme Retail',
            ],
            [
                'name'    => 'Warehouse Client',
                'email'   => 'client.warehouse@example.com',
                'company' => 'Summit Warehouse',
            ],
        ];

        foreach ($clientUsers as $data) {
            $company = Company::where('name', $data['company'])->first();

            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'       => $data['name'],
                    'password'   => Hash::make('password'),
                    'role'       => 'user',
                    'industry'   => $company->industry,
                    'company_id' => $company->id,
                ]
            );

            echo "✅ Client: {$data['email']} → {$data['company']}\n";
        }

        echo "\n🎉 Done. All passwords are: password\n";
    }
}
