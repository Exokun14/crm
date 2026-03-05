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
            'progress' => 'sometimes|integer|min:0|max:100',
            'completed' => 'nullable|date',
            'status' => 'sometimes|in:Not Started,In Progress,Completed',
            'time_spent' => 'nullable|integer',
            'assessment_score' => 'nullable|integer',
        ]);

        DB::table('user_course_progress')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        return response()->json(['message' => 'Progress updated']);
    }
}
