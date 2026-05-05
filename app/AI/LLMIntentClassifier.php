<?php

namespace App\AI;

use Illuminate\Support\Facades\Log;

/**
 * LLMIntentClassifier — Aria by GenieX
 *
 * FILE LOCATION: app/AI/LLMIntentClassifier.php
 *
 * Uses a focused, low-token Ollama call to classify the user's message into
 * a structured intent object. Runs async via curl_multi so Oracle prefetch
 * can happen in parallel while the LLM is thinking.
 *
 * Returns:
 *   intent     — one of the VALID_INTENTS
 *   period     — today | yesterday | this_week | this_month | this_year | all_time | null
 *   store      — store name as mentioned, or null
 *   cashier    — cashier name as mentioned, or null
 *   confidence — float 0.0–1.0
 *   source     — 'llm' | 'fallback'
 */
class LLMIntentClassifier
{
    private string $ollamaUrl;
    private string $model;
    private float  $confidenceThreshold;

    public const VALID_INTENTS = [
        'today_summary',
        'top_items',
        'cashier_perf',
        'cashier_self',
        'store_list',
        'store_compare',
        'returns',
        'hourly',
        'trend',
        'weekly',
        'monthly',
        'yearly',
        'full_report',
        'greeting',
        'learning',
        'capability',
        'unknown',
    ];

    public const VALID_PERIODS = [
        'today',
        'yesterday',
        'this_week',
        'this_month',
        'this_year',
        'all_time',
    ];

    // Maps LLM period labels → internal period strings used by Oracle queries
    public const PERIOD_MAP = [
        'today'      => 'today',
        'yesterday'  => 'yesterday',
        'this_week'  => 'week',
        'this_month' => 'month',
        'this_year'  => 'year',
        'all_time'   => 'all_time',
    ];

    public function __construct(
        string $ollamaUrl,
        string $model,
        float  $confidenceThreshold = 0.70
    ) {
        $this->ollamaUrl           = rtrim($ollamaUrl, '/');
        $this->model               = $model;
        $this->confidenceThreshold = $confidenceThreshold;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BUILD ASYNC CURL HANDLE
    // Caller adds this to a curl_multi handle, runs it alongside Oracle queries,
    // then calls parseMultiResponse() to get the result.
    // ─────────────────────────────────────────────────────────────────────────

    public function buildAsyncHandle(string $message, array $recentHistory = []): \CurlHandle
    {
        $payload = json_encode([
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildClassifierPrompt()],
                ['role' => 'user',   'content' => $this->buildClassifierInput($message, $recentHistory)],
            ],
            'stream'  => false,
            'options' => [
                'temperature' => 0.0, // deterministic — we want exact labels every time
                'num_predict' => 80,  // just enough for the JSON blob
            ],
        ]);

