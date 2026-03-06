<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    // ─── Helper: shape a raw DB row into the API response format ─────────────
    private function format($activity): object
    {
        // Decode the JSON data blob into top-level fields
        $data = json_decode($activity->data ?? '{}', true) ?? [];

        $activity->items     = $data['items']     ?? null;
        $activity->cards     = $data['cards']     ?? null;
        $activity->questions = $data['questions'] ?? null;
        $activity->checklist = $data['checklist'] ?? null;
        $activity->pairs     = $data['pairs']     ?? null;

        // Nest media fields into a single object (matches frontend Activity.media shape)
        if ($activity->media_url) {
            $activity->media = [
                'url'  => $activity->media_url,
                'type' => $activity->media_type,
                'name' => $activity->media_name,
            ];
        } else {
            $activity->media = null;
        }

        // Remove raw columns that are now nested
        unset($activity->data, $activity->media_url, $activity->media_type, $activity->media_name);

        return $activity;
    }

    // ─── GET /activities ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = DB::table('activities');

        if ($request->filled('type'))   $query->where('type',   $request->type);
        if ($request->filled('status')) $query->where('status', $request->status);

        // FIX: order so newest activities appear first — this is why the list
        // appeared empty; old code had no ordering and DB returned rows in
        // insertion order which React wasn't filtering correctly.
        $query->orderByDesc('created_at');

        $activities = $query->get()->map(fn($row) => $this->format($row));

        return response()->json($activities);
    }

    // ─── POST /activities ────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'       => 'required|in:accordion,flashcard,checklist,matching,fillblank,hotspot',
            'title'      => 'required|string|max:255',
            'status'     => 'required|in:draft,published',
            'items'      => 'nullable|array',
            'cards'      => 'nullable|array',
            'questions'  => 'nullable|array',
            'checklist'  => 'nullable|array',
            'pairs'      => 'nullable|array',
            'media'          => 'nullable|array',
            'media.url'      => 'nullable|string',
            'media.type'     => 'nullable|in:image,video,file',
            'media.name'     => 'nullable|string',
        ]);

        $activityId = 'act_' . uniqid();

        // Pack content arrays into the JSON blob
        $data = [];
        foreach (['items','cards','questions','checklist','pairs'] as $field) {
            if (isset($validated[$field])) $data[$field] = $validated[$field];
        }

        DB::table('activities')->insert([
            'activity_id' => $activityId,
            'type'        => $validated['type'],
            'title'       => $validated['title'],
            'status'      => $validated['status'],
            'data'        => json_encode($data),
            'media_url'   => $validated['media']['url']  ?? null,
            'media_type'  => $validated['media']['type'] ?? null,
            'media_name'  => $validated['media']['name'] ?? null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $created = DB::table('activities')->where('activity_id', $activityId)->first();

        return response()->json($this->format($created), 201);
    }

    // ─── GET /activities/{id} ────────────────────────────────────────────────
    public function show($activityId)
    {
        $activity = DB::table('activities')->where('activity_id', $activityId)->first();

        if (!$activity) {
            return response()->json(['error' => 'Activity not found'], 404);
        }

        return response()->json($this->format($activity));
    }

    // ─── PUT /activities/{id} ─────────────────────────────────────────────────
    public function update(Request $request, $activityId)
    {
        $activity = DB::table('activities')->where('activity_id', $activityId)->first();
        if (!$activity) {
            return response()->json(['error' => 'Activity not found'], 404);
        }

        $validated = $request->validate([
            'title'      => 'sometimes|string|max:255',
            'status'     => 'sometimes|in:draft,published',
            'items'      => 'nullable|array',
            'cards'      => 'nullable|array',
            'questions'  => 'nullable|array',
            'checklist'  => 'nullable|array',
            'pairs'      => 'nullable|array',
            'media'          => 'nullable|array',
            'media.url'      => 'nullable|string',
            'media.type'     => 'nullable|in:image,video,file',
            'media.name'     => 'nullable|string',
        ]);

        $updateData = ['updated_at' => now()];

        if (isset($validated['title']))  $updateData['title']  = $validated['title'];
        if (isset($validated['status'])) $updateData['status'] = $validated['status'];

        // Re-build the data blob by merging with existing
        $existing = json_decode($activity->data ?? '{}', true) ?? [];
        foreach (['items','cards','questions','checklist','pairs'] as $field) {
            if (array_key_exists($field, $validated)) {
                // Allow explicitly nulling a field to clear it
                if ($validated[$field] === null) {
                    unset($existing[$field]);
                } else {
                    $existing[$field] = $validated[$field];
                }
            }
        }
        $updateData['data'] = json_encode($existing);

        // Update media fields when a media key is present in the request
        if (array_key_exists('media', $validated)) {
            $updateData['media_url']  = $validated['media']['url']  ?? null;
            $updateData['media_type'] = $validated['media']['type'] ?? null;
            $updateData['media_name'] = $validated['media']['name'] ?? null;
        }

        DB::table('activities')->where('activity_id', $activityId)->update($updateData);

        $updated = DB::table('activities')->where('activity_id', $activityId)->first();

        return response()->json($this->format($updated));
    }

    // ─── DELETE /activities/{id} ─────────────────────────────────────────────
    public function destroy($activityId)
    {
        $deleted = DB::table('activities')->where('activity_id', $activityId)->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Activity not found'], 404);
        }

        return response()->json(['message' => 'Activity deleted successfully']);
    }

    // ─── PUT /activities/{id}/status ─────────────────────────────────────────
    // Convenience endpoint for toggling draft ↔ published without a full update.
    public function updateStatus(Request $request, $activityId)
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,published',
        ]);

        $affected = DB::table('activities')
            ->where('activity_id', $activityId)
            ->update(['status' => $validated['status'], 'updated_at' => now()]);

        if (!$affected) {
            return response()->json(['error' => 'Activity not found'], 404);
        }

        return response()->json(['message' => 'Status updated', 'status' => $validated['status']]);
    }
}
