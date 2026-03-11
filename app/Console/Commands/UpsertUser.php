<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Company;

class UpsertUser extends Command
{
    protected $signature = 'user:upsert';

    protected $description = 'Create or update a user interactively';

    public function handle(): void
    {
        $this->info('');
        $this->info('━━━  User Manager  ━━━');
        $this->info('');

        // ── Email ─────────────────────────────────────────────────────────────
        $email = $this->ask('Email address');

        $existing = User::where('email', $email)->first();
        if ($existing) {
            $this->warn("User already exists: {$existing->name} (role: {$existing->role})");
            if (! $this->confirm('Update this user?', true)) {
                $this->info('Cancelled.');
                return;
            }
        }

        // ── Name ──────────────────────────────────────────────────────────────
        $name = $this->ask('Full name', $existing?->name);

        // ── Role ──────────────────────────────────────────────────────────────
        $role = $this->choice('Role', ['admin', 'user'], $existing?->role === 'admin' ? 0 : 1);

        // ── Industry ──────────────────────────────────────────────────────────
        $industry = $this->choice(
            'Industry',
            ['fnb', 'retail', 'warehouse', 'none'],
            match ($existing?->industry) {
                'fnb'       => 0,
                'retail'    => 1,
                'warehouse' => 2,
                default     => 3,
            }
        );
        $industry = $industry === 'none' ? null : $industry;

        // ── Company (only for non-admin users) ────────────────────────────────
        $companyId = $existing?->company_id;

        if ($role === 'user') {
            $companies = Company::all(['id', 'name']);

            if ($companies->isEmpty()) {
                $this->warn('No companies found. Run php artisan db:seed to create sample companies, or create one first.');
            } else {
                $companyOptions = $companies->pluck('name')->prepend('(none)')->toArray();
                $currentIndex   = $companies->search(fn($c) => $c->id === $companyId);
                $default        = $currentIndex !== false ? $currentIndex + 1 : 0; // +1 because (none) is at index 0

                $chosen    = $this->choice('Assign to company', $companyOptions, $default);
                $companyId = $chosen === '(none)'
                    ? null
                    : $companies->firstWhere('name', $chosen)?->id;
            }
        } else {
            // Admins have no company
            $companyId = null;
        }

        // ── Password ──────────────────────────────────────────────────────────
        $changePassword = $existing
            ? $this->confirm('Change password?', false)
            : true;

        $password = null;
        if ($changePassword) {
            $password = $this->secret('Password (leave blank for "password")') ?: 'password';
        }

        // ── Save ──────────────────────────────────────────────────────────────
        $data = [
            'name'       => $name,
            'role'       => $role,
            'industry'   => $industry,
            'company_id' => $companyId,
        ];

        if ($password) {
            $data['password'] = Hash::make($password);
        }

        $user = User::updateOrCreate(['email' => $email], $data);

        $this->info('');
        $this->info('✅  User saved successfully!');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID',       $user->id],
                ['Name',     $user->name],
                ['Email',    $user->email],
                ['Role',     $user->role],
                ['Industry', $user->industry ?? '—'],
                ['Company',  $user->company?->name ?? '—'],
            ]
        );
    }
}
