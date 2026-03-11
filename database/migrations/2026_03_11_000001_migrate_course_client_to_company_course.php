<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('course_client')
            ->join('clients', 'clients.id', '=', 'course_client.client_id')
            ->select('course_client.course_id', 'clients.name')
            ->get();

        echo "Found {$rows->count()} rows in course_client\n";

        $migrated = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $company = DB::table('companies')
                ->where('name', $row->name)
                ->first();

            if ($company) {
                DB::table('company_course')->insertOrIgnore([
                    'course_id'   => $row->course_id,
                    'company_id'  => $company->id,
                    'assigned_at' => now(),
                ]);
                $migrated++;
            } else {
                echo "  SKIPPED — no company match for client name: '{$row->name}'\n";
                $skipped++;
            }
        }

        echo "Migrated: {$migrated}, Skipped: {$skipped}\n";
        echo "Total rows in company_course now: " . DB::table('company_course')->count() . "\n";
    }

    public function down(): void
    {
        // Remove rows that were copied from course_client
        $clientCourseIds = DB::table('course_client')->pluck('course_id');
        DB::table('company_course')->whereIn('course_id', $clientCourseIds)->delete();
    }
};
