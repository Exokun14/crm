<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

/**
 * PortalFieldsSeeder
 *
 * Seeds all tables introduced by 2026_03_12_000001_add_portal_fields.php:
 *   companies (extended), users (extended), branches, pos_devices,
 *   licenses, tickets, notifications
 *
 * Run with:
 *   php artisan db:seed --class=PortalFieldsSeeder
 *
 * Idempotent: wrapped in a transaction; skips companies/users that
 * already exist by email / name to avoid duplicates on re-run.
 */
class PortalFieldsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // ----------------------------------------------------------------
            // 1. COMPANIES
            // ----------------------------------------------------------------
            $companies = [
                [
                    'name'              => 'Nike Philippines',
                    'store_name'        => 'Nike PH',
                    'contact_email'     => 'ops@nikeph.com',
                    'phone'             => '+63 2 8888 0001',
                    'contact_person'    => 'Maria Santos',
                    'alt_contact_person'=> 'Jose Reyes',
                    'alt_contact_email' => 'jose.reyes@nikeph.com',
                    'alt_contact_phone' => '+63 917 000 0001',
                    'account_manager'   => 'Renz Talentino',
                    'msa_start'         => '2024-01-01',
                    'msa_end'           => '2026-12-31',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ],
                [
                    'name'              => 'Jollibee Foods Corporation',
                    'store_name'        => 'Jollibee',
                    'contact_email'     => 'it@jollibee.com.ph',
                    'phone'             => '+63 2 8888 0002',
                    'contact_person'    => 'Ana Cruz',
                    'alt_contact_person'=> 'Carlo Dela Cruz',
                    'alt_contact_email' => 'carlo.delacruz@jollibee.com.ph',
                    'alt_contact_phone' => '+63 918 000 0002',
                    'account_manager'   => 'Patricia Lim',
                    'msa_start'         => '2023-06-01',
                    'msa_end'           => '2025-05-31',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ],
                [
                    'name'              => 'SM Retail Inc.',
                    'store_name'        => 'SM Supermarket',
                    'contact_email'     => 'support@smretail.com',
                    'phone'             => '+63 2 8888 0003',
                    'contact_person'    => 'Liza Tan',
                    'alt_contact_person'=> null,
                    'alt_contact_email' => null,
                    'alt_contact_phone' => null,
                    'account_manager'   => 'Marco Villanueva',
                    'msa_start'         => '2025-01-01',
                    'msa_end'           => '2027-12-31',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ],
            ];

            $companyIds = [];
            foreach ($companies as $data) {
                $existing = DB::table('companies')->where('name', $data['name'])->first();
                if ($existing) {
                    $companyIds[$data['name']] = $existing->id;
                    DB::table('companies')->where('id', $existing->id)->update(
                        collect($data)->except('name')->toArray()
                    );
                } else {
                    $companyIds[$data['name']] = DB::table('companies')->insertGetId($data);
                }
            }

            // ----------------------------------------------------------------
            // 2. USERS  (1 admin + 2 regular users per company)
            // ----------------------------------------------------------------
            $usersData = [
                // Global admin
                [
                    'name'       => 'Super Admin',
                    'email'      => 'admin@portal.test',
                    'password'   => Hash::make('password'),
                    'role'       => 'admin',
                    'industry'   => null,
                    'company_id' => null,
                    'phone'      => '+63 900 000 0000',
                    'position'   => 'System Administrator',
                    'status'     => 'active',
                ],
                // Nike users
                [
                    'name'       => 'Maria Santos',
                    'email'      => 'maria@nikeph.com',
                    'password'   => Hash::make('password'),
                    'role'       => 'user',
                    'industry'   => 'retail',
                    'company_id' => $companyIds['Nike Philippines'],
                    'phone'      => '+63 917 100 0001',
                    'position'   => 'IT Manager',
                    'status'     => 'active',
                ],
                [
                    'name'       => 'Jose Reyes',
                    'email'      => 'jose@nikeph.com',
                    'password'   => Hash::make('password'),
                    'role'       => 'user',
                    'industry'   => 'retail',
                    'company_id' => $companyIds['Nike Philippines'],
                    'phone'      => '+63 917 100 0002',
                    'position'   => 'Store Supervisor',
                    'status'     => 'active',
                ],
                // Jollibee users
                [
                    'name'       => 'Ana Cruz',
                    'email'      => 'ana@jollibee.com.ph',
                    'password'   => Hash::make('password'),
                    'role'       => 'user',
                    'industry'   => 'fnb',
                    'company_id' => $companyIds['Jollibee Foods Corporation'],
                    'phone'      => '+63 918 200 0001',
                    'position'   => 'Operations Lead',
                    'status'     => 'active',
                ],
                [
                    'name'       => 'Carlo Dela Cruz',
                    'email'      => 'carlo@jollibee.com.ph',
                    'password'   => Hash::make('password'),
                    'role'       => 'user',
                    'industry'   => 'fnb',
                    'company_id' => $companyIds['Jollibee Foods Corporation'],
                    'phone'      => '+63 918 200 0002',
                    'position'   => 'Branch Manager',
                    'status'     => 'inactive',
                ],
                // SM users
                [
                    'name'       => 'Liza Tan',
                    'email'      => 'liza@smretail.com',
                    'password'   => Hash::make('password'),
                    'role'       => 'user',
                    'industry'   => 'retail',
                    'company_id' => $companyIds['SM Retail Inc.'],
                    'phone'      => '+63 919 300 0001',
                    'position'   => 'Project Manager',
                    'status'     => 'active',
                ],
            ];

            $userIds = [];
            foreach ($usersData as $u) {
                $existing = DB::table('users')->where('email', $u['email'])->first();
                if ($existing) {
                    $userIds[$u['email']] = $existing->id;
                    DB::table('users')->where('id', $existing->id)->update(
                        collect($u)->except('email')->merge(['updated_at' => now()])->toArray()
                    );
                } else {
                    $userIds[$u['email']] = DB::table('users')->insertGetId(
                        array_merge($u, ['email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()])
                    );
                }
            }

            // ----------------------------------------------------------------
            // 3. BRANCHES
            // ----------------------------------------------------------------
            $branches = [
                // Nike
                ['company' => 'Nike Philippines',        'name' => 'SM Mall of Asia',    'site' => 'Nike - SM MoA, Pasay',         'seats' => 10, 'license_tag' => 'LIC-NKE-SMMOA-0601'],
                ['company' => 'Nike Philippines',        'name' => 'Greenbelt 5',         'site' => 'Nike - Greenbelt 5, Makati',   'seats' => 8,  'license_tag' => 'LIC-NKE-GB5-0601'],
                ['company' => 'Nike Philippines',        'name' => 'Trinoma',             'site' => 'Nike - Trinoma, QC',           'seats' => 6,  'license_tag' => 'LIC-NKE-TRI-0601'],
                // Jollibee
                ['company' => 'Jollibee Foods Corporation', 'name' => 'Ortigas Center',  'site' => 'Jollibee - Ortigas, Pasig',    'seats' => 15, 'license_tag' => 'LIC-JFC-ORT-0601'],
                ['company' => 'Jollibee Foods Corporation', 'name' => 'BGC High Street', 'site' => 'Jollibee - BGC, Taguig',       'seats' => 12, 'license_tag' => 'LIC-JFC-BGC-0601'],
                // SM
                ['company' => 'SM Retail Inc.',          'name' => 'SM North EDSA',       'site' => 'SM Supermarket - North EDSA',  'seats' => 20, 'license_tag' => 'LIC-SMR-NE-0601'],
                ['company' => 'SM Retail Inc.',          'name' => 'SM Aura',             'site' => 'SM Supermarket - Aura, BGC',   'seats' => 18, 'license_tag' => 'LIC-SMR-AUR-0601'],
            ];

            $branchIds = [];
            foreach ($branches as $b) {
                $cid = $companyIds[$b['company']];
                $existing = DB::table('branches')
                    ->where('company_id', $cid)
                    ->where('name', $b['name'])
                    ->first();
                $row = [
                    'company_id'  => $cid,
                    'name'        => $b['name'],
                    'site'        => $b['site'],
                    'seats'       => $b['seats'],
                    'license_tag' => $b['license_tag'],
                    'updated_at'  => now(),
                ];
                if ($existing) {
                    $branchIds[$b['company']][$b['name']] = $existing->id;
                    DB::table('branches')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = now();
                    $branchIds[$b['company']][$b['name']] = DB::table('branches')->insertGetId($row);
                }
            }

            // ----------------------------------------------------------------
            // 4. POS DEVICES
            // ----------------------------------------------------------------
            $posDevices = [
                // Nike - SM MoA
                ['company' => 'Nike Philippines', 'branch' => 'SM Mall of Asia',    'status' => 'active',      'model' => 'Epson TM-T82X',    'serial' => 'SN-NKE-001', 'ip' => '192.168.10.101', 'os' => 'Windows 10 IoT', 'msa_start' => '2024-01-01', 'msa_end' => '2026-12-31', 'warranty_end' => '2026-01-01'],
                ['company' => 'Nike Philippines', 'branch' => 'SM Mall of Asia',    'status' => 'maintenance', 'model' => 'Epson TM-T82X',    'serial' => 'SN-NKE-002', 'ip' => '192.168.10.102', 'os' => 'Windows 10 IoT', 'msa_start' => '2024-01-01', 'msa_end' => '2026-12-31', 'warranty_end' => '2026-01-01'],
                // Nike - Greenbelt
                ['company' => 'Nike Philippines', 'branch' => 'Greenbelt 5',        'status' => 'active',      'model' => 'Star mPOP',        'serial' => 'SN-NKE-003', 'ip' => '192.168.11.101', 'os' => 'Windows 11',     'msa_start' => '2024-06-01', 'msa_end' => '2026-05-31', 'warranty_end' => '2025-06-01'],
                // Jollibee - Ortigas
                ['company' => 'Jollibee Foods Corporation', 'branch' => 'Ortigas Center',  'status' => 'active', 'model' => 'Bixolon SRP-350III', 'serial' => 'SN-JFC-001', 'ip' => '10.0.1.50', 'os' => 'Windows 10 IoT', 'msa_start' => '2023-06-01', 'msa_end' => '2025-05-31', 'warranty_end' => '2025-06-01'],
                ['company' => 'Jollibee Foods Corporation', 'branch' => 'Ortigas Center',  'status' => 'offline','model' => 'Bixolon SRP-350III', 'serial' => 'SN-JFC-002', 'ip' => '10.0.1.51', 'os' => 'Windows 10 IoT', 'msa_start' => '2023-06-01', 'msa_end' => '2025-05-31', 'warranty_end' => '2025-06-01'],
                // SM - North EDSA
                ['company' => 'SM Retail Inc.',  'branch' => 'SM North EDSA',        'status' => 'active',      'model' => 'Epson TM-U220',    'serial' => 'SN-SMR-001', 'ip' => '172.16.0.10',    'os' => 'Windows 10',     'msa_start' => '2025-01-01', 'msa_end' => '2027-12-31', 'warranty_end' => '2028-01-01'],
                ['company' => 'SM Retail Inc.',  'branch' => 'SM North EDSA',        'status' => 'active',      'model' => 'Epson TM-U220',    'serial' => 'SN-SMR-002', 'ip' => '172.16.0.11',    'os' => 'Windows 10',     'msa_start' => '2025-01-01', 'msa_end' => '2027-12-31', 'warranty_end' => '2028-01-01'],
            ];

            foreach ($posDevices as $d) {
                $cid = $companyIds[$d['company']];
                $bid = $branchIds[$d['company']][$d['branch']] ?? null;
                $existing = DB::table('pos_devices')
                    ->where('company_id', $cid)
                    ->where('serial', $d['serial'])
                    ->first();
                $row = [
                    'company_id'   => $cid,
                    'branch_id'    => $bid,
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
                    $row['created_at'] = now();
                    DB::table('pos_devices')->insert($row);
                }
            }

            // ----------------------------------------------------------------
            // 5. LICENSES
            // ----------------------------------------------------------------
            $licenses = [
                ['company' => 'Nike Philippines',        'key' => 'LIC-NKE-2024-0601', 'sa_start' => '2024-01-01', 'sa_end' => '2026-12-31', 'version' => 'v3.2.1'],
                ['company' => 'Jollibee Foods Corporation', 'key' => 'LIC-JFC-2023-0601', 'sa_start' => '2023-06-01', 'sa_end' => '2025-05-31', 'version' => 'v3.1.4'],
                ['company' => 'SM Retail Inc.',          'key' => 'LIC-SMR-2025-0601', 'sa_start' => '2025-01-01', 'sa_end' => '2027-12-31', 'version' => 'v3.3.0'],
            ];

            foreach ($licenses as $l) {
                $cid = $companyIds[$l['company']];
                $existing = DB::table('licenses')
                    ->where('company_id', $cid)
                    ->where('license_key', $l['key'])
                    ->first();
                $row = [
                    'company_id'     => $cid,
                    'license_key'    => $l['key'],
                    'sa_start'       => $l['sa_start'],
                    'sa_end'         => $l['sa_end'],
                    'krunch_version' => $l['version'],
                    'updated_at'     => now(),
                ];
                if ($existing) {
                    DB::table('licenses')->where('id', $existing->id)->update($row);
                } else {
                    $row['created_at'] = now();
                    DB::table('licenses')->insert($row);
                }
            }

            // ----------------------------------------------------------------
            // 6. TICKETS
            // ----------------------------------------------------------------
            $tickets = [
                [
                    'company'     => 'Nike Philippines',
                    'branch'      => 'SM Mall of Asia',
                    'user_email'  => 'maria@nikeph.com',
                    'subject'     => 'POS terminal not printing receipts',
                    'description' => 'Unit SN-NKE-001 stopped printing after a Windows Update was applied last night.',
                    'status'      => 'open',
                    'priority'    => 'high',
                    'category'    => 'Hardware',
                ],
                [
                    'company'     => 'Nike Philippines',
                    'branch'      => 'Greenbelt 5',
                    'user_email'  => 'jose@nikeph.com',
                    'subject'     => 'Krunch version upgrade request',
                    'description' => 'Branch needs to be upgraded from v3.1.4 to v3.2.1.',
                    'status'      => 'pending',
                    'priority'    => 'normal',
                    'category'    => 'Software',
                ],
                [
                    'company'     => 'Jollibee Foods Corporation',
                    'branch'      => 'Ortigas Center',
                    'user_email'  => 'ana@jollibee.com.ph',
                    'subject'     => 'Offline POS device SN-JFC-002 not reconnecting',
                    'description' => 'Device has been offline for 3 days. Network team confirmed the port is live.',
                    'status'      => 'open',
                    'priority'    => 'critical',
                    'category'    => 'Connectivity',
                ],
                [
                    'company'     => 'Jollibee Foods Corporation',
                    'branch'      => 'BGC High Street',
                    'user_email'  => 'ana@jollibee.com.ph',
                    'subject'     => 'Request for additional user account',
                    'description' => 'New branch manager needs a portal account.',
                    'status'      => 'closed',
                    'priority'    => 'low',
                    'category'    => 'Account',
                ],
                [
                    'company'     => 'SM Retail Inc.',
                    'branch'      => 'SM North EDSA',
                    'user_email'  => 'liza@smretail.com',
                    'subject'     => 'MSA renewal inquiry',
                    'description' => 'Requesting details on the renewal terms for the upcoming MSA expiry.',
                    'status'      => 'pending',
                    'priority'    => 'normal',
                    'category'    => 'Licensing',
                ],
            ];

            foreach ($tickets as $t) {
                $cid = $companyIds[$t['company']];
                $bid = $branchIds[$t['company']][$t['branch']] ?? null;
                $uid = $userIds[$t['user_email']] ?? null;
                $existing = DB::table('tickets')
                    ->where('company_id', $cid)
                    ->where('subject', $t['subject'])
                    ->first();
                $row = [
                    'company_id'  => $cid,
                    'branch_id'   => $bid,
                    'user_id'     => $uid,
                    'subject'     => $t['subject'],
                    'description' => $t['description'],
                    'status'      => $t['status'],
                    'priority'    => $t['priority'],
                    'category'    => $t['category'],
                    'updated_at'  => now(),
                ];
                if (!$existing) {
                    $row['created_at'] = now();
                    DB::table('tickets')->insert($row);
                }
            }

            // ----------------------------------------------------------------
            // 7. NOTIFICATIONS
            // ----------------------------------------------------------------
            $notifications = [
                [
                    'user_email' => 'maria@nikeph.com',
                    'company'    => 'Nike Philippines',
                    'type'       => 'alert',
                    'title'      => 'POS Maintenance Alert',
                    'message'    => 'Unit SN-NKE-002 at SM Mall of Asia has been placed under maintenance.',
                    'read'       => false,
                ],
                [
                    'user_email' => 'ana@jollibee.com.ph',
                    'company'    => 'Jollibee Foods Corporation',
                    'type'       => 'warning',
                    'title'      => 'MSA Expiring Soon',
                    'message'    => 'Your Master Service Agreement is due to expire on 2025-05-31. Please contact your account manager.',
                    'read'       => false,
                ],
                [
                    'user_email' => 'ana@jollibee.com.ph',
                    'company'    => 'Jollibee Foods Corporation',
                    'type'       => 'alert',
                    'title'      => 'Device Offline',
                    'message'    => 'POS device SN-JFC-002 at Ortigas Center has been offline for more than 72 hours.',
                    'read'       => false,
                ],
                [
                    'user_email' => 'liza@smretail.com',
                    'company'    => 'SM Retail Inc.',
                    'type'       => 'info',
                    'title'      => 'Welcome to the Portal',
                    'message'    => 'Your SM Retail portal account is now active. Explore your dashboard for an overview of your services.',
                    'read'       => true,
                ],
                [
                    'user_email' => 'admin@portal.test',
                    'company'    => null,
                    'type'       => 'info',
                    'title'      => 'Seeder Complete',
                    'message'    => 'Portal seed data has been loaded successfully.',
                    'read'       => false,
                ],
            ];

            // Notifications are append-only; skip if exact message already exists for this user
            foreach ($notifications as $n) {
                $uid = $userIds[$n['user_email']] ?? null;
                $cid = $n['company'] ? ($companyIds[$n['company']] ?? null) : null;
                $exists = DB::table('notifications')
                    ->where('user_id', $uid)
                    ->where('title', $n['title'])
                    ->exists();
                if (!$exists) {
                    DB::table('notifications')->insert([
                        'user_id'    => $uid,
                        'company_id' => $cid,
                        'type'       => $n['type'],
                        'title'      => $n['title'],
                        'message'    => $n['message'],
                        'read'       => $n['read'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}
