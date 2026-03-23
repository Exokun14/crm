<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    // GET /clients
    // FIX: queries 'company' table (not 'clients' — that table doesn't exist).
    // Returns rows shaped to match the frontend's Company interface:
    //   { id, company_name, industry_type: { title, color, icon } }
    public function index()
    {
        $clients = DB::table('company')
            ->leftJoin('industry_cards', 'company.industry_type', '=', 'industry_cards.id')
            ->select(
                'company.id',
                'company.company_name',
                'company.company_logo',
                'company.contact_person',
                'company.email',
                'industry_cards.title  as industry',
                'industry_cards.color  as industry_color',
                'industry_cards.icon   as industry_icon'
            )
            ->get();

        return response()->json($clients);
    }

    // GET /clients/{id}
    public function show($id)
    {
        $client = DB::table('company')
            ->leftJoin('industry_cards', 'company.industry_type', '=', 'industry_cards.id')
            ->where('company.id', $id)
            ->select(
                'company.*',
                'industry_cards.title as industry',
                'industry_cards.color as industry_color',
                'industry_cards.icon  as industry_icon'
            )
            ->first();

        if (!$client) {
            return response()->json(['error' => 'Client not found'], 404);
        }

        return response()->json($client);
    }

    // GET /clients/{id}/courses
    // FIX: uses 'company_course' pivot (matches CourseController) not 'course_client'
    public function courses($id)
    {
        $courses = DB::table('courses')
            ->join('company_course', 'courses.id', '=', 'company_course.course_id')
            ->where('company_course.company_id', $id)
            ->select('courses.*')
            ->get();

        return response()->json($courses);
    }
}
