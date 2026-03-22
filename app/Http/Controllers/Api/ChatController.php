<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ChatController
 *
 * POST /api/chat
 *   body: { message: string, history: [{role, content}] }
 *
 * Flow:
 *  1. Extract keywords from the user message
 *  2. Query relevant rows from courses, companies, users, progress
 *  3. Build a system prompt with that context
 *  4. Send full history + new message to Ollama (llama3.2)
 *  5. Save both turns to chat_messages
 *  6. Return the assistant reply
 */
class ChatController extends Controller
{
    // Ollama endpoint — change if your Ollama runs elsewhere
    // Ollama runs as a sibling Docker service — reachable by container name
    const OLLAMA_URL = 'http://ollama:11434/api/chat';
    const MODEL      = 'llama3.2';

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/chat
    // ─────────────────────────────────────────────────────────────────────────
    public function send(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
            'history.*.role'    => 'required|in:user,assistant',
            'history.*.content' => 'required|string',
        ]);

        $userId  = Auth::id(); // null if unauthenticated — handled gracefully below
        $message = trim($request->message);
        $history = $request->input('history', []);

        Log::channel('stderr')->info('[Chat] ▶ user_id=' . $userId . ' message=' . substr($message, 0, 80));

        // ── 1. Build DB context ───────────────────────────────────────────────
        $context = $this->buildContext($message, $userId);
        Log::channel('stderr')->info('[Chat] context length=' . strlen($context));

        // ── 2. Build messages array for Ollama ────────────────────────────────
        $systemPrompt = $this->buildSystemPrompt($context);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Append previous history (last 10 turns to keep context window sane)
        $trimmedHistory = array_slice($history, -10);
        foreach ($trimmedHistory as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        // Append the new user message
        $messages[] = ['role' => 'user', 'content' => $message];

        // ── 3. Call Ollama ────────────────────────────────────────────────────
        try {
            $response = Http::timeout(60)->post(self::OLLAMA_URL, [
                'model'    => self::MODEL,
                'stream'   => false,
                'messages' => $messages,
            ]);

            if (!$response->successful()) {
                Log::channel('stderr')->error('[Chat] Ollama error: ' . $response->status() . ' ' . $response->body());
                return response()->json(['error' => 'AI service unavailable. Please try again.'], 503);
            }

            $reply = $response->json('message.content') ?? 'Sorry, I could not generate a response.';
            Log::channel('stderr')->info('[Chat] ✅ reply length=' . strlen($reply));

        } catch (\Exception $e) {
            Log::channel('stderr')->error('[Chat] Ollama exception: ' . $e->getMessage());
            return response()->json(['error' => 'Could not reach AI service: ' . $e->getMessage()], 503);
        }

        // ── 4. Save chat history ──────────────────────────────────────────────
        if ($userId && $userId > 0) {
            DB::table('chat_messages')->insert([
                ['user_id' => $userId, 'role' => 'user',      'content' => $message, 'created_at' => now(), 'updated_at' => now()],
                ['user_id' => $userId, 'role' => 'assistant',  'content' => $reply,   'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        return response()->json(['reply' => $reply]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/chat/history
    // Returns the last 50 messages for the current user
    // ─────────────────────────────────────────────────────────────────────────
    public function history()
    {
        $userId = Auth::id();

        $messages = DB::table('chat_messages')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get(['role', 'content', 'created_at']);

        return response()->json($messages);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/chat/history
    // Clears chat history for the current user
    // ─────────────────────────────────────────────────────────────────────────
    public function clearHistory()
    {
        $userId = Auth::id();
        DB::table('chat_messages')->where('user_id', $userId)->delete();
        return response()->json(['message' => 'Chat history cleared.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Build DB context string based on keywords in the message
    // ─────────────────────────────────────────────────────────────────────────
    private function buildContext(string $message, ?int $userId): string
    {
        $msg   = strtolower($message);
        $parts = [];

        // ── Courses ───────────────────────────────────────────────────────────
        $courses = DB::table('courses')
            ->where(function ($q) use ($msg) {
                $q->where('title', 'like', '%' . $msg . '%')
                  ->orWhere('cat',   'like', '%' . $msg . '%')
                  ->orWhere('desc',  'like', '%' . $msg . '%');
            })
            ->orWhere('stage', 'published')
            ->select('id', 'title', 'cat', 'stage', 'time', 'desc')
            ->limit(8)
            ->get();

        if ($courses->isNotEmpty()) {
            $parts[] = "=== COURSES ===";
            foreach ($courses as $c) {
                $parts[] = "- [{$c->stage}] {$c->title} | Category: {$c->cat} | Duration: {$c->time}";
                if ($c->desc) $parts[] = "  Description: " . substr($c->desc, 0, 120);
            }
        }

        // ── Companies ─────────────────────────────────────────────────────────
        $companies = DB::table('companies')
            ->where(function ($q) use ($msg) {
                $q->where('name',     'like', '%' . $msg . '%')
                  ->orWhere('industry', 'like', '%' . $msg . '%');
            })
            ->orWhere('active', true)
            ->select('id', 'name', 'industry', 'active')
            ->limit(8)
            ->get();

        if ($companies->isNotEmpty()) {
            $parts[] = "\n=== COMPANIES ===";
            foreach ($companies as $c) {
                $status  = $c->active ? 'Active' : 'Inactive';
                $parts[] = "- {$c->name} | Industry: {$c->industry} | Status: {$status}";
            }
        }

        // ── User progress (only for the current user) ─────────────────────────
        if ($userId) {
            $progress = DB::table('user_course_progress')
                ->where('user_id', $userId)
                ->select('course', 'progress', 'status', 'completed', 'time_spent')
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get();

            if ($progress->isNotEmpty()) {
                $parts[] = "\n=== YOUR LEARNING PROGRESS ===";
                foreach ($progress as $p) {
                    $parts[] = "- {$p->course}: {$p->progress}% | Status: {$p->status}" . ($p->completed ? " (Completed)" : "");
                }
            }
        }

        // ── Tickets ───────────────────────────────────────────────────────────────
        $tickets = DB::table('tickets')
            ->leftJoin('companies', 'tickets.company_id', '=', 'companies.id')
            ->where(function ($q) use ($msg) {
                $q->where('tickets.subject',      'like', '%' . $msg . '%')
                  ->orWhere('tickets.description', 'like', '%' . $msg . '%')
                  ->orWhere('tickets.status',      'like', '%' . $msg . '%')
                  ->orWhere('tickets.priority',    'like', '%' . $msg . '%')
                  ->orWhere('tickets.category',    'like', '%' . $msg . '%')
                  ->orWhere('companies.name',      'like', '%' . $msg . '%');
            })
            ->select('tickets.id', 'tickets.subject', 'tickets.description', 'tickets.status', 'tickets.priority', 'tickets.category', 'tickets.created_at', 'companies.name as company_name')
            ->orderBy('tickets.created_at', 'desc')
            ->limit(8)
            ->get();

        if ($tickets->isNotEmpty()) {
            $parts[] = "\n=== SUPPORT TICKETS ===";
            foreach ($tickets as $t) {
                $parts[] = "- [{$t->status}] [{$t->priority}] {$t->subject} | Company: " . ($t->company_name ?? 'N/A') . " | Category: {$t->category}";
                if ($t->description) $parts[] = "  Detail: " . substr($t->description, 0, 120);
            }
        }

        // ── Platform stats ────────────────────────────────────────────────────────
        $userCount        = DB::table('users')->count();
        $adminCount       = DB::table('users')->where('role', 'admin')->count();
        $activeUsers      = DB::table('users')->where('status', 'active')->count();
        $publishedCourses = DB::table('courses')->where('stage', 'published')->count();
        $draftCourses     = DB::table('courses')->where('stage', 'draft')->count();
        $totalCompanies   = DB::table('companies')->where('active', true)->count();
        $openTickets      = DB::table('tickets')->where('status', 'open')->count();
        $highPriority     = DB::table('tickets')->whereIn('priority', ['high', 'critical'])->count();
        $closedTickets    = DB::table('tickets')->where('status', 'closed')->count();

        $parts[] = "\n=== PLATFORM STATS ===";
        $parts[] = "Total users: {$userCount} | Admins: {$adminCount} | Active: {$activeUsers}";
        $parts[] = "Published courses: {$publishedCourses} | Drafts: {$draftCourses} | Active companies: {$totalCompanies}";
        $parts[] = "Open tickets: {$openTickets} | High/Critical priority: {$highPriority} | Closed: {$closedTickets}";

        return implode("\n", $parts);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Build the system prompt
    // ─────────────────────────────────────────────────────────────────────────
    private function buildSystemPrompt(string $context): string
    {
        return <<<PROMPT
You are GeniX Assistant, a helpful AI built into the GeniX learning management platform.
You help admins, managers, and learners understand course content, track progress, and manage companies.

Be concise, friendly, and professional. Use bullet points for lists. Keep responses under 200 words unless more detail is specifically requested.

Here is the current data from the platform that is relevant to this conversation:

{$context}

If asked about something not in the data above, say you don't have that information available right now.
Do not make up data. If asked to do something outside your scope (e.g. delete records, change passwords), politely decline and explain you are read-only.
PROMPT;
    }
}
