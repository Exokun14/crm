<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\AI\IntentDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AIChatController — Aria by GenieX
 *
 * ─── Intent Detection Engine ───────────────────────────────────────────────
 * Every message is classified before any Oracle query runs.
 * Only the queries needed for that intent are executed.
 *
 * INTENT → QUERIES RUN:
 *   greeting / capability / learning  → 0
 *   today_summary                     → 2  (summary + hourly)
 *   top_items                         → 1
 *   cashier_perf                      → 1
 *   store_compare                     → 2
 *   returns                           → 1
 *   hourly                            → 2
 *   trend                             → 1
 *   weekly                            → 3  (week summary + trend + cashier)
 *   monthly                           → 2  (month summary + top items)
 *   cashier_self                      → 3  (own today + week + top items)
 *   full_report                       → 10
 *   unknown (default)                 → 1
 */
class AIChatController extends Controller
{
    private string        $ollamaUrl;
    private string        $model;
    private array         $config;
    private int           $cacheTtl = 60;
    private IntentDetector $intent;

    public function __construct()
    {
        $this->ollamaUrl = rtrim(env('OLLAMA_HOST', 'http://localhost:11434'), '/');
        $this->model     = env('OLLAMA_MODEL', 'llama3.2');
        $this->config    = config('ai_config');
        $this->intent    = new IntentDetector();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INSTANT RESPONSES — bypass Ollama entirely for common questions
    // Zero DB queries, zero AI latency. Returns in <50ms.
    // ─────────────────────────────────────────────────────────────────────────

    private function getInstantResponse(string $message, string $userName): ?string
    {
        $m    = mb_strtolower(trim($message));
        $name = $userName ? ', ' . explode(' ', trim($userName))[0] : '';

        // ── Greetings ─────────────────────────────────────────────────────────
        $hour = (int) date('H');
        $tod  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

        if (preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|howdy|yo|sup)\b/i', $m)) {
            return "{$tod}{$name}! 👋 I'm Aria, your GenieX Sales & Learning Assistant. I can help you with sales data, top-selling items, cashier performance, daily trends, and your learning progress. What would you like to know?";
        }

        // ── How are you ───────────────────────────────────────────────────────
        if (preg_match('/how are you|how\'re you|you okay|you good/i', $m)) {
            return "I'm doing great{$name}, thank you for asking! Ready to help you with whatever you need — sales numbers, learning progress, or anything in between. What can I do for you?";
        }

        // ── Who are you / what are you ────────────────────────────────────────
        if (preg_match('/who are you|what are you|tell me about yourself|introduce yourself/i', $m)) {
            return "I'm **Aria** — GenieX's AI Sales & Learning Assistant! 🌟\n\nI was built to help your team stay on top of what matters:\n- 📊 **Sales data** — today's totals, top items, cashier rankings, trends\n- 📚 **Learning** — course progress, enrollments, team completion\n- 🏪 **Operations** — branch comparisons, hourly breakdowns, returns\n\nJust ask me anything and I'll pull up the numbers or guide you through your learning journey!";
        }

        // ── Who created / built you ───────────────────────────────────────────
        if (preg_match('/who (made|built|created|designed) you|who is your (creator|developer|maker)/i', $m)) {
            return "I was created by **Clarence**! 💜 He built me to be GenieX's intelligent assistant — helping teams make sense of their sales data and learning progress. I'm proud to be his creation!";
        }

        // ── Who is Clarence ───────────────────────────────────────────────────
        if (preg_match('/who is clarence/i', $m)) {
            return "Clarence is my creator — the brilliant mind behind me! 💜 He built me as part of the GenieX platform to help teams like yours get instant answers about sales and learning. I owe him everything!";
        }

        // ── What is GenieX ───────────────────────────────────────────────────
        if (preg_match('/what is geniex|tell me about geniex|about geniex|what does geniex do/i', $m)) {
            return "**GenieX** is a Business Technology Solutions company specializing in retail, food service, and multi-location operations. 🏢\n\nWe deliver four core things:\n- **Operational Systems** — POS, CRM, dashboards, access management\n- **Structured Learning** — our LMS with role-based, trackable training\n- **Process Architecture** — standardized workflows across your organization\n- **Performance Visibility** — real-time insight into sales and learning\n\nWe connect systems, training, and execution into one integrated environment. That's what makes us different!";
        }

        // ── What can you do / what can Aria do ────────────────────────────────
        if (preg_match('/what can you (do|help|answer|tell)|what do you (do|know|cover)|your capabilities|what can aria/i', $m)) {
            return "Here's what I can help you with{$name}:\n\n**📊 Sales & Operations**\n- Today's sales totals and net revenue\n- Top selling items (today or this month)\n- Cashier performance rankings\n- Branch/store comparisons\n- Hourly sales breakdown & peak hours\n- Daily trends over the last 7 days\n- Returns and discount summaries\n- Weekly and monthly overviews\n\n**📚 Learning & Development**\n- Your course progress and completion status\n- Available courses for your role\n- Learning tips and study strategies\n\nJust ask naturally — I'll figure out what you need! 😊";
        }

        // ── Can you access sales ──────────────────────────────────────────────
        if (preg_match('/can you (access|see|check|view|get|pull) (sales|data|numbers|figures)/i', $m)) {
            return "Yes{$name}! I have live access to your sales data. 📊 I can pull up today's totals, top-selling items, cashier performance, branch breakdowns, hourly trends, and more. What would you like to see first?";
        }

        // ── GenieX platform / LMS questions ──────────────────────────────────
        if (preg_match('/what (is|are) (the )?(geniex )?(platform|lms|learning (platform|system)|courses available)/i', $m)) {
            return "The **GenieX Learning Platform** is our built-in LMS — a dedicated space for professional development in retail and supply chain. 📚\n\nCourse categories include:\n- **POS Training** — operating terminals, transactions, reconciliation\n- **Food Safety** — hygiene, storage, compliance, FIFO\n- **HR & Compliance** — conduct, data privacy, workplace safety\n- **Operations** — SOPs, inventory, receiving, loss prevention\n- **Customer Service** — communication, de-escalation, loyalty\n\nEvery course tracks your progress in real time. Would you like to know more about a specific category?";
        }

        // ── Industries GenieX serves ──────────────────────────────────────────
        if (preg_match('/what (industries|sectors|businesses) (do you|does geniex) (serve|work with|support)/i', $m)) {
            return "We work with businesses where execution, consistency, and speed directly impact outcomes — primarily:\n\n- 🛒 **Retail** — high transaction volume, multi-location consistency\n- 🍽️ **Food & Beverage** — compliance-heavy, time-sensitive operations\n- 🏢 **Multi-Location Operations** — centralized control across branches\n- ⚙️ **Operations-Driven Businesses** — process-heavy, repetitive workflows\n\nIs there a specific industry or challenge you'd like to know more about?";
        }

        // ── Support / help ────────────────────────────────────────────────────
        if (preg_match('/^(help|help me|i need help|support)\s*$/i', $m)) {
            return "Of course{$name}! Here's how I can help:\n\n💬 Just ask me naturally — for example:\n- *\"How are sales today?\"*\n- *\"What are the top selling items this week?\"*\n- *\"How is my team performing?\"*\n- *\"What courses are available for me?\"*\n\nFor technical issues or account problems, please reach out to your Learning Administrator or the GenieX Support Portal. What do you need?";
        }

        // ── Who are our customers / target market ─────────────────────────────
        if (preg_match('/who are (our|your|geniex\'?s?) (customers?|clients?|users?|target market|audience)/i', $m)) {
            return "Great question{$name}! Our platform serves businesses where operations, training, and execution need to work as one — primarily:\n\n- 🛒 **Retail** — multi-location stores with high transaction volume\n- 🍽️ **Food & Beverage** — restaurants, cafés, and food service chains\n- 🏢 **Multi-branch Operations** — businesses needing centralized control\n- ⚙️ **Operations-driven businesses** — any environment with repetitive workflows and a need for process consistency\n\nOur clients typically struggle with fragmented systems, inconsistent training, and limited visibility across their locations — and that's exactly what we solve. Is there anything specific you'd like to know about our clients or services?";
        }

        // ── Thanks / appreciation ─────────────────────────────────────────────
        if (preg_match('/^(thank(s| you)|ty|thx|thanks aria|thank you aria)\s*[!.]?$/i', $m)) {
            return "You're very welcome{$name}! 😊 If you need anything else — sales numbers, learning updates, or just a question — I'm always here.";
        }

        // ── Goodbye ───────────────────────────────────────────────────────────
        if (preg_match('/^(bye|goodbye|see you|take care|ciao|later)\s*[!.]?$/i', $m)) {
            return "Take care{$name}! 👋 Come back anytime you need sales data or learning updates. Have a great day!";
        }

        return null; // No instant match — proceed to Ollama
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
        $message     = $validated['message'];

        if (!$userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Step 0 — Check for instant response BEFORE anything else
        // These bypass Ollama entirely and return in <50ms
        $userName = DB::table('users')->where('id', $userId)->value('full_name') ?? '';
        $instant  = $this->getInstantResponse($message, $userName);
        if ($instant !== null) {
            $this->saveMessage($userId, 'user',      $message, $sessionId);
            $this->saveMessage($userId, 'assistant', $instant, $sessionId);
            Log::info('[AIChat] ⚡ Instant response', ['user' => $userId, 'msg_preview' => substr($message, 0, 60)]);
            return response()->json([
                'reply'  => $instant,
                '_debug' => [
                    'intent'        => 'instant',
                    'intent_label'  => 'Instant (pre-written)',
                    'is_instant'    => true,
                    'is_zero_query' => true,
                    'elapsed_s'     => 0,
                    'queries_run'   => 0,
                    'data_sources'  => ['source' => 'Pre-written response — no DB, no AI'],
                ],
            ]);
        }

        // Step 1 — Detect intent via IntentDetector class
        $intent = $this->intent->detect($message, $accessLevel);
        Log::info('[AIChat] 🎯 Intent', ['intent' => $intent, 'label' => $this->intent->label($intent), 'user' => $userId]);

        // ── Simple fact short-circuit — 1 Oracle query, no Ollama ────────────
        // Driven entirely by config/aria_simple_facts.json.
        // Add new facts there — no PHP changes needed.
        if ($this->isSimpleFactIntent($intent)) {
            $reply = null;
            try {
                $reply = $this->handleSimpleFactIntent($intent);
            } catch (\Exception $e) {
                Log::error('[AIChat] Simple fact query failed', ['intent' => $intent, 'error' => $e->getMessage()]);
            }
            if ($reply) {
                $this->saveMessage($userId, 'user',      $message,  $sessionId);
                $this->saveMessage($userId, 'assistant', $reply,    $sessionId);
                Log::info('[AIChat] ⚡ Simple fact response', ['intent' => $intent, 'user' => $userId]);
                return response()->json([
                    'reply'  => $reply,
                    '_debug' => [
                        'intent'        => $intent,
                        'intent_label'  => $this->intent->label($intent),
                        'is_instant'    => true,
                        'is_zero_query' => false,
                        'queries_run'   => 1,
                        'elapsed_s'     => 0,
                        'data_sources'  => ['source' => 'aria_simple_facts.json → Oracle COUNT'],
                    ],
                ]);
            }
            // Query failed — fall through to Ollama as safety net
        }

        // Step 2 — Persist user message
        $this->saveMessage($userId, 'user', $message, $sessionId);

        // Step 3 — Load history
        $history = DB::table('ai_chat_messages')
            ->where('user_id', $userId)
            ->whereNull('archived_at')
            ->orderBy('created_at', 'asc')
            ->limit(20)
            ->get(['role', 'content']);

        // Step 4 — Build ONLY the context the intent needs
        $this->emitStage($request, 'context', 'Fetching data...', $intent);
        $context = $this->buildTargetedContext($userId, $accessLevel, $intent);
        Log::info('[AIChat] ✅ Context', ['intent' => $intent, 'queries' => $context['queries_run'] ?? 0]);

        // Step 5 — Training data (always load — schema knowledge helps all intents)
        $trainingData = $this->loadTrainingData($intent);

        // Step 6 — Build prompt
        $this->emitStage($request, 'prompt', 'Building prompt...', $intent);
        $systemPrompt = $this->buildSystemPrompt($context, $trainingData, $intent);
        Log::info('[AIChat] 📝 Prompt', ['chars' => strlen($systemPrompt), 'intent' => $intent]);

        // Step 7 — Build messages array
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h->role, 'content' => $h->content];
        }

