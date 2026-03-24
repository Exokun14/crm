<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index()
    {
        $clients = DB::table('clients')->get();
        return response()->json($clients);
    }

    public function show($id)
    {
        $client = DB::table('clients')->where('id', $id)->first();
        
        if (!$client) {
            return response()->json(['error' => 'Client not found'], 404);
        }
        
        return response()->json($client);
    }

    public function courses($id)
    {
        $courses = DB::table('courses')
            ->join('course_client', 'courses.id', '=', 'course_client.course_id')
            ->where('course_client.client_id', $id)
            ->select('courses.*')
            ->get();

        return response()->json($courses);
    }
}
