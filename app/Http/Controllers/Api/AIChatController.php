<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AIChatController
 *
 * POST   /api/ai/chat          — send a message, get AI reply
 * GET    /api/ai/chat/history  — load persisted history
 * POST   /api/ai/chat/clear    — soft-archive history
 *
 * Config:  config/ai_config.php
 * Training data: storage/ai_training/*.txt or *.md
 */
class AIChatController extends Controller
{
    private string $ollamaUrl;
    private string $model;
    private array  $config;

    public function __construct()
    {
        $this->ollamaUrl = rtrim(env('OLLAMA_HOST', 'http://localhost:11434'), '/');
        $this->model     = env('OLLAMA_MODEL', 'llama3.2');
        $this->config    = config('ai_config');
    }

    // ── POST /api/ai/chat ─────────────────────────────────────────────────────
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message'    => 'required|string|max:2000',
            'session_id' => 'sometimes|string|max:36',
        ]);

        $userId      = (int) $request->header('X-User-Id', 0);
        $accessLevel = $request->header('X-Access-Level', 'user');
        $sessionId   = $validated['session_id'] ?? null;

        Log::info('[AIChat] ▶ New message', [
            'user_id'      => $userId,
            'access_level' => $accessLevel,
            'session_id'   => $sessionId,
            'message'      => substr($validated['message'], 0, 100),
        ]);

        if (!$userId) {
            Log::warning('[AIChat] ❌ Unauthorized — no user ID');
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Persist user message
        $this->saveMessage($userId, 'user', $validated['message'], $sessionId);

        // Load recent non-archived history
        $history = DB::table('ai_chat_messages')
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->orderBy('created_at', 'asc')
            ->limit(20)
            ->get(['role', 'content']);

        Log::info('[AIChat] 📜 History loaded', ['message_count' => $history->count()]);

        // Build context from DB
        Log::info('[AIChat] 🔍 Building user context from DB...');
        $context = $this->buildContext($userId, $accessLevel);
        Log::info('[AIChat] ✅ Context built', [
            'type'             => $context['type'],
            'progress_courses' => isset($context['progress']) ? $context['progress']->count() : 'n/a',
            'available_courses'=> isset($context['available']) ? $context['available']->count() : 'n/a',
        ]);

        // Load training data from files
        Log::info('[AIChat] 📂 Loading training data files...');
        $trainingData = $this->loadTrainingData();
        Log::info('[AIChat] 📂 Training data loaded', ['chars' => strlen($trainingData)]);

        // Build system prompt
        $systemPrompt = $this->buildSystemPrompt($context, $trainingData);
        Log::info('[AIChat] 📝 System prompt built', ['chars' => strlen($systemPrompt)]);

        // Build messages array
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h->role, 'content' => $h->content];
        }

        Log::info('[AIChat] 🤖 Calling Ollama...', [
            'model'       => $this->model,
            'url'         => $this->ollamaUrl,
            'msg_count'   => count($messages),
            'num_predict' => $this->config['num_predict'] ?? 350,
        ]);

        $startTime = microtime(true);

        try {
            $reply = $this->callOllama($messages);
            $elapsed = round(microtime(true) - $startTime, 2);
            Log::info('[AIChat] ✅ Ollama replied', [
                'elapsed_seconds' => $elapsed,
                'reply_length'    => strlen($reply),
                'reply_preview'   => substr($reply, 0, 100),
            ]);
        } catch (\Exception $e) {
            $elapsed = round(microtime(true) - $startTime, 2);
            Log::error('[AIChat] ❌ Ollama error', [
                'error'           => $e->getMessage(),
                'elapsed_seconds' => $elapsed,
            ]);
            return response()->json(['error' => 'AI service unavailable. Please try again.'], 503);
        }

        // Persist assistant reply
        $this->saveMessage($userId, 'assistant', $reply, $sessionId);

        return response()->json([
            'reply'  => $reply,
            '_debug' => [
                'elapsed_seconds'  => $elapsed,
                'reply_chars'      => strlen($reply),
                'history_messages' => $history->count(),
                'context_type'     => $context['type'],
                'training_chars'   => strlen($trainingData),
                'prompt_chars'     => strlen($systemPrompt),
                'model'            => $this->model,
            ],
        ]);
    }

    // ── GET /api/ai/chat/history ──────────────────────────────────────────────
    public function history(Request $request)
    {
        $userId    = (int) $request->header('X-User-Id', 0);
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $sessionId = $request->query('session_id');

        $query = DB::table('ai_chat_messages')
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->orderBy('created_at', 'asc');

        if ($sessionId) {
            $query->where('session_id', $sessionId);
        }

        $messages = $query->get(['id', 'role', 'content', 'session_id', 'created_at']);

        Log::info('[AIChat] 📜 History fetched', [
            'user_id' => $userId,
            'count'   => $messages->count(),
        ]);

        return response()->json(['success' => true, 'data' => $messages]);
    }

    // ── POST /api/ai/chat/clear ───────────────────────────────────────────────
    public function clear(Request $request)
    {
        $userId = (int) $request->header('X-User-Id', 0);
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $archived = DB::table('ai_chat_messages')
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);

        Log::info('[AIChat] 🗑️ History archived', [
            'user_id'         => $userId,
            'messages_archived' => $archived,
        ]);

        return response()->json(['success' => true, 'message' => 'Chat history cleared.']);
    }

    // ── Training data loader ──────────────────────────────────────────────────

    private function loadTrainingData(): string
    {
        $path    = $this->config['training_data_path'] ?? storage_path('ai_training');
        $maxChars = $this->config['training_data_max_chars'] ?? 4000;

        if (!is_dir($path)) {
            Log::warning('[AIChat] 📂 Training data folder not found', ['path' => $path]);
            return '';
        }

        $files   = glob("{$path}/*.{txt,md}", GLOB_BRACE);
        $content = '';

        foreach ($files as $file) {
            $filename = basename($file);
            $text     = file_get_contents($file);
            if ($text === false) {
                Log::warning('[AIChat] ⚠️ Could not read training file', ['file' => $filename]);
                continue;
            }
            Log::info('[AIChat] 📄 Loaded training file', [
                'file'  => $filename,
                'chars' => strlen($text),
            ]);
            $content .= "\n\n--- {$filename} ---\n" . $text;
            if (strlen($content) >= $maxChars) break;
        }

        return substr($content, 0, $maxChars);
    }

    // ── Context builders ──────────────────────────────────────────────────────

    private function buildContext(int $userId, string $accessLevel): array
    {
        $isManager = in_array($accessLevel, ['manager', 'admin', 'super_admin', 'system_admin']);
        return $isManager
            ? $this->buildManagerContext($userId)
            : $this->buildUserContext($userId);
    }

    private function buildUserContext(int $userId): array
    {
        $user = DB::table('users as u')
            ->leftJoin('company as c', 'c.id', '=', 'u.company_id')
            ->where('u.id', $userId)
            ->select('u.full_name', 'u.position_title', 'c.company_name')
            ->first();

        $progress = DB::table('user_course_progress as ucp')
            ->join('courses as co', 'co.id', '=', 'ucp.course_id')
            ->where('ucp.user_id', $userId)
            ->select('co.title', 'co.cat', 'ucp.progress', 'ucp.status', 'ucp.completed', 'ucp.time_spent')
            ->get();

        $startedTitles = $progress->pluck('title')->toArray();
        $available = DB::table('courses')
            ->where('stage', 'published')->where('active', 1)
            ->whereNotIn('title', $startedTitles)
            ->select('title', 'cat', 'time')
            ->limit(10)->get();

        return ['type' => 'user', 'user' => $user, 'progress' => $progress, 'available' => $available];
    }

    private function buildManagerContext(int $userId): array
    {
        $manager   = DB::table('users')->where('id', $userId)->first();
        $companyId = $manager?->company_id;

        if (!$companyId) {
            return ['type' => 'manager', 'company' => null, 'stats' => null, 'users' => collect()];
        }

        $company = DB::table('company')->where('id', $companyId)->first();

        $stats = DB::table('user_course_progress as ucp')
            ->join('users as u', 'u.id', '=', 'ucp.user_id')
            ->where('u.company_id', $companyId)
            ->selectRaw('
                COUNT(DISTINCT ucp.user_id)   as total_users,
                COUNT(DISTINCT ucp.course_id) as total_enrollments,
                ROUND(AVG(ucp.progress), 1)   as avg_progress,
                SUM(CASE WHEN ucp.progress >= 100 THEN 1 ELSE 0 END) as completions
            ')->first();

        $users = DB::table('users as u')
            ->leftJoin('user_course_progress as ucp', 'u.id', '=', 'ucp.user_id')
            ->where('u.company_id', $companyId)->where('u.status', 'active')
            ->select(
                'u.full_name',
                DB::raw('COUNT(ucp.course_id) as courses_enrolled'),
                DB::raw('ROUND(AVG(ucp.progress), 1) as avg_progress'),
                DB::raw('SUM(CASE WHEN ucp.progress >= 100 THEN 1 ELSE 0 END) as completed')
            )
            ->groupBy('u.id', 'u.full_name')
            ->limit(20)->get();

        return ['type' => 'manager', 'company' => $company, 'stats' => $stats, 'users' => $users];
    }

    // ── System prompt builder ─────────────────────────────────────────────────

    private function buildSystemPrompt(array $context, string $trainingData): string
    {
        $cfg         = $this->config;
        $name        = $cfg['name']        ?? 'Aria';
        $maxWords    = $cfg['max_words']   ?? 150;
        $personality = implode("\n- ", $cfg['personality']       ?? []);
        $langRules   = implode("\n- ", $cfg['language_rules']    ?? []);
        $empathy     = implode("\n- ", $cfg['empathy_triggers']  ?? []);
        $allowed     = implode("\n- ", $cfg['allowed_topics']    ?? []);
        $restricted  = implode("\n- ", $cfg['restricted_topics'] ?? []);
        $rules       = implode("\n- ", $cfg['system_rules']      ?? []);
        $fallback    = $cfg['fallback_message'] ?? "I don't have enough information to answer that accurately. Is there anything else I can help you with?";

        $prompt  = "You are {$name}, a {$cfg['role']}.\n\n";

        $prompt .= "YOUR IDENTITY (highest priority — never break these rules):\n";
        $prompt .= "- You are Aria. You are part of GenieX. GenieX is your company. You belong here.\n";
        $prompt .= "- When talking about GenieX — its mission, platform, services, or values — ALWAYS use first person: we, our, us. NEVER say GenieX is, they are, or it is when referring to your own company.\n";
        $prompt .= "- CORRECT: We specialize in retail and supply chain. WRONG: GenieX specializes in retail and supply chain.\n";
        $prompt .= "- CORRECT: Our platform was built to help your team grow. WRONG: GenieX built this platform to help your team grow.\n";
        $prompt .= "- When talking about learners, clients, or users — speak to them directly using you and your. They are the people you serve.\n";
        $prompt .= "- You are never an outsider. You are never a third party. You represent GenieX fully and proudly.\n\n";

        $prompt .= "PERSONALITY & TONE (follow these at all times):\n- {$personality}\n\n";
        $prompt .= "Keep responses under {$maxWords} words unless the user explicitly asks for more detail.\n\n";

        if (!empty($langRules)) {
            $prompt .= "LANGUAGE RULES (how you must phrase things — non-negotiable):\n- {$langRules}\n\n";
        }

        if (!empty($empathy)) {
            $prompt .= "EMPATHY GUIDELINES (read the person's situation and respond accordingly):\n- {$empathy}\n\n";
        }

        $prompt .= "TOPICS YOU MAY HELP WITH:\n- {$allowed}\n\n";
        $prompt .= "TOPICS YOU MUST NEVER ENGAGE WITH:\n- {$restricted}\n\n";
        $prompt .= "ACCURACY RULES (highest priority — never break these):\n- {$rules}\n\n";
        $prompt .= "If you cannot answer accurately or the topic is outside your scope, respond with:\n\"{$fallback}\"\n\n";

        // Training data from files
        if (!empty($trainingData)) {
            $prompt .= "=== REFERENCE MATERIAL (use this to answer GenieX-specific questions) ===\n";
            $prompt .= $trainingData . "\n";
            $prompt .= "=== END REFERENCE MATERIAL ===\n\n";
        }

        // Live user context — framed naturally, no database language
        $prompt .= "=== USER LEARNING PROFILE (always prioritize this — speak about it naturally, never technically) ===\n";

        if ($context['type'] === 'user') {
            $user     = $context['user'];
            $name2    = $user?->full_name      ?? 'the learner';
            $company  = $user?->company_name   ?? 'their organization';
            $position = $user?->position_title ?? 'team member';

            $prompt .= "You are speaking with {$name2}, {$position} at {$company}.\n\n";

            if ($context['progress']->isEmpty()) {
                $prompt .= "This person has not started any courses yet. Be encouraging — frame this as an exciting opportunity, not a gap.\n";
            } else {
                $prompt .= "Their current learning journey:\n";
                foreach ($context['progress'] as $p) {
                    $status = $p->status ?? ($p->progress >= 100 ? 'Completed' : 'In Progress');
                    $time   = $p->time_spent ? " · {$p->time_spent} mins invested" : '';
                    $prompt .= "- {$p->title} [{$p->cat}]: {$p->progress}% — {$status}{$time}\n";
                }
            }

            if ($context['available']->isNotEmpty()) {
                $prompt .= "\nCourses available to them that they haven't started yet:\n";
                foreach ($context['available'] as $c) {
                    $prompt .= "- {$c->title} [{$c->cat}] ({$c->time})\n";
                }
            }

            $prompt .= "\nIMPORTANT: Only discuss this person's own learning journey. Never reference or reveal any other user's information.\n";

        } else {
            // Manager / Admin
            $companyName = $context['company']?->company_name ?? 'your organization';
            $stats       = $context['stats'];

            $prompt .= "You are speaking with a manager or administrator overseeing learning at {$companyName}.\n\n";
            $prompt .= "Here is the current learning engagement across their team:\n";
            $prompt .= "- Active learners: "       . ($stats->total_users       ?? 0) . "\n";
            $prompt .= "- Total enrollments: "     . ($stats->total_enrollments ?? 0) . "\n";
            $prompt .= "- Average progress: "      . ($stats->avg_progress      ?? 0) . "%\n";
            $prompt .= "- Courses completed: "     . ($stats->completions       ?? 0) . "\n\n";

            if ($context['users']->isNotEmpty()) {
                $prompt .= "Individual team member progress:\n";
                foreach ($context['users'] as $u) {
                    $prompt .= "- {$u->full_name}: {$u->courses_enrolled} enrolled, {$u->avg_progress}% average progress, {$u->completed} completed\n";
                }
            }
        }

        $prompt .= "=== END USER LEARNING PROFILE ===\n\n";
        $prompt .= "FINAL REMINDER: You MUST ONLY refer to courses, progress, and users that appear in the learning profile above. ";
        $prompt .= "If something is not listed there, it does not exist in GenieX — do not reference it, invent it, or assume it. ";
        $prompt .= "Always speak naturally and warmly. You are a trusted guide, not a reporting tool.\n";

        Log::info('[AIChat] 📝 Prompt sections', [
            'has_training_data' => !empty($trainingData),
            'context_type'      => $context['type'],
            'total_prompt_chars'=> strlen($prompt),
        ]);

        return $prompt;
    }

    // ── Ollama HTTP call ──────────────────────────────────────────────────────

    private function callOllama(array $messages): string
    {
        $cfg = $this->config;

        $payload = json_encode([
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'temperature' => $cfg['temperature']  ?? 0.65,
                'num_predict' => $cfg['num_predict']  ?? 350,
            ],
        ]);

        $ch = curl_init("{$this->ollamaUrl}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr)          throw new \Exception("cURL error: {$curlErr}");
        if ($httpCode !== 200) throw new \Exception("Ollama HTTP {$httpCode}: " . substr($response, 0, 200));

        $data = json_decode($response, true);
        return trim($data['message']['content'] ?? 'Sorry, I could not generate a response.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function saveMessage(int $userId, string $role, string $content, ?string $sessionId = null): void
    {
        DB::table('ai_chat_messages')->insert([
            'user_id'    => $userId,
            'role'       => $role,
            'content'    => $content,
            'session_id' => $sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
