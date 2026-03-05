<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('user_course_progress');

        if ($request->has('company')) {
            $query->where('company', $request->company);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'company' => 'required|string',
            'course' => 'required|string',
            'progress' => 'required|integer|min:0|max:100',
            'started' => 'required|date',
            'completed' => 'nullable|date',
            'status' => 'required|in:Not Started,In Progress,Completed',
            'time_spent' => 'nullable|integer',
            'assessment_score' => 'nullable|integer',
        ]);

        $id = DB::table('user_course_progress')->insertGetId(array_merge($validated, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['id' => $id, 'message' => 'Progress created'], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'progress'         => 'sometimes|integer|min:0|max:100',
            'completed'        => 'nullable|date',
            'status'           => 'sometimes|in:Not Started,In Progress,Completed',
            'time_spent'       => 'nullable|integer|min:0',
            'assessment_score' => 'nullable|integer',
        ]);

        $existing = DB::table('user_course_progress')->where('id', $id)->first();

        if (!$existing) {
            return response()->json(['error' => 'Progress record not found'], 404);
        }

        $updateData = ['updated_at' => now()];

        if (isset($validated['progress']))         $updateData['progress']         = $validated['progress'];
        if (isset($validated['completed']))        $updateData['completed']        = $validated['completed'];
        if (isset($validated['status']))           $updateData['status']           = $validated['status'];
        if (isset($validated['assessment_score'])) $updateData['assessment_score'] = $validated['assessment_score'];

        // Accumulate time_spent — never overwrite with a smaller value
        if (isset($validated['time_spent'])) {
            $delta       = max(0, (int) $validated['time_spent']);
            $currentTime = max(0, (int) ($existing->time_spent ?? 0));
            $updateData['time_spent'] = $currentTime + min($delta, 120);
        }

        DB::table('user_course_progress')
            ->where('id', $id)
            ->update($updateData);

        return response()->json(['message' => 'Progress updated']);
    }
}