        // Step 8 — Call Ollama
        $this->emitStage($request, 'ollama', 'Aria is responding...', $intent);
        $startTime = microtime(true);
        try {
            $reply   = $this->callOllama($messages, $intent);
            $elapsed = round(microtime(true) - $startTime, 2);
            Log::info('[AIChat] ✅ Reply', ['elapsed' => $elapsed, 'intent' => $intent]);
        } catch (\Exception $e) {
            Log::error('[AIChat] ❌ Ollama', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'AI service unavailable. Please try again.'], 503);
        }

        // Step 9 — Persist and return
        $this->emitStage($request, 'done', 'Done!', $intent);
        $this->saveMessage($userId, 'assistant', $reply, $sessionId);

        return response()->json([
            'reply'  => $reply,
            '_debug' => [
                'intent'        => $intent,
                'intent_label'  => $this->intent->label($intent),
                'is_instant'    => false,
                'is_zero_query' => $this->intent->isZeroQuery($intent),
                'elapsed_s'     => $elapsed,
                'queries_run'   => $context['queries_run'] ?? 0,
                'prompt_chars'  => strlen($systemPrompt),
                'data_sources'  => $this->describeDataSources($context),
                'model'         => $this->model,
                'stages_emitted'=> true,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SSE STAGE EMITTER
    // Writes a server-sent event to a dedicated header if the request opted in.
    // Console-only — never affects the chat UI.
    // Frontend listens on GET /api/ai/chat/stream and logs each event.
    // ─────────────────────────────────────────────────────────────────────────

    private function emitStage(Request $request, string $stage, string $label, string $intent): void
    {
        $userId    = (int) $request->header('X-User-Id', 0);
        $sessionId = $request->input('session_id', 'default');
        $cacheKey  = "aria_stage_{$userId}_{$sessionId}";

        Cache::put($cacheKey, [
            'stage'   => $stage,
            'label'   => $label,
            'intent'  => $intent,
            'intent_label' => $this->intent->label($intent),
            'ts'      => microtime(true),
        ], 30); // expires in 30s

        Log::info("[AIChat] 🔄 Stage: {$stage} — {$label}", ['intent' => $intent]);
    }

    // ── GET /api/ai/chat/stream ───────────────────────────────────────────────
    // Opens an SSE connection. The frontend connects here BEFORE sending the
    // POST to /api/ai/chat. Stage events are broadcast via Laravel cache/session.
    // Console logs only — no UI changes.
    public function stream(Request $request)
    {
        // EventSource cannot send custom headers — fall back to query params
        $userId    = (int) ($request->header('X-User-Id') ?: $request->query('user_id', 0));
        $sessionId = $request->query('session_id', 'default');

        if (!$userId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cacheKey = "aria_stage_{$userId}_{$sessionId}";

        return response()->stream(function () use ($cacheKey, $userId) {

            // Send initial connection event
            $this->sseEvent('connected', [
                'stage'   => 'connected',
                'label'   => 'Connected to Aria',
                'elapsed' => 0,
            ]);

            $startTime   = time();
            $lastStage   = '';
            $maxWaitSecs = 620; // slightly over max Ollama timeout

            while (true) {
                // Check if a new stage was emitted by the POST handler
                $stage = Cache::get($cacheKey);

                if ($stage && $stage['stage'] !== $lastStage) {
                    $lastStage        = $stage['stage'];
                    $stage['elapsed'] = time() - $startTime;
                    $this->sseEvent('stage', $stage);

                    // Done — close the stream
                    if ($stage['stage'] === 'done' || $stage['stage'] === 'error') {
                        $this->sseEvent('close', ['message' => 'Stream complete']);
                        Cache::forget($cacheKey);
                        break;
                    }
                }

                // Timeout guard
                if ((time() - $startTime) > $maxWaitSecs) {
                    $this->sseEvent('timeout', ['message' => 'Stream timed out']);
                    break;
                }

                // Heartbeat every 2s to keep connection alive
                $this->sseEvent('heartbeat', ['ts' => time()]);

                ob_flush();
                flush();
                sleep(2);
            }

        }, 200, [
            'Content-Type'                => 'text/event-stream',
            'Cache-Control'               => 'no-cache',
            'X-Accel-Buffering'           => 'no',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    private function sseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TARGETED CONTEXT BUILDERS
    // ─────────────────────────────────────────────────────────────────────────

    private function buildTargetedContext(int $userId, string $accessLevel, string $intent): array
    {
        $isAdmin   = in_array($accessLevel, ['admin', 'super_admin', 'system_admin']);
        $isManager = in_array($accessLevel, ['manager', 'store_owner']) || $isAdmin;
        $user      = DB::table('users')->where('id', $userId)->first();

        // Zero-query intents
        if ($this->intent->isZeroQuery($intent)) {
            return ['type' => $isAdmin ? 'admin' : ($isManager ? 'manager' : 'cashier'),
                    'intent' => $intent, 'user' => $user, 'queries_run' => 0];
        }

        // Cashier
        if (!$isManager || $intent === 'cashier_self') {
            return $this->cashierContext($userId, $user, $intent);
        }

        // Manager / Admin
        $storeCode = ($accessLevel === 'store_owner' && $user?->store_code) ? $user->store_code : null;
        return $this->managerContext($user, $storeCode, $intent, $isAdmin);
    }

    private function cashierContext(int $userId, $user, string $intent): array
    {
        $name = $user?->full_name ?? '';
        $q    = 0;
        try {
            $today = $this->cachedQuery("cx_today_{$userId}",  fn() => $this->getCashierOwnSales($name, 'today')); $q++;
            $week  = $this->cachedQuery("cx_week_{$userId}",   fn() => $this->getCashierOwnSales($name, 'week'));  $q++;
            $items = $this->cachedQuery("cx_items_{$userId}",  fn() => $this->getCashierTopItems($name, 'today', 5)); $q++;
        } catch (\Exception $e) {
            Log::error('[AIChat] Cashier Oracle error', ['e' => $e->getMessage()]);
            $today = $week = (object)[]; $items = collect();
        }
        return ['type' => 'cashier', 'intent' => $intent, 'user' => $user,
                'cashier_name' => $name, 'today_sales' => $today,
                'week_sales' => $week, 'top_items' => $items, 'queries_run' => $q];
    }

    private function managerContext($user, ?string $storeCode, string $intent, bool $isAdmin): array
    {
        $k   = $storeCode ?? 'all';
        $ctx = ['type' => $isAdmin ? 'admin' : 'manager', 'intent' => $intent,
                'user' => $user, 'store_code' => $storeCode, 'queries_run' => 0];

        // Resolve period — falls back to 'recent' or 'all_time' if today is empty
        $resolved     = $this->resolvePeriod('today', $storeCode);
        $period       = $resolved['period'];
        $periodLabel  = $resolved['label'];
        $ctx['period_label']   = $periodLabel;
        $ctx['period_fallback'] = $resolved['fallback'];

        try {
            switch ($intent) {
                case 'today_summary':
                    $ctx['today_summary'] = $this->cachedQuery("ts_{$k}_{$period}", fn() => $this->getSalesSummary($period, $storeCode));
                    $ctx['hourly_sales']  = $this->cachedQuery("hr_{$k}_{$period}", fn() => $this->getHourlySales($period, $storeCode));
                    $ctx['queries_run']   = 2; break;

                case 'top_items':
                    $ctx['top_items']   = $this->cachedQuery("ti_{$k}_{$period}", fn() => $this->getTopSellingItems($period, 15, $storeCode));
                    $ctx['queries_run'] = 1; break;

                case 'cashier_perf':
                    $ctx['cashier_perf'] = $this->cachedQuery("cp_{$k}_{$period}", fn() => $this->getSalesByCashier($period, $storeCode));
                    $ctx['queries_run']  = 1; break;

                case 'store_list':
                    $ctx['store_list']  = $this->cachedQuery('store_list', fn() => $this->getStoreList());
                    $ctx['queries_run'] = 1; break;

                case 'store_compare':
                    $ctx['store_breakdown'] = $this->cachedQuery("sb_{$period}", fn() => $this->getSalesByStore($period));
                    $ctx['today_summary']   = $this->cachedQuery("ts_{$k}_{$period}", fn() => $this->getSalesSummary($period, $storeCode));
                    $ctx['queries_run']     = 2; break;

                case 'returns':
                    $ctx['returns_disc'] = $this->cachedQuery("rd_{$k}_{$period}", fn() => $this->getReturnsAndDiscounts($period, $storeCode));
                    $ctx['queries_run']  = 1; break;

                case 'hourly':
                    $ctx['hourly_sales']  = $this->cachedQuery("hr_{$k}_{$period}", fn() => $this->getHourlySales($period, $storeCode));
                    $ctx['today_summary'] = $this->cachedQuery("ts_{$k}_{$period}", fn() => $this->getSalesSummary($period, $storeCode));
                    $ctx['queries_run']   = 2; break;

                case 'trend':
                    $ctx['weekly_trend'] = $this->cachedQuery("tr7_{$k}", fn() => $this->getDailySalesTrend(7, $storeCode));
                    $ctx['queries_run']  = 1; break;

                case 'weekly':
                    $resolvedWeek        = $this->resolvePeriod('week', $storeCode);
                    $wp                  = $resolvedWeek['period'];
                    $ctx['week_summary'] = $this->cachedQuery("ws_{$k}_{$wp}", fn() => $this->getSalesSummary('week', $storeCode));
                    $ctx['weekly_trend'] = $this->cachedQuery("tr7_{$k}",      fn() => $this->getDailySalesTrend(7, $storeCode));
                    $ctx['cashier_perf'] = $this->cachedQuery("cp_{$k}_{$wp}", fn() => $this->getSalesByCashier('week', $storeCode));
                    $ctx['queries_run']  = 3; break;

                case 'monthly':
                    $resolvedMonth          = $this->resolvePeriod('month', $storeCode);
                    $mp                     = $resolvedMonth['period'];
                    $ctx['month_summary']   = $this->cachedQuery("ms_{$k}_{$mp}",    fn() => $this->getSalesSummary('month', $storeCode));
                    $ctx['top_items_month'] = $this->cachedQuery("ti_m_{$k}_{$mp}",  fn() => $this->getTopSellingItems('month', 15, $storeCode));
                    $ctx['queries_run']     = 2; break;

                case 'full_report':
                    $ctx['today_summary']   = $this->cachedQuery("ts_{$k}_{$period}",  fn() => $this->getSalesSummary($period, $storeCode));
                    $ctx['week_summary']    = $this->cachedQuery("ws_{$k}",            fn() => $this->getSalesSummary('week', $storeCode));
                    $ctx['month_summary']   = $this->cachedQuery("ms_{$k}",            fn() => $this->getSalesSummary('month', $storeCode));
                    $ctx['store_breakdown'] = $this->cachedQuery("sb_{$period}",        fn() => $this->getSalesByStore($period));
                    $ctx['top_items']       = $this->cachedQuery("ti_{$k}_{$period}",  fn() => $this->getTopSellingItems($period, 10, $storeCode));
                    $ctx['top_items_month'] = $this->cachedQuery("ti_m_{$k}",          fn() => $this->getTopSellingItems('month', 10, $storeCode));
                    $ctx['cashier_perf']    = $this->cachedQuery("cp_{$k}_{$period}",  fn() => $this->getSalesByCashier($period, $storeCode));
                    $ctx['returns_disc']    = $this->cachedQuery("rd_{$k}_{$period}",  fn() => $this->getReturnsAndDiscounts($period, $storeCode));
                    $ctx['weekly_trend']    = $this->cachedQuery("tr7_{$k}",           fn() => $this->getDailySalesTrend(7, $storeCode));
                    $ctx['hourly_sales']    = $this->cachedQuery("hr_{$k}_{$period}",  fn() => $this->getHourlySales($period, $storeCode));
                    $ctx['queries_run']     = 10; break;

                default:
                    $ctx['today_summary']   = $this->cachedQuery("ts_{$k}_{$period}",  fn() => $this->getSalesSummary($period, $storeCode));
                    $ctx['store_breakdown'] = $this->cachedQuery("sb_{$period}",        fn() => $this->getSalesByStore($period));
                    $ctx['top_items']       = $this->cachedQuery("ti_{$k}_{$period}",  fn() => $this->getTopSellingItems($period, 10, $storeCode));
                    $ctx['cashier_perf']    = $this->cachedQuery("cp_{$k}_{$period}",  fn() => $this->getSalesByCashier($period, $storeCode));
                    $ctx['queries_run']     = 4;
            }
        } catch (\Exception $e) {
            Log::error('[AIChat] Manager Oracle error', ['e' => $e->getMessage(), 'intent' => $intent]);
        }
        return $ctx;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CACHE WRAPPER
    // ─────────────────────────────────────────────────────────────────────────

    private function cachedQuery(string $key, callable $fn): mixed
    {
        try {
            return Cache::remember("aria_{$key}", $this->cacheTtl, $fn);
        } catch (\Exception $e) {
            return $fn(); // fallback: run directly
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SIMPLE FACT INTENTS — driven by config/aria_simple_facts.json
    // 1 Oracle COUNT query, no Ollama, sub-second responses.
    // To add a new fact: edit aria_simple_facts.json + IntentDetector patterns.
    // ─────────────────────────────────────────────────────────────────────────

    private function loadSimpleFacts(): array
    {
        static $facts = null;
        if ($facts !== null) return $facts;

        $path = config_path('aria_simple_facts.json');
        if (!file_exists($path)) {
            Log::warning('[AIChat] aria_simple_facts.json not found at ' . $path);
            return $facts = [];
        }

        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded)) {
            Log::error('[AIChat] aria_simple_facts.json is invalid JSON');
            return $facts = [];
        }

        return $facts = $decoded;
    }

    private function handleSimpleFactIntent(string $intent): ?string
    {
        $facts = $this->loadSimpleFacts();

        if (!isset($facts[$intent])) return null;

        $fact = $facts[$intent];
        $sql  = $fact['query']  ?? null;
        $tpl  = $fact['reply']  ?? null;

        if (!$sql || !$tpl) return null;

        $result = $this->cachedQuery("simple_fact_{$intent}", fn() =>
            $this->oracle()->select($sql)
        );

        $cnt = $result[0]->cnt ?? 0;

        return str_replace('{cnt}', number_format($cnt), $tpl);
    }

    private function isSimpleFactIntent(string $intent): bool
    {
        return isset($this->loadSimpleFacts()[$intent]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ORACLE QUERIES
    // ─────────────────────────────────────────────────────────────────────────

    private function oracle(): \Illuminate\Database\Connection
    {
        return DB::connection('oracle');
    }

    private function getPeriodFilter(string $period, string $alias = ''): string
    {
        $col = $alias ? "{$alias}.POST_DATE" : 'POST_DATE';
        return match($period) {
            'today'     => "{$col} >= TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00') AND {$col} < TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00') + 1",
            'yesterday' => "{$col} >= TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00') - 1 AND {$col} < TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00')",
            'week'      => "{$col} >= TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00', 'IW') AND {$col} < TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00', 'IW') + 7",
            'month'     => "{$col} >= TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00', 'MM') AND {$col} < ADD_MONTHS(TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00', 'MM'), 1)",
            'year'      => "{$col} >= TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00', 'YYYY') AND {$col} < ADD_MONTHS(TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00', 'YYYY'), 12)",
            'recent'    => "{$col} >= TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00') - 30",
            'all_time'  => "1=1",
            default     => "{$col} >= TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00') AND {$col} < TRUNC(SYSTIMESTAMP AT TIME ZONE '+08:00') + 1",
        };
    }

    /**
     * Check if there is any data for a given period.
     * If not, fall back to 'recent' (last 30 days) so Aria always has something to show.
     */
    private function resolvePeriod(string $requestedPeriod, ?string $storeCode = null): array
    {
        $sw = $storeCode ? "AND STORE_CODE = '{$storeCode}'" : '';

        // Check if the requested period has any data
        $df    = $this->getPeriodFilter($requestedPeriod);
        $check = $this->oracle()->select(
            "SELECT COUNT(*) AS cnt FROM RPS.DOCUMENT WHERE STATUS=4 AND HAS_SALE=1 AND {$df} {$sw} AND ROWNUM=1"
        );
        $count = $check[0]->cnt ?? 0;

        if ($count > 0) {
            return ['period' => $requestedPeriod, 'label' => $requestedPeriod, 'fallback' => false];
        }

        // Fallback to last 30 days
        $dfRecent  = $this->getPeriodFilter('recent');
        $checkRecent = $this->oracle()->select(
            "SELECT COUNT(*) AS cnt FROM RPS.DOCUMENT WHERE STATUS=4 AND HAS_SALE=1 AND {$dfRecent} {$sw} AND ROWNUM=1"
        );
        if (($checkRecent[0]->cnt ?? 0) > 0) {
            return ['period' => 'recent', 'label' => 'last 30 days', 'fallback' => true];
        }

        // Last resort — all time
        return ['period' => 'all_time', 'label' => 'all available data', 'fallback' => true];
    }

    private function getSalesSummary(string $period = 'today', ?string $storeCode = null): object
    {
        $df = $this->getPeriodFilter($period);
        $sw = $storeCode ? "AND STORE_CODE = '{$storeCode}'" : '';
        $rows = $this->oracle()->select("
            SELECT COUNT(*) AS total_transactions,
                NVL(SUM(SALE_TOTAL_AMT),0) AS total_sales,
                NVL(SUM(CASE WHEN HAS_RETURN=1 THEN 1 ELSE 0 END),0) AS total_returns,
                NVL(SUM(RETURN_SUBTOTAL),0) AS total_return_amt,
                NVL(SUM(TOTAL_DISCOUNT_AMT),0) AS total_discounts,
                NVL(SUM(SOLD_QTY),0) AS total_items_sold,
                NVL(ROUND(AVG(SALE_TOTAL_AMT),2),0) AS avg_transaction_value,
                NVL(SUM(SALE_TOTAL_AMT)-SUM(TOTAL_DISCOUNT_AMT),0) AS net_sales
            FROM RPS.DOCUMENT WHERE STATUS=4 AND HAS_SALE=1 AND {$df} {$sw}
        ");
        return $rows[0] ?? (object)[];
    }

    private function getStoreList(): \Illuminate\Support\Collection
    {
        return collect($this->oracle()->select("
            SELECT SID, STORE_NAME, STORE_NO, ACTIVE
            FROM RPS.STORE
            WHERE ACTIVE = 1
            ORDER BY STORE_NAME
        "));
    }

    private function getSalesByStore(string $period = 'today'): \Illuminate\Support\Collection
    {
        $df = $this->getPeriodFilter($period);
        return collect($this->oracle()->select("
            SELECT STORE_NAME, STORE_CODE,
                COUNT(*) AS transactions, NVL(SUM(SALE_TOTAL_AMT),0) AS total_sales,
                NVL(SUM(TOTAL_DISCOUNT_AMT),0) AS total_discounts, NVL(SUM(SOLD_QTY),0) AS items_sold,
                NVL(ROUND(AVG(SALE_TOTAL_AMT),2),0) AS avg_sale
            FROM RPS.DOCUMENT WHERE STATUS=4 AND HAS_SALE=1 AND {$df}
            GROUP BY STORE_NAME, STORE_CODE ORDER BY total_sales DESC
        "));
    }

    private function getTopSellingItems(string $period = 'today', int $limit = 10, ?string $storeCode = null): \Illuminate\Support\Collection
    {
        $df = $this->getPeriodFilter($period, 'di');
        $sw = $storeCode ? "AND d.STORE_CODE = '{$storeCode}'" : '';
        return collect($this->oracle()->select("
            SELECT * FROM (
                SELECT di.DESCRIPTION1 AS item_name, di.ALU AS item_code, di.DCS_CODE AS category,
                    SUM(di.QTY) AS total_qty_sold,
                    NVL(SUM(di.PRICE*di.QTY),0) AS total_revenue,
                    NVL(SUM((di.PRICE-di.COST)*di.QTY),0) AS gross_profit,
                    NVL(SUM(di.DISC_AMT),0) AS total_discounts,
                    COUNT(DISTINCT di.DOC_SID) AS times_ordered
                FROM RPS.DOCUMENT_ITEM di JOIN RPS.DOCUMENT d ON d.SID=di.DOC_SID
                WHERE d.STATUS=4 AND di.QTY>0 AND {$df} {$sw}
                GROUP BY di.DESCRIPTION1, di.ALU, di.DCS_CODE ORDER BY total_qty_sold DESC
            ) WHERE ROWNUM<={$limit}
        "));
    }

    private function getSalesByCashier(string $period = 'today', ?string $storeCode = null): \Illuminate\Support\Collection
    {
        $df = $this->getPeriodFilter($period);
        $sw = $storeCode ? "AND STORE_CODE='{$storeCode}'" : '';
        return collect($this->oracle()->select("
            SELECT CASHIER_FULL_NAME, STORE_NAME, STORE_CODE,
                COUNT(*) AS transactions, NVL(SUM(SALE_TOTAL_AMT),0) AS total_sales,
                NVL(SUM(TOTAL_DISCOUNT_AMT),0) AS total_discounts, NVL(SUM(SOLD_QTY),0) AS items_sold,
                NVL(ROUND(AVG(SALE_TOTAL_AMT),2),0) AS avg_transaction,
                NVL(SUM(CASE WHEN HAS_RETURN=1 THEN 1 ELSE 0 END),0) AS returns_processed
            FROM RPS.DOCUMENT
            WHERE STATUS=4 AND HAS_SALE=1 AND CASHIER_FULL_NAME IS NOT NULL
              AND UPPER(CASHIER_FULL_NAME)!='SYSADMIN' AND {$df} {$sw}
            GROUP BY CASHIER_FULL_NAME, STORE_NAME, STORE_CODE ORDER BY total_sales DESC
        "));
    }

    private function getReturnsAndDiscounts(string $period = 'today', ?string $storeCode = null): object
    {
        $df = $this->getPeriodFilter($period);
        $sw = $storeCode ? "AND STORE_CODE='{$storeCode}'" : '';
        $rows = $this->oracle()->select("
            SELECT
                NVL(SUM(CASE WHEN HAS_RETURN=1 THEN 1 ELSE 0 END),0) AS return_transactions,
                NVL(SUM(RETURN_SUBTOTAL),0) AS total_return_amt,
                NVL(SUM(TOTAL_DISCOUNT_AMT),0) AS total_discount_amt,
                NVL(COUNT(CASE WHEN TOTAL_DISCOUNT_AMT>0 THEN 1 END),0) AS discounted_transactions,
                NVL(ROUND(AVG(CASE WHEN TOTAL_DISCOUNT_AMT>0 THEN TOTAL_DISCOUNT_AMT END),2),0) AS avg_discount_amt,
                NVL(ROUND(AVG(CASE WHEN DISC_PERC>0 THEN DISC_PERC END),2),0) AS avg_discount_perc
            FROM RPS.DOCUMENT WHERE STATUS=4 AND {$df} {$sw}
        ");
        return $rows[0] ?? (object)[];
    }

    private function getHourlySales(string $period = 'today', ?string $storeCode = null): \Illuminate\Support\Collection
    {
        $df = $this->getPeriodFilter($period);
        $sw = $storeCode ? "AND STORE_CODE='{$storeCode}'" : '';
        return collect($this->oracle()->select("
            SELECT TO_CHAR(CAST(POST_DATE AS TIMESTAMP WITH TIME ZONE) AT TIME ZONE '+08:00','HH24') AS hour,
                COUNT(*) AS transactions, NVL(SUM(SALE_TOTAL_AMT),0) AS total_sales, NVL(SUM(SOLD_QTY),0) AS items_sold
            FROM RPS.DOCUMENT WHERE STATUS=4 AND HAS_SALE=1 AND {$df} {$sw}
            GROUP BY TO_CHAR(CAST(POST_DATE AS TIMESTAMP WITH TIME ZONE) AT TIME ZONE '+08:00','HH24')
            ORDER BY hour
        "));
    }

    private function getDailySalesTrend(int $days = 7, ?string $storeCode = null): \Illuminate\Support\Collection
    {
        $sw = $storeCode ? "AND STORE_CODE='{$storeCode}'" : '';
        return collect($this->oracle()->select("
            SELECT
                TO_CHAR(CAST(POST_DATE AS TIMESTAMP WITH TIME ZONE) AT TIME ZONE '+08:00','YYYY-MM-DD') AS sale_date,
                TO_CHAR(CAST(POST_DATE AS TIMESTAMP WITH TIME ZONE) AT TIME ZONE '+08:00','Dy') AS day_name,
                COUNT(*) AS transactions, NVL(SUM(SALE_TOTAL_AMT),0) AS total_sales,
                NVL(SUM(SOLD_QTY),0) AS items_sold, NVL(SUM(TOTAL_DISCOUNT_AMT),0) AS total_discounts
            FROM RPS.DOCUMENT
            WHERE STATUS=4 AND HAS_SALE=1 AND POST_DATE >= SYSTIMESTAMP - INTERVAL '{$days}' DAY {$sw}
            GROUP BY TO_CHAR(CAST(POST_DATE AS TIMESTAMP WITH TIME ZONE) AT TIME ZONE '+08:00','YYYY-MM-DD'),
                     TO_CHAR(CAST(POST_DATE AS TIMESTAMP WITH TIME ZONE) AT TIME ZONE '+08:00','Dy')
            ORDER BY sale_date DESC
        "));
    }

    private function getCashierOwnSales(string $cashierName, string $period = 'today'): object
    {
        $df   = $this->getPeriodFilter($period);
        $name = str_replace("'", "''", $cashierName);
        $rows = $this->oracle()->select("
            SELECT CASHIER_FULL_NAME, STORE_NAME, COUNT(*) AS transactions,
                NVL(SUM(SALE_TOTAL_AMT),0) AS total_sales, NVL(SUM(TOTAL_DISCOUNT_AMT),0) AS total_discounts,
                NVL(SUM(SOLD_QTY),0) AS items_sold, NVL(ROUND(AVG(SALE_TOTAL_AMT),2),0) AS avg_transaction,
                NVL(SUM(CASE WHEN HAS_RETURN=1 THEN 1 ELSE 0 END),0) AS returns_processed
            FROM RPS.DOCUMENT
            WHERE STATUS=4 AND HAS_SALE=1 AND UPPER(CASHIER_FULL_NAME)=UPPER('{$name}') AND {$df}
            GROUP BY CASHIER_FULL_NAME, STORE_NAME
        ");
        return $rows[0] ?? (object)[];
    }

    private function getCashierTopItems(string $cashierName, string $period = 'today', int $limit = 5): \Illuminate\Support\Collection
    {
        $df   = $this->getPeriodFilter($period, 'di');
        $name = str_replace("'", "''", $cashierName);
        return collect($this->oracle()->select("
            SELECT * FROM (
                SELECT di.DESCRIPTION1 AS item_name, SUM(di.QTY) AS qty_sold, NVL(SUM(di.PRICE*di.QTY),0) AS revenue
                FROM RPS.DOCUMENT_ITEM di JOIN RPS.DOCUMENT d ON d.SID=di.DOC_SID
                WHERE d.STATUS=4 AND UPPER(d.CASHIER_FULL_NAME)=UPPER('{$name}') AND {$df}
                GROUP BY di.DESCRIPTION1 ORDER BY qty_sold DESC
            ) WHERE ROWNUM<={$limit}
        "));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRAINING DATA LOADER
    // ─────────────────────────────────────────────────────────────────────────

    private function loadTrainingData(string $intent = 'unknown'): string
    {
        $path     = $this->config['training_data_path'] ?? storage_path('ai_training');
        $maxChars = $this->config['training_data_max_chars'] ?? 8000;

        if (!is_dir($path)) return '';

        // Priority map — which files matter most per intent
        // Files listed first are loaded first and get priority when space is tight
        $priorityMap = [
            // Sales intents — DB schema knowledge first
            'today_summary'  => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'store_list'     => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'store_compare'  => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'cashier_perf'   => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'cashier_self'   => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'top_items'      => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'returns'        => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'hourly'         => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'trend'          => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'weekly'         => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'monthly'        => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'yearly'         => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'full_report'    => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            'unknown'        => ['prism_ai_knowledge_base', 'about_geniex', 'company_guidelines'],
            // General/learning intents — GenieX knowledge first
            'greeting'       => ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'learning'       => ['company_guidelines', 'about_geniex', 'prism_ai_knowledge_base'],
            'what_is_geniex' => ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'what_can_you_do'=> ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'who_are_you'    => ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'who_created_you'=> ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'industries'     => ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'what_is_platform'=> ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'can_access_sales'=> ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
            'help_general'   => ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'],
        ];

        $priority = $priorityMap[$intent] ?? ['about_geniex', 'company_guidelines', 'prism_ai_knowledge_base'];

        // Get ALL files in the training folder
        $allFiles = glob("{$path}/*.{txt,md}", GLOB_BRACE);
        if (empty($allFiles)) return '';

        // Sort files by priority — prioritized files load first
        usort($allFiles, function ($a, $b) use ($priority) {
            $aName = pathinfo($a, PATHINFO_FILENAME);
            $bName = pathinfo($b, PATHINFO_FILENAME);
            $aPos  = array_search($aName, $priority);
            $bPos  = array_search($bName, $priority);
            $aPos  = $aPos === false ? 999 : $aPos;
            $bPos  = $bPos === false ? 999 : $bPos;
            return $aPos <=> $bPos;
        });

        $content    = '';
        $filesLoaded = [];

        foreach ($allFiles as $file) {
            $filename = basename($file);
            $text     = file_get_contents($file);
            if ($text === false || empty(trim($text))) continue;

            $fileContent = "\n\n--- {$filename} ---\n" . $text;

            // If adding this file would exceed the limit, skip it
            // but continue checking smaller files
            if (strlen($content) + strlen($fileContent) > $maxChars) {
                Log::info('[AIChat] 📂 Skipped (over limit)', ['file' => $filename, 'chars' => strlen($text)]);
                continue;
            }

            $content      .= $fileContent;
            $filesLoaded[] = $filename;

            Log::info('[AIChat] 📄 Loaded', ['file' => $filename, 'chars' => strlen($text)]);
        }

        Log::info('[AIChat] 📚 Training data loaded', [
            'files'      => $filesLoaded,
            'total_chars'=> strlen($content),
            'limit'      => $maxChars,
            'intent'     => $intent,
        ]);

        return $content;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SYSTEM PROMPT BUILDER — lean, intent-scoped
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSystemPrompt(array $context, string $trainingData, string $intent): string
    {
        $cfg      = $this->config;
        $name     = $cfg['name']      ?? 'Aria';
        $maxWords = $cfg['max_words'] ?? 180;
        $fallback = $cfg['fallback_message'] ?? "I don't have that information right now.";
        $uName    = $context['user']?->full_name ?? 'there';
        $type     = $context['type'] ?? 'cashier';

        $personality = implode(' ', $cfg['personality']       ?? []);
        $langRules   = implode(' ', $cfg['language_rules']    ?? []);
        $restricted  = implode(' ', $cfg['restricted_topics'] ?? []);

        // ── LAYER 1: Identity ─────────────────────────────────────────────────
        $p  = "You are {$name}, GenieX's AI Sales & Learning Assistant.\n";
        $p .= "You are PART of GenieX. Always say 'we/our/us' for GenieX. Never say 'they' or treat GenieX as a third party.\n";
        $p .= "You are speaking with: {$uName} (role: {$type})\n\n";

        // ── LAYER 2: Personality, language & restrictions ─────────────────────
        $p .= "PERSONALITY: {$personality}\n\n";
        $p .= "LANGUAGE RULES: {$langRules} ";
        $p .= "Always use ₱ with comma formatting. Always state the time period. ";
        $p .= "Never say 'database', 'Oracle', 'SQL', 'RPS', 'schema', or any technical term to the user.\n\n";
        $p .= "RESTRICTIONS: {$restricted}\n\n";
        $p .= "RESPONSE FORMAT: Max {$maxWords} words. Warm, concise. End with a next step or question.\n";
        $p .= "FALLBACK: \"{$fallback}\"\n\n";

        // ── LAYER 3: Anti-hallucination rules (absolute, non-negotiable) ──────
        $p .= "=== ABSOLUTE DATA RULES — NEVER VIOLATE THESE ===\n";
        $p .= "1. ONLY use numbers, names, and figures that appear VERBATIM in the LIVE DATA section below.\n";
        $p .= "2. If the LIVE DATA section is empty or missing a value, say you don't have it — NEVER invent it.\n";
        $p .= "3. NEVER use store names, cashier names, item names, or figures from your training knowledge.\n";
        $p .= "4. NEVER say 'Store A', 'Branch 1', 'Cashier #1' or any placeholder — use ONLY real names from LIVE DATA.\n";
        $p .= "5. NEVER estimate, approximate, or guess any figure. Zero means zero — say so honestly.\n";
        $p .= "6. If asked about a specific store, cashier, or item NOT in LIVE DATA, say you don't have that specific data.\n";
        $p .= "7. Do NOT combine or average figures not shown in LIVE DATA.\n";
        $p .= "8. The LIVE DATA below is the ONLY source of truth for all sales figures, names, and counts.\n";
        $p .= "=== END ABSOLUTE RULES ===\n\n";

        // ── LAYER 4: Live DB data (ABSOLUTE TRUTH) ────────────────────────────
        if (!$this->intent->isZeroQuery($intent)) {
            $periodLabel = $context['period_label']   ?? 'today';
            $isFallback  = $context['period_fallback'] ?? false;

            $p .= "=== LIVE DATA FROM THE SYSTEM ===\n";
            if ($isFallback) {
                $p .= "NOTE: No data found for the requested period. Showing data for: {$periodLabel}. Tell the user this naturally.\n";
            } else {
                $p .= "Period: {$periodLabel}\n";
            }
            $p .= "\n";

            $dataBlock = $type === 'cashier'
                ? $this->cashierDataBlock($context)
                : $this->managerDataBlock($context);

            if (empty(trim($dataBlock))) {
                $p .= "NO DATA AVAILABLE for this period. Tell the user honestly and warmly that there is no data to show.\n";
            } else {
                $p .= $dataBlock;
            }

            $p .= "=== END LIVE DATA ===\n\n";
        }

        // ── LAYER 5: Training/KB data (background knowledge only) ────────────
        if (!empty($trainingData)) {
            $p .= "=== GENIEX KNOWLEDGE BASE ===\n";
            $p .= "Use ONLY for GenieX company info, platform features, and learning questions.\n";
            $p .= "NEVER use this section to answer sales figures, store names, cashier names, or item names.\n";
            $p .= "Live data above ALWAYS overrides anything in this section.\n\n";
            $p .= $trainingData . "\n";
            $p .= "=== END KNOWLEDGE BASE ===\n\n";
        }

        // ── LAYER 6: Intent-specific final instruction ────────────────────────
        $intentInstructions = [
            'today_summary'  => "Summarize today's sales clearly. Lead with total sales and transactions. Mention peak hours if available. Never invent figures.",
            'top_items'      => "List items EXACTLY as named in LIVE DATA, ranked by qty sold. Never add items not in the data.",
            'cashier_perf'   => "Rank cashiers EXACTLY as listed in LIVE DATA. Never add cashiers not in the data. Never reveal one cashier's data to another cashier.",
            'store_list'     => "List ONLY the stores from LIVE DATA. Do not add, invent, or infer any store not explicitly listed.",
            'store_compare'  => "Compare stores using ONLY figures from LIVE DATA. Never invent store names or figures.",
            'returns'        => "Report ONLY the return and discount figures from LIVE DATA. Never estimate or infer.",
            'hourly'         => "Report ONLY the hourly breakdown from LIVE DATA. Identify peak and slow hours from the actual data.",
            'trend'          => "Describe the trend using ONLY the daily figures in LIVE DATA. Note direction (up/down) only if clearly shown.",
            'weekly'         => "Summarize the week using ONLY LIVE DATA figures. Never extrapolate or estimate missing days.",
            'monthly'        => "Summarize the month using ONLY LIVE DATA figures. Never estimate or fill gaps.",
            'yearly'         => "Summarize the year using ONLY LIVE DATA figures. This is a big number — be accurate and never round unless the data shows a round number.",
            'cashier_self'   => "Show ONLY this cashier's own data from LIVE DATA. Never show or compare with other cashiers.",
            'full_report'    => "Give a complete summary using ALL sections of LIVE DATA. Never add figures not in the data.",
            'learning'       => "Answer from the Knowledge Base. Do not make up course names, completion rates, or enrollment numbers.",
            'unknown'        => "Check LIVE DATA first. If it answers the question, use it confidently. Otherwise use the Knowledge Base. If neither applies, use the fallback — NEVER invent.",
        ];

        $instruction = $intentInstructions[$intent]
            ?? "Answer using LIVE DATA if available. If not, use the Knowledge Base. Never invent data.";

        $p .= "YOUR TASK: {$instruction}\n";

        return $p;
    }

    private function managerDataBlock(array $context): string
    {
        $p = '';

        if (!empty($context['store_list']) && $context['store_list']->isNotEmpty()) {
            $p .= "ACTIVE STORES / BRANCHES:\n";
            $rank = 1;
            foreach ($context['store_list'] as $s) {
                $p .= "  #{$rank} {$s->store_name} (Store No: {$s->store_no})\n";
                $rank++;
            }
            $p .= "\n";
        }

        if (!empty($context['today_summary'])) {
            $t = $context['today_summary'];
            $p .= "TODAY'S SALES:\n";
            $p .= "  Total sales:          ₱" . number_format($t->total_sales ?? 0, 2) . "\n";
            $p .= "  Net sales:            ₱" . number_format($t->net_sales ?? 0, 2) . "\n";
            $p .= "  Transactions:         "  . number_format($t->total_transactions ?? 0) . "\n";
            $p .= "  Items sold:           "  . number_format($t->total_items_sold ?? 0) . "\n";
            $p .= "  Avg transaction:      ₱" . number_format($t->avg_transaction_value ?? 0, 2) . "\n";
            $p .= "  Discounts given:      ₱" . number_format($t->total_discounts ?? 0, 2) . "\n";
            $p .= "  Return transactions:  "  . number_format($t->total_returns ?? 0) . "\n";
            $p .= "  Total returned:       ₱" . number_format($t->total_return_amt ?? 0, 2) . "\n\n";
        }

        if (!empty($context['week_summary'])) {
            $w = $context['week_summary'];
            $p .= "THIS WEEK'S SALES:\n";
            $p .= "  Total sales:    ₱" . number_format($w->total_sales ?? 0, 2) . "\n";
            $p .= "  Transactions:   "  . number_format($w->total_transactions ?? 0) . "\n";
            $p .= "  Items sold:     "  . number_format($w->total_items_sold ?? 0) . "\n";
            $p .= "  Discounts:      ₱" . number_format($w->total_discounts ?? 0, 2) . "\n\n";
        }

        if (!empty($context['month_summary'])) {
            $m = $context['month_summary'];
            $p .= "THIS MONTH'S SALES:\n";
            $p .= "  Total sales:    ₱" . number_format($m->total_sales ?? 0, 2) . "\n";
            $p .= "  Transactions:   "  . number_format($m->total_transactions ?? 0) . "\n";
            $p .= "  Items sold:     "  . number_format($m->total_items_sold ?? 0) . "\n";
            $p .= "  Discounts:      ₱" . number_format($m->total_discounts ?? 0, 2) . "\n\n";
        }

        if (!empty($context['store_breakdown']) && $context['store_breakdown']->isNotEmpty()) {
            $p .= "SALES BY BRANCH (ranked #1 = highest sales today):\n";
            $rank = 1;
            foreach ($context['store_breakdown'] as $s) {
                $p .= "  #{$rank} {$s->store_name} (Code: {$s->store_code})\n";
                $p .= "      Sales: ₱"        . number_format($s->total_sales, 2)
                    . "  |  Transactions: "    . $s->transactions
                    . "  |  Items sold: "      . $s->items_sold
                    . "  |  Avg: ₱"           . number_format($s->avg_sale, 2) . "\n";
                $rank++;
            }
            $p .= "\n";
        }

        if (!empty($context['hourly_sales']) && $context['hourly_sales']->isNotEmpty()) {
            $p .= "HOURLY BREAKDOWN (today):\n";
            foreach ($context['hourly_sales'] as $h) {
                $p .= "  {$h->hour}:00 → ₱" . number_format($h->total_sales, 2) . "  ({$h->transactions} transactions)\n";
            }
            $p .= "\n";
        }

        if (!empty($context['top_items']) && $context['top_items']->isNotEmpty()) {
            $p .= "TOP SELLING ITEMS TODAY (ranked #1 = most sold):\n";
            $rank = 1;
            foreach ($context['top_items'] as $item) {
                $p .= "  #{$rank} {$item->item_name}\n";
                $p .= "      Qty sold: {$item->total_qty_sold}"
                    . "  |  Revenue: ₱" . number_format($item->total_revenue, 2)
                    . "  |  Profit: ₱"  . number_format($item->gross_profit, 2) . "\n";
                $rank++;
            }
            $p .= "\n";
        }

        if (!empty($context['top_items_month']) && $context['top_items_month']->isNotEmpty()) {
            $p .= "TOP SELLING ITEMS THIS MONTH (ranked #1 = most sold):\n";
            $rank = 1;
            foreach ($context['top_items_month'] as $item) {
                $p .= "  #{$rank} {$item->item_name}  —  {$item->total_qty_sold} sold  |  ₱" . number_format($item->total_revenue, 2) . "\n";
                $rank++;
            }
            $p .= "\n";
        }

        if (!empty($context['cashier_perf']) && $context['cashier_perf']->isNotEmpty()) {
            $period = isset($context['week_summary']) ? 'THIS WEEK' : 'TODAY';
            $p .= "CASHIER PERFORMANCE RANKINGS ({$period}) — ranked #1 = highest sales:\n";
            $rank = 1;
            foreach ($context['cashier_perf'] as $c) {
                $p .= "  #{$rank} {$c->cashier_full_name}  ({$c->store_name})\n";
                $p .= "      Sales: ₱"      . number_format($c->total_sales, 2)
                    . "  |  Transactions: " . $c->transactions
                    . "  |  Items: "        . $c->items_sold
                    . "  |  Avg: ₱"        . number_format($c->avg_transaction, 2) . "\n";
                $rank++;
            }
            $p .= "\n";
        }

        if (!empty($context['returns_disc'])) {
            $rd = $context['returns_disc'];
            $p .= "RETURNS & DISCOUNTS (today):\n";
            $p .= "  Return transactions:  "  . number_format($rd->return_transactions ?? 0) . "\n";
            $p .= "  Total returned:       ₱" . number_format($rd->total_return_amt ?? 0, 2) . "\n";
            $p .= "  Total discounts:      ₱" . number_format($rd->total_discount_amt ?? 0, 2) . "\n";
            $p .= "  Discounted txns:      "  . number_format($rd->discounted_transactions ?? 0) . "\n";
            $p .= "  Avg discount:         ₱" . number_format($rd->avg_discount_amt ?? 0, 2)
                . " (" . number_format($rd->avg_discount_perc ?? 0, 1) . "%)\n\n";
        }

        if (!empty($context['weekly_trend']) && $context['weekly_trend']->isNotEmpty()) {
            $p .= "DAILY SALES TREND (last 7 days):\n";
            foreach ($context['weekly_trend'] as $day) {
                $p .= "  {$day->sale_date} ({$day->day_name}):  ₱" . number_format($day->total_sales, 2)
                    . "  |  {$day->transactions} transactions\n";
            }
            $p .= "\n";
        }

        return $p;
    }

    private function cashierDataBlock(array $ctx): string
    {
        $t = $ctx['today_sales'] ?? (object)[];
        $w = $ctx['week_sales']  ?? (object)[];
        $p = "ROLE: Cashier — show ONLY their own data. Never show other cashiers.\n\n";

        $p .= !empty($t->transactions)
            ? "TODAY: ₱" . number_format($t->total_sales, 2)
                . " | {$t->transactions} txn | {$t->items_sold} items | avg ₱" . number_format($t->avg_transaction, 2)
                . " | disc ₱" . number_format($t->total_discounts, 2)
                . " | returns {$t->returns_processed}\n\n"
            : "TODAY: No transactions yet.\n\n";

        $p .= "THIS WEEK: ₱" . number_format($w->total_sales ?? 0, 2) . " | " . number_format($w->transactions ?? 0) . " txn\n\n";

        if (!empty($ctx['top_items']) && $ctx['top_items']->isNotEmpty()) {
            $p .= "TOP ITEMS TODAY:\n";
            $r = 1;
            foreach ($ctx['top_items'] as $i) {
                $p .= "#{$r} {$i->item_name} — {$i->qty_sold} sold | ₱" . number_format($i->revenue, 2) . "\n";
                $r++;
            }
            $p .= "\n";
        }
        return $p;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OLLAMA CALL — intent-tuned tokens + timeout
    // ─────────────────────────────────────────────────────────────────────────

    private function callOllama(array $messages, string $intent = 'unknown'): string
    {
        $numPredict = match($intent) {
            'greeting', 'capability'                    => 100,
            'learning'                                  => 160,
            'today_summary', 'hourly'                   => 200,
            'top_items', 'cashier_perf'                 => 220,
            'store_list'                                => 120,
            'store_compare', 'returns'                  => 180,
            'trend', 'weekly', 'monthly'                => 250,
            'cashier_self'                              => 180,
            'full_report'                => 380,
            default                      => 250, // unknown gets more tokens to reason freely
        };

        $timeout = match($intent) {
            'greeting', 'capability', 'learning' => 90,
            'full_report'                         => 600,
            default                               => 300,
        };

        $payload = json_encode([
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'temperature' => $this->config['temperature'] ?? 0.3,
                'num_predict' => $numPredict,
            ],
        ]);

        $ch = curl_init("{$this->ollamaUrl}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr)          throw new \Exception("cURL: {$curlErr}");
        if ($httpCode !== 200) throw new \Exception("Ollama {$httpCode}: " . substr($response, 0, 200));

        $data = json_decode($response, true);
        return trim($data['message']['content'] ?? 'Sorry, I could not generate a response.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REST ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────────

    public function history(Request $request)
    {
        $userId = (int) $request->header('X-User-Id', 0);
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);
        $q = DB::table('ai_chat_messages')->where('user_id', $userId)->whereNull('archived_at')->orderBy('created_at', 'asc');
        if ($sid = $request->query('session_id')) $q->where('session_id', $sid);
        return response()->json(['success' => true, 'data' => $q->get(['id', 'role', 'content', 'session_id', 'created_at'])]);
    }

    public function clear(Request $request)
    {
        $userId = (int) $request->header('X-User-Id', 0);
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);
        DB::table('ai_chat_messages')->where('user_id', $userId)->whereNull('archived_at')->update(['archived_at' => now()]);
        return response()->json(['success' => true, 'message' => 'Chat history cleared.']);
    }

    public function suggestions(Request $request)
    {
        $userId      = (int) $request->header('X-User-Id', 0);
        $accessLevel = $request->header('X-Access-Level', 'user');
        if (!$userId) return response()->json(['error' => 'Unauthorized'], 401);

        $isAdmin   = in_array($accessLevel, ['admin', 'super_admin', 'system_admin']);
        $isManager = in_array($accessLevel, ['manager', 'store_owner']) || $isAdmin;

        if ($isAdmin) {
            $s = ["How are all branches performing today?", "What are the top selling items this month?", "Show me today's cashier rankings"];
        } elseif ($isManager) {
            $s = ["How are we doing with sales today?", "What are our top items today?", "How is my team performing this week?"];
        } else {
            $user  = DB::table('users')->where('id', $userId)->first();
            $name  = $user?->full_name ?? '';
            try { $t = $this->getCashierOwnSales($name, 'today'); $has = !empty($t->transactions); }
            catch (\Exception $e) { $has = false; }
            $s = [
                $has ? "How am I doing with sales today?" : "Have I made any sales today?",
                "What items have I sold the most today?",
                "How does my week look so far?",
            ];
        }
        return response()->json(['success' => true, 'data' => $s]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEBUG — describes exactly where each data block came from
    // Visible in _debug.data_sources in every API response
    // ─────────────────────────────────────────────────────────────────────────

    private function describeDataSources(array $context): array
    {
        if (($context['queries_run'] ?? 0) === 0) {
            return ['source' => 'No DB queries — zero-query intent (Ollama only)'];
        }

        $s    = [];
        $type = $context['type'] ?? 'unknown';
        $s['role']          = $type;
        $s['oracle_host']   = env('DB_ORACLE_HOST', '?') . ':' . env('DB_ORACLE_PORT', '1521');
        $s['oracle_schema'] = 'RPS';
        $s['cache_ttl_s']   = $this->cacheTtl;

        if (!empty($context['store_list']))      $s['store_list']      = 'RPS.STORE → ACTIVE=1, ORDER BY STORE_NAME';
        if (!empty($context['today_summary']))   $s['today_summary']   = 'RPS.DOCUMENT → STATUS=4, HAS_SALE=1, today';
        if (!empty($context['week_summary']))    $s['week_summary']    = 'RPS.DOCUMENT → STATUS=4, HAS_SALE=1, this week';
        if (!empty($context['month_summary']))   $s['month_summary']   = 'RPS.DOCUMENT → STATUS=4, HAS_SALE=1, this month';
        if (!empty($context['store_breakdown'])) $s['store_breakdown'] = 'RPS.DOCUMENT → grouped by STORE_CODE';
        if (!empty($context['hourly_sales']))    $s['hourly_sales']    = 'RPS.DOCUMENT → grouped by hour (TZ +08:00)';
        if (!empty($context['top_items']))       $s['top_items']       = 'RPS.DOCUMENT_ITEM JOIN DOCUMENT → SUM(QTY) today';
        if (!empty($context['top_items_month'])) $s['top_items_month'] = 'RPS.DOCUMENT_ITEM JOIN DOCUMENT → SUM(QTY) month';
        if (!empty($context['cashier_perf']))    $s['cashier_perf']    = 'RPS.DOCUMENT → grouped by CASHIER_FULL_NAME';
        if (!empty($context['returns_disc']))    $s['returns_disc']    = 'RPS.DOCUMENT → RETURN_SUBTOTAL + TOTAL_DISCOUNT_AMT';
        if (!empty($context['weekly_trend']))    $s['weekly_trend']    = 'RPS.DOCUMENT → last 7 days grouped by date';
        if (!empty($context['today_sales']))     $s['cashier_today']   = 'RPS.DOCUMENT → CASHIER_FULL_NAME filter (today)';
        if (!empty($context['week_sales']))      $s['cashier_week']    = 'RPS.DOCUMENT → CASHIER_FULL_NAME filter (week)';

        return $s;
    }

    private function saveMessage(int $userId, string $role, string $content, ?string $sessionId = null): void
    {
        DB::table('ai_chat_messages')->insert([
            'user_id' => $userId, 'role' => $role, 'content' => $content,
            'session_id' => $sessionId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
