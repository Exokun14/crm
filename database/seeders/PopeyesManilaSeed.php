<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PopeyesManilaSeed
 *
 * Seeds Popeyes Manila company, its client user, branches,
 * POS devices, licenses, and notifications.
 *
 * Run with:
 *   php artisan db:seed --class=PopeyesManilaSeed
 *
 * Idempotent — safe to re-run, will update existing rows.
 */
class PopeyesManilaSeed extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            // ----------------------------------------------------------------
            // 1. COMPANY
            // ----------------------------------------------------------------
            $companyData = [
                'name'               => 'Popeyes Manila',
                'store_name'         => 'Popeyes Philippines',
                'contact_email'      => 'popeyes@example.com',
                'phone'              => '+63 2 8888 1234',
                'contact_person'     => 'Rafael Mendoza',
                'alt_contact_person' => 'Camille Reyes',
                'alt_contact_email'  => 'camille.reyes@popeyes.ph',
                'alt_contact_phone'  => '+63 917 555 0101',
                'account_manager'    => 'Renz Talentino',
                'msa_start'          => '2024-03-01',
                'msa_end'            => '2026-02-28',
                'active'             => true,
                'updated_at'         => now(),
            ];

            $existing = DB::table('companies')->where('name', 'Popeyes Manila')->first();
            if ($existing) {
                DB::table('companies')->where('id', $existing->id)->update($companyData);
                $companyId = $existing->id;
            } else {
                $companyId = DB::table('companies')->insertGetId(
                    array_merge($companyData, ['created_at' => now()])
                );
            }

            // ----------------------------------------------------------------
            // 2. USER  (the fnb client login)
            // ----------------------------------------------------------------
            $userData = [
                'name'       => 'FnB Client',
                'password'   => Hash::make('password'),
                'role'       => 'user',
                'industry'   => 'fnb',
                'company_id' => $companyId,
                'phone'      => '+63 917 555 0100',
                'position'   => 'Portal Administrator',
                'status'     => 'active',
                'updated_at' => now(),
            ];

            $existingUser = DB::table('users')->where('email', 'client.fnb@example.com')->first();
            if ($existingUser) {
                DB::table('users')->where('id', $existingUser->id)->update($userData);
                $userId = $existingUser->id;
            } else {
                $userId = DB::table('users')->insertGetId(array_merge($userData, [
                    'email'             => 'client.fnb@example.com',
                    'email_verified_at' => now(),
                    'created_at'        => now(),
                ]));
            }

            // ----------------------------------------------------------------
            // 3. BRANCHES
            // ----------------------------------------------------------------
            $branches = [
                [
                    'name'        => 'SM Mall of Asia',
                    'site'        => 'Popeyes - SM MoA, Pasay City',
                    'seats'       => 12,
                    'license_tag' => 'LIC-POP-SMMOA-2024',
                ],
                [
                    'name'        => 'Robinsons Galleria',
                    'site'        => 'Popeyes - Robinsons Galleria, Ortigas',
                    'seats'       => 10,
                    'license_tag' => 'LIC-POP-ROBGAL-2024',
                ],
                [
                    'name'        => 'Trinoma',
                    'site'        => 'Popeyes - Trinoma, Quezon City',
                    'seats'       => 8,
                    'license_tag' => 'LIC-POP-TRI-2024',
                ],
            ];

            $branchIds = [];
            foreach ($branches as $b) {
                $existing = DB::table('branches')
                    ->where('company_id', $companyId)
                    ->where('name', $b['name'])
                    ->first();
                $row = array_merge($b, ['company_id' => $companyId, 'updated_at' => now()]);
                if ($existing) {
                    DB::table('branches')->where('id', $existing->id)->update($row);
                    $branchIds[$b['name']] = $existing->id;
                } else {
                    $branchIds[$b['name']] = DB::table('branches')->insertGetId(
                        array_merge($row, ['created_at' => now()])
                    );
                }
            }

            // ----------------------------------------------------------------
            // 4. POS DEVICES
            // ----------------------------------------------------------------
            $posDevices = [
                // SM MoA — 3 units
                ['branch' => 'SM Mall of Asia',      'status' => 'active',      'model' => 'Epson TM-T82X',     'serial' => 'SN-POP-MOA-001', 'ip' => '192.168.10.101', 'os' => 'Windows 10 IoT', 'msa_start' => '2024-03-01', 'msa_end' => '2026-02-28', 'warranty_end' => '2026-03-01'],
                ['branch' => 'SM Mall of Asia',      'status' => 'active',      'model' => 'Epson TM-T82X',     'serial' => 'SN-POP-MOA-002', 'ip' => '192.168.10.102', 'os' => 'Windows 10 IoT', 'msa_start' => '2024-03-01', 'msa_end' => '2026-02-28', 'warranty_end' => '2026-03-01'],
                ['branch' => 'SM Mall of Asia',      'status' => 'maintenance', 'model' => 'Epson TM-T82X',     'serial' => 'SN-POP-MOA-003', 'ip' => '192.168.10.103', 'os' => 'Windows 10 IoT', 'msa_start' => '2024-03-01', 'msa_end' => '2026-02-28', 'warranty_end' => '2026-03-01'],
                // Robinsons Galleria — 2 units
                ['branch' => 'Robinsons Galleria',   'status' => 'active',      'model' => 'Bixolon SRP-350III', 'serial' => 'SN-POP-ROB-001', 'ip' => '10.0.2.51',      'os' => 'Windows 11',     'msa_start' => '2024-03-01', 'msa_end' => '2026-02-28', 'warranty_end' => '2025-12-01'],
                ['branch' => 'Robinsons Galleria',   'status' => 'offline',     'model' => 'Bixolon SRP-350III', 'serial' => 'SN-POP-ROB-002', 'ip' => '10.0.2.52',      'os' => 'Windows 11',     'msa_start' => '2024-03-01', 'msa_end' => '2026-02-28', 'warranty_end' => '2025-12-01'],
                // Trinoma — 2 units
                ['branch' => 'Trinoma',              'status' => 'active',      'model' => 'Star mPOP',          'serial' => 'SN-POP-TRI-001', 'ip' => '172.16.5.11',    'os' => 'Windows 10 IoT', 'msa_start' => '2024-06-01', 'msa_end' => '2026-05-31', 'warranty_end' => '2026-06-01'],
                ['branch' => 'Trinoma',              'status' => 'active',      'model' => 'Star mPOP',          'serial' => 'SN-POP-TRI-002', 'ip' => '172.16.5.12',    'os' => 'Windows 10 IoT', 'msa_start' => '2024-06-01', 'msa_end' => '2026-05-31', 'warranty_end' => '2026-06-01'],
            ];

            foreach ($posDevices as $d) {
                $existing = DB::table('pos_devices')
                    ->where('company_id', $companyId)
                    ->where('serial', $d['serial'])
                    ->first();
                $row = [
                    'company_id'   => $companyId,
                    'branch_id'    => $branchIds[$d['branch']] ?? null,
                    'status'       => $d['status'],
                    'model'        => $d['model'],
                    'serial'       => $d['serial'],
                    'ip_address'   => $d['ip'],
                    'os'           => $d['os'],
                    'msa_start'    => $d['msa_start'],
                    'msa_end'      => $d['msa_end'],
                    'warranty_end' => $d['warranty_end'],
                    'updated_at'   => now(),
                ];
                if ($existing) {
                    DB::table('pos_devices')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('pos_devices')->insert(array_merge($row, ['created_at' => now()]));
                }
            }

            // ----------------------------------------------------------------
            // 5. LICENSE
            // ----------------------------------------------------------------
            $licenseData = [
                'company_id'     => $companyId,
                'license_key'    => 'LIC-POP-2024-MNL1',
                'sa_start'       => '2024-03-01',
                'sa_end'         => '2026-02-28',
                'krunch_version' => 'v3.2.1',
                'updated_at'     => now(),
            ];

            $existingLic = DB::table('licenses')
                ->where('company_id', $companyId)
                ->where('license_key', 'LIC-POP-2024-MNL1')
                ->first();
            if ($existingLic) {
                DB::table('licenses')->where('id', $existingLic->id)->update($licenseData);
            } else {
                DB::table('licenses')->insert(array_merge($licenseData, ['created_at' => now()]));
            }

            // ----------------------------------------------------------------
            // 6. NOTIFICATIONS
            // ----------------------------------------------------------------
            $notifications = [
                [
                    'type'    => 'warning',
                    'title'   => 'MSA Expiring Soon',
                    'message' => 'Your Master Service Agreement expires on Feb 28, 2026. Please contact your account manager to arrange renewal.',
                    'read'    => false,
                ],
                [
                    'type'    => 'alert',
                    'title'   => 'Device Offline',
                    'message' => 'POS device SN-POP-ROB-002 at Robinsons Galleria has gone offline. Please check network connectivity.',
                    'read'    => false,
                ],
                [
                    'type'    => 'info',
                    'title'   => 'Maintenance Scheduled',
                    'message' => 'Unit SN-POP-MOA-003 at SM Mall of Asia is currently under scheduled maintenance. ETA: 24 hours.',
                    'read'    => false,
                ],
                [
                    'type'    => 'success',
                    'title'   => 'Welcome to the Portal',
                    'message' => 'Your Popeyes Manila portal account is active. You can now manage your branches, POS devices, and licenses from this dashboard.',
                    'read'    => true,
                ],
            ];

            foreach ($notifications as $n) {
                $exists = DB::table('notifications')
                    ->where('user_id', $userId)
                    ->where('title', $n['title'])
                    ->exists();
                if (!$exists) {
                    DB::table('notifications')->insert([
                        'user_id'    => $userId,
                        'company_id' => $companyId,
                        'type'       => $n['type'],
                        'title'      => $n['title'],
                        'message'    => $n['message'],
                        'read'       => $n['read'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $this->command->info("✅ Popeyes Manila seeded — company_id: {$companyId}, user_id: {$userId}");
            $this->command->info("   Branches : " . count($branchIds));
            $this->command->info("   POS      : " . count($posDevices));
            $this->command->info("   Login    : client.fnb@example.com / password");
        });
    }
}
