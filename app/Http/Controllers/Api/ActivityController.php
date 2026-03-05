<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('activities');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $activities = $query->get()->map(function ($activity) {
            $activity->data = json_decode($activity->data, true);
            
            // Include media if present
            if ($activity->media_url) {
                $activity->media = [
                    'url' => $activity->media_url,
                    'type' => $activity->media_type,
                    'name' => $activity->media_name,
                ];
            }
            
            return $activity;
        });

        return response()->json($activities);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:accordion,flashcard,checklist,matching,fillblank,hotspot',
            'title' => 'required|string|max:255',
            'status' => 'required|in:draft,published',
            'items' => 'nullable|array',
            'cards' => 'nullable|array',
            'questions' => 'nullable|array',
            'checklist' => 'nullable|array',
            'pairs' => 'nullable|array',
            'media' => 'nullable|array',
            'media.url' => 'nullable|string',
            'media.type' => 'nullable|in:image,video,file',
            'media.name' => 'nullable|string',
        ]);

        $activityId = 'act_' . uniqid();

        $data = [];
        if (isset($validated['items'])) $data['items'] = $validated['items'];
        if (isset($validated['cards'])) $data['cards'] = $validated['cards'];
        if (isset($validated['questions'])) $data['questions'] = $validated['questions'];
        if (isset($validated['checklist'])) $data['checklist'] = $validated['checklist'];
        if (isset($validated['pairs'])) $data['pairs'] = $validated['pairs'];

        DB::table('activities')->insert([
            'activity_id' => $activityId,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'status' => $validated['status'],
            'data' => json_encode($data),
            'media_url' => $validated['media']['url'] ?? null,
            'media_type' => $validated['media']['type'] ?? null,
            'media_name' => $validated['media']['name'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['activity_id' => $activityId, 'message' => 'Activity created successfully'], 201);
    }

    public function show($activityId)
    {
        $activity = DB::table('activities')->where('activity_id', $activityId)->first();

        if (!$activity) {
            return response()->json(['error' => 'Activity not found'], 404);
        }

        $activity->data = json_decode($activity->data, true);
        
        if ($activity->media_url) {
            $activity->media = [
                'url' => $activity->media_url,
                'type' => $activity->media_type,
                'name' => $activity->media_name,
            ];
        }

        return response()->json($activity);
    }

    public function update(Request $request, $activityId)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:draft,published',
            'items' => 'nullable|array',
            'cards' => 'nullable|array',
            'questions' => 'nullable|array',
            'checklist' => 'nullable|array',
            'pairs' => 'nullable|array',
            'media' => 'nullable|array',
        ]);

        $data = [];
        if (isset($validated['items'])) $data['items'] = $validated['items'];
        if (isset($validated['cards'])) $data['cards'] = $validated['cards'];
        if (isset($validated['questions'])) $data['questions'] = $validated['questions'];
        if (isset($validated['checklist'])) $data['checklist'] = $validated['checklist'];
        if (isset($validated['pairs'])) $data['pairs'] = $validated['pairs'];

        $updateData = [
            'updated_at' => now(),
        ];

        if (isset($validated['title'])) $updateData['title'] = $validated['title'];
        if (isset($validated['status'])) $updateData['status'] = $validated['status'];
        if (!empty($data)) $updateData['data'] = json_encode($data);
        
        if (isset($validated['media'])) {
            $updateData['media_url'] = $validated['media']['url'] ?? null;
            $updateData['media_type'] = $validated['media']['type'] ?? null;
            $updateData['media_name'] = $validated['media']['name'] ?? null;
        }

        DB::table('activities')->where('activity_id', $activityId)->update($updateData);

        return response()->json(['message' => 'Activity updated successfully']);
    }

    public function destroy($activityId)
    {
        DB::table('activities')->where('activity_id', $activityId)->delete();
        return response()->json(['message' => 'Activity deleted successfully']);
    }
}