        $ch = curl_init("{$this->ollamaUrl}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        return $ch;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PARSE RESPONSE FROM curl_multi_getcontent
    // ─────────────────────────────────────────────────────────────────────────

    public function parseMultiResponse(\CurlHandle $ch): array
    {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        if ($curlErr || $httpCode !== 200 || empty($response)) {
            Log::warning('[LLMClassifier] cURL failed', [
                'error' => $curlErr,
                'http'  => $httpCode,
            ]);
            return $this->fallbackResult();
        }

        $data    = json_decode($response, true);
        $content = trim($data['message']['content'] ?? '');

        return $this->parseClassifierOutput($content);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SYNCHRONOUS CLASSIFY — for testing or when parallelism isn't needed
    // ─────────────────────────────────────────────────────────────────────────

    public function classifySync(string $message, array $recentHistory = []): array
    {
        $ch = $this->buildAsyncHandle($message, $recentHistory);
        curl_exec($ch);
        $result = $this->parseMultiResponse($ch);
        curl_close($ch);
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIDENCE CHECK
    // ─────────────────────────────────────────────────────────────────────────

    public function isConfident(array $result): bool
    {
        return ($result['confidence'] ?? 0.0) >= $this->confidenceThreshold;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESOLVE INTERNAL PERIOD STRING
    // Converts LLM period label (e.g. 'this_month') → Oracle period ('month')
    // ─────────────────────────────────────────────────────────────────────────

    public function resolveInternalPeriod(?string $llmPeriod): string
    {
        return self::PERIOD_MAP[$llmPeriod ?? 'today'] ?? 'today';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL: CLASSIFIER SYSTEM PROMPT
    // ─────────────────────────────────────────────────────────────────────────

    private function buildClassifierPrompt(): string
    {
        $intents = implode(', ', self::VALID_INTENTS);
        $periods = implode(', ', self::VALID_PERIODS);

        return <<<PROMPT
You are an intent classifier for Aria, a retail sales AI assistant.

Your ONLY job: read the user's message and return a JSON object.
No explanation. No preamble. No markdown fences. Just raw JSON.

VALID INTENTS:
{$intents}

What each intent means:
- today_summary   → overall sales today: revenue, transactions, net sales
- top_items       → best-selling products or items by quantity
- cashier_perf    → manager asking about cashier rankings or team performance
- cashier_self    → cashier asking about their own personal sales
- store_list      → how many stores/branches exist
- store_compare   → comparing sales across multiple stores or branches
- returns         → returns, refunds, or discounts
- hourly          → hourly sales breakdown or peak hours
- trend           → sales trend or direction over multiple days
- weekly          → this week's summary
- monthly         → this month's summary
- yearly          → this year's summary
- full_report     → complete report covering everything available
- greeting        → hello, hi, good morning, small talk, how are you
- learning        → courses, training, LMS, learning progress
- capability      → what can Aria do, what do you know
- unknown         → cannot determine, or none of the above

VALID PERIODS:
{$periods}

Period meanings:
- today      → today, now, this morning, this afternoon, tonight, so far today
- yesterday  → yesterday, last night
- this_week  → this week, week so far, since Monday
- this_month → this month, month so far, monthly
- this_year  → this year, year to date, annual, yearly
- all_time   → ever, all time, since the beginning, total history

RESPOND ONLY WITH THIS EXACT JSON FORMAT (no other text whatsoever):
{
  "intent":     "<one valid intent>",
  "period":     "<one valid period, or null if not mentioned>",
  "store":      "<store name exactly as user mentioned, or null>",
  "cashier":    "<cashier name exactly as user mentioned, or null>",
  "confidence": <float 0.0 to 1.0>
}
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL: BUILD CLASSIFIER INPUT WITH RECENT CONTEXT
    // Includes last 3 turns so follow-up questions resolve correctly.
    // e.g. "how about yesterday?" after asking about today.
    // ─────────────────────────────────────────────────────────────────────────

    private function buildClassifierInput(string $message, array $recentHistory): string
    {
        $context = '';

        if (!empty($recentHistory)) {
            $last3   = array_slice($recentHistory, -3);
            $context = "Recent conversation:\n";
            foreach ($last3 as $h) {
                $role    = ucfirst($h['role'] ?? 'user');
                $preview = mb_substr($h['content'] ?? '', 0, 120);
                $context .= "{$role}: {$preview}\n";
            }
            $context .= "\n";
        }

        return "{$context}Current message to classify: {$message}";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL: PARSE AND VALIDATE LLM OUTPUT
    // ─────────────────────────────────────────────────────────────────────────

    private function parseClassifierOutput(string $raw): array
    {
        // Strip markdown code fences if model added them despite instructions
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);

        if (!is_array($parsed)) {
            Log::warning('[LLMClassifier] Could not parse JSON output', [
                'raw'     => $raw,
                'cleaned' => $cleaned,
            ]);
            return $this->fallbackResult();
        }

        $intent = in_array($parsed['intent'] ?? '', self::VALID_INTENTS)
            ? $parsed['intent']
            : 'unknown';

        $period = in_array($parsed['period'] ?? '', self::VALID_PERIODS)
            ? $parsed['period']
            : null;

        $confidence = is_numeric($parsed['confidence'] ?? null)
            ? min(1.0, max(0.0, (float) $parsed['confidence']))
            : 0.5;

        $store = isset($parsed['store'])
            && is_string($parsed['store'])
            && $parsed['store'] !== 'null'
            && $parsed['store'] !== ''
                ? trim($parsed['store']) : null;

        $cashier = isset($parsed['cashier'])
            && is_string($parsed['cashier'])
            && $parsed['cashier'] !== 'null'
            && $parsed['cashier'] !== ''
                ? trim($parsed['cashier']) : null;

        Log::info('[LLMClassifier] ✅ Classified', [
            'intent'     => $intent,
            'period'     => $period,
            'store'      => $store,
            'cashier'    => $cashier,
            'confidence' => $confidence,
        ]);

        return [
            'intent'     => $intent,
            'period'     => $period,
            'store'      => $store,
            'cashier'    => $cashier,
            'confidence' => $confidence,
            'source'     => 'llm',
        ];
    }

    private function fallbackResult(): array
    {
        return [
            'intent'     => 'unknown',
            'period'     => null,
            'store'      => null,
            'cashier'    => null,
            'confidence' => 0.0,
            'source'     => 'fallback',
        ];
    }
}
