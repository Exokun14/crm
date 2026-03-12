<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TicketSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tickets')->truncate();

        $now = Carbon::now();

        $tickets = [
            // ── OPEN ──────────────────────────────────────────────────────────
            [
                'company_id'  => 1,
                'branch_id'   => 1,
                'user_id'     => 3,
                'subject'     => 'POS terminal not responding',
                'description' => 'The main counter POS has been freezing intermittently since this morning. Requires a hard reboot every 30 minutes.',
                'status'      => 'open',
                'priority'    => 'high',
                'category'    => 'Hardware',
                'created_at'  => $now->copy()->subDays(1),
                'updated_at'  => $now->copy()->subDays(1),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 2,
                'user_id'     => 4,
                'subject'     => 'Network connectivity issues',
                'description' => 'Branch 2 intermittently loses connection to the central server, causing transaction timeouts.',
                'status'      => 'open',
                'priority'    => 'high',
                'category'    => 'Network',
                'created_at'  => $now->copy()->subDays(2),
                'updated_at'  => $now->copy()->subDays(2),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 1,
                'user_id'     => 3,
                'subject'     => 'Card reader malfunction',
                'description' => 'The card reader on counter 3 is not detecting contactless payments. Chip and swipe still work.',
                'status'      => 'open',
                'priority'    => 'medium',
                'category'    => 'Hardware',
                'created_at'  => $now->copy()->subDays(3),
                'updated_at'  => $now->copy()->subDays(3),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 3,
                'user_id'     => null,
                'subject'     => 'Software update failures',
                'description' => 'Krunch POS v3.2.1 fails to install on two terminals. Error code: UPDATE_ROLLBACK_001.',
                'status'      => 'open',
                'priority'    => 'medium',
                'category'    => 'Software',
                'created_at'  => $now->copy()->subDays(4),
                'updated_at'  => $now->copy()->subDays(4),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => null,
                'user_id'     => 4,
                'subject'     => 'Receipt printer jam on counter 1',
                'description' => 'Paper gets jammed after every 10-15 receipts. Tried cleaning the roller but issue persists.',
                'status'      => 'open',
                'priority'    => 'low',
                'category'    => 'Hardware',
                'created_at'  => $now->copy()->subDays(5),
                'updated_at'  => $now->copy()->subDays(5),
            ],

            // ── PENDING / IN PROGRESS ─────────────────────────────────────────
            [
                'company_id'  => 1,
                'branch_id'   => 1,
                'user_id'     => 3,
                'subject'     => 'POS display flickering',
                'description' => 'Customer-facing display flickers when rendering large order queues. Suspected GPU driver issue.',
                'status'      => 'pending',
                'priority'    => 'medium',
                'category'    => 'Hardware',
                'created_at'  => $now->copy()->subDays(6),
                'updated_at'  => $now->copy()->subDays(1),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 2,
                'user_id'     => null,
                'subject'     => 'Discount module not applying correctly',
                'description' => 'Senior citizen discounts are not being deducted from the VAT-inclusive total as required by law.',
                'status'      => 'pending',
                'priority'    => 'high',
                'category'    => 'Software',
                'created_at'  => $now->copy()->subDays(8),
                'updated_at'  => $now->copy()->subDays(2),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 3,
                'user_id'     => 4,
                'subject'     => 'Router firmware upgrade needed',
                'description' => 'IT flagged that branch router firmware is 2 major versions behind. Scheduling a maintenance window.',
                'status'      => 'pending',
                'priority'    => 'low',
                'category'    => 'Network',
                'created_at'  => $now->copy()->subDays(10),
                'updated_at'  => $now->copy()->subDays(3),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 1,
                'user_id'     => 3,
                'subject'     => 'Inventory sync delay',
                'description' => 'Real-time inventory counts are lagging by 15-20 minutes, causing over-selling of limited items.',
                'status'      => 'pending',
                'priority'    => 'medium',
                'category'    => 'Software',
                'created_at'  => $now->copy()->subDays(12),
                'updated_at'  => $now->copy()->subDays(4),
            ],

            // ── CLOSED / RESOLVED ─────────────────────────────────────────────
            [
                'company_id'  => 1,
                'branch_id'   => 2,
                'user_id'     => 4,
                'subject'     => 'End-of-day report not generating',
                'description' => 'EOD report failed to generate on 2025-02-28. Root cause: disk space exhausted. Cleared and resolved.',
                'status'      => 'closed',
                'priority'    => 'high',
                'category'    => 'Software',
                'created_at'  => $now->copy()->subDays(30),
                'updated_at'  => $now->copy()->subDays(28),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 1,
                'user_id'     => 3,
                'subject'     => 'Thermal paper stock mismatch',
                'description' => 'Wrong paper width ordered. Replaced with correct 80mm rolls. Ticket closed.',
                'status'      => 'closed',
                'priority'    => 'low',
                'category'    => 'Other',
                'created_at'  => $now->copy()->subDays(25),
                'updated_at'  => $now->copy()->subDays(20),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 3,
                'user_id'     => null,
                'subject'     => 'UPS battery replacement',
                'description' => 'UPS unit at Branch 3 was showing low battery health. Replaced battery unit on-site.',
                'status'      => 'closed',
                'priority'    => 'medium',
                'category'    => 'Hardware',
                'created_at'  => $now->copy()->subDays(20),
                'updated_at'  => $now->copy()->subDays(15),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 2,
                'user_id'     => 4,
                'subject'     => 'VPN tunnel dropping every night',
                'description' => 'Nightly VPN drops were caused by ISP maintenance window. Rescheduled sync jobs to avoid the window.',
                'status'      => 'closed',
                'priority'    => 'medium',
                'category'    => 'Network',
                'created_at'  => $now->copy()->subDays(18),
                'updated_at'  => $now->copy()->subDays(14),
            ],
            [
                'company_id'  => 1,
                'branch_id'   => 1,
                'user_id'     => 3,
                'subject'     => 'POS clock out of sync',
                'description' => 'Terminals were showing incorrect timestamps. Re-enabled NTP sync and confirmed accurate time across all units.',
                'status'      => 'closed',
                'priority'    => 'low',
                'category'    => 'Software',
                'created_at'  => $now->copy()->subDays(15),
                'updated_at'  => $now->copy()->subDays(12),
            ],
        ];

        DB::table('tickets')->insert($tickets);
        $this->command->info('✅ Tickets seeded (' . count($tickets) . ' rows)');
    }
}
