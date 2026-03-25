<?php

namespace App\AI;

/**
 * IntentDetector — Aria by GenieX
 * ─────────────────────────────────────────────────────────────────────────────
 * Classifies every incoming message into a named intent BEFORE any database
 * query or AI call runs. This keeps the controller lean and makes it trivial
 * to add new intents without touching business logic.
 *
 * INTENT CATALOGUE
 * ────────────────
 * Zero-query intents (no Oracle, no Ollama for instant responses):
 *   greeting          — hi, hello, good morning, etc.
 *   farewell          — bye, see you, take care
 *   thanks            — thank you, thanks, ty
 *   how_are_you       — how are you, you okay
 *   who_are_you       — who are you, introduce yourself
 *   who_created_you   — who made you, who built you, clarence
 *   what_is_geniex    — what is geniex, about geniex, what do you offer
 *   what_can_you_do   — what can you do, your capabilities
 *   can_access_sales  — can you access sales, do you have data
 *   what_is_platform  — what is the lms, what platform is this
 *   industries        — what industries do you serve
 *   help_general      — help, i need help
 *
 * Learning intents (no Oracle needed):
 *   learning          — course, progress, module, quiz, enrollment
 *
 * Sales intents (Oracle queries):
 *   today_summary     — today, how are we doing, sales today
 *   top_items         — top items, best sellers, most sold
 *   cashier_perf      — cashier rankings, top earners, staff performance
 *   store_compare     — branch comparison, per store, which branch
 *   returns           — returns, refunds, discounts, voids
 *   hourly            — peak hours, hourly breakdown, busiest time
 *   trend             — daily trend, last 7 days, yesterday
 *   weekly            — this week, weekly summary
 *   monthly           — this month, monthly overview
 *   yearly            — this year, annual, ytd
 *   cashier_self      — (cashier role only) my sales, how am i doing
 *   full_report       — full report, complete overview, everything
 *
 * Fallback:
 *   unknown           — anything not matched (defaults to today_summary)
 */
class IntentDetector
{
    // ── Role constants ────────────────────────────────────────────────────────
    private const ADMIN_ROLES   = ['admin', 'super_admin', 'system_admin'];
    private const MANAGER_ROLES = ['manager', 'store_owner'];
    private const CASHIER_ROLES = ['cashier', 'user', 'staff', 'employee'];

    // ── Intent definitions ────────────────────────────────────────────────────
    // Each entry: [ 'intent_name' => [ patterns... ] ]
    // Patterns support:
    //   - plain string  → str_contains match
    //   - /regex/       → preg_match
    //   - *string*      → whole-message-only match (for short greetings)

    private const INTENTS = [

        // ── Zero-query / instant ──────────────────────────────────────────────

        'greeting' => [
            '/^(hi|hello|hey|yo|sup|howdy|hiya|heya|oi|helo|hei)\b/i',
            '/^good (morning|afternoon|evening|day|noon)\b/i',
            '/^(good morning|good afternoon|good evening|good day)$/i',
            '/^(morning|afternoon|evening|mornin|evenin)[\s!.]*$/i',
            '/^(kumusta|kamusta|musta|hoy|uy)\b/i',
            '/^(what\'?s up|wassup|wazzup|whats up|howdy)\b/i',
        ],

        'farewell' => [
            '/^(bye|goodbye|good bye|see you|see ya|take care|ciao|later|ttyl|gtg|gotta go)\b/i',
            '/^(have a good|have a great|have a nice)\b/i',
            '/^(ingat|paalam|sige na|sige bye)\b/i',
            '/^(till next time|until next time|catch you later|talk later)\b/i',
        ],

        'thanks' => [
            '/^(thank(s| you)|ty|thx|cheers|much appreciated|appreciate it|nice one|salamat)\b/i',
            '/^(thanks aria|thank you aria|ty aria|salamat aria)\b/i',
            '/that (was |is )?(great|helpful|awesome|perfect|amazing|fantastic|excellent|superb)/i',
            '/^(great|perfect|awesome|excellent|nice|good job|well done|amazing)[\s!.]*$/i',
            '/you\'?re (the best|amazing|great|helpful|awesome)/i',
        ],

        'how_are_you' => [
            '/how are you/i',
            '/how\'?re you/i',
            '/are you (ok|okay|good|doing well|alright|fine)/i',
            '/how is aria/i',
            '/you doing (ok|okay|good|well|fine)/i',
            '/kamusta ka/i',
            '/how do you feel/i',
            '/are you (there|awake|online|active|working)/i',
        ],

        'who_are_you' => [
            '/who are you/i',
            '/what are you/i',
            '/tell me about yourself/i',
            '/introduce yourself/i',
            '/are you (an? )?(ai|bot|robot|assistant|human|person|real)/i',
            '/are you aria/i',
            '/what\'?s your name/i',
            '/who (am i|are you) talking to/i',
            '/who is aria/i',
            '/what is aria/i',
        ],

        'who_created_you' => [
            '/who (made|built|created|designed|developed|programmed|coded) you/i',
            '/who is your (creator|developer|maker|author|programmer|owner)/i',
            '/who (made|built|created|developed) aria/i',
            '/who (is|\'?s) clarence/i',
            '/clarence/i',
            '/your (creator|developer|maker|owner|builder)/i',
            '/who (is responsible|owns you|runs you)/i',
        ],

        'what_is_geniex' => [
            '/what is geniex/i',
            '/tell me about geniex/i',
            '/about geniex/i',
            '/what does geniex (do|offer|provide|sell)/i',
            '/what (is|are) (geniex\'?s?|your) (services?|product|platform|offering|solution)/i',
            '/how does geniex work/i',
            '/geniex (overview|summary|background|history|mission|vision)/i',
            '/what\'?s geniex/i',
            '/geniex company/i',
            '/what kind of company is geniex/i',
        ],

        'what_can_you_do' => [
            '/what can you (do|help|answer|tell|show|assist)/i',
            '/what (do|can) you (know|cover|handle|support|access)/i',
            '/your (capabilities|features|functions|abilities|skills)/i',
            '/what can aria (do|help|access|show)/i',
            '/what (topics|questions) (can|do) you (cover|answer|handle)/i',
            '/help me understand what you (can|are able to)/i',
            '/what (are you|is aria) capable of/i',
            '/show me what you can do/i',
            '/what do you have access to/i',
            '/what (reports?|data|info|information) can you (show|give|provide|pull|access)/i',
        ],

        'can_access_sales' => [
            '/can you (access|see|check|view|get|pull|fetch|read) (sales|data|numbers|figures|transactions|reports?)/i',
            '/do you have (access to|the) (sales|data|numbers|live|real.?time)/i',
            '/are you (connected|linked|integrated) (to|with)/i',
            '/access (to )?(sales|data|live|the (database|system|pos))/i',
            '/do you (have|see|know) (our|the) (live|real.?time|current|actual) (data|sales|numbers)/i',
            '/is your data (live|real.?time|current|up.?to.?date)/i',
        ],

        'what_is_platform' => [
            '/what is (the |this |our )?(lms|learning (platform|system|management))/i',
            '/what (platform|system) (is this|are we using|do we have)/i',
            '/tell me about (the |this |our )?(platform|lms|learning system)/i',
            '/how does (the |this )?(lms|platform|learning) work/i',
            '/what (courses?|training) (are|is) available/i',
            '/what (course )?categories (do you have|are there|are available)/i',
        ],

        'industries' => [
            '/what (industries|sectors|businesses|clients|types of (business|company)) (do you|does geniex) (serve|work with|support|help|target)/i',
            '/who (are|is) (your|geniex\'?s?) (clients?|customers?|target market)/i',
            '/what (kind|type) of (business|company|organization) (do you|does geniex) (serve|work with)/i',
            '/industry/i',
        ],

        'help_general' => [
            '/^(help|help me|i need help|need help|support|assist me|assistance)\.?!?$/i',
            '/how (do|can) i (use|get started with|navigate|access) (aria|this|geniex)/i',
            '/where (do|can) i (start|begin|find)/i',
            '/i\'?m (lost|confused|not sure|stuck)/i',
            '/not sure (how|what|where|who)/i',
        ],

        // ── Learning intents ──────────────────────────────────────────────────

        'learning' => [
            '/\bcourse\b/i',
            '/\bcourses\b/i',
            '/\blearning\b/i',
            '/\bprogress\b/i',
            '/\benroll(ment|ed)?\b/i',
            '/\bmodule\b/i',
            '/\blesson\b/i',
            '/\btraining\b/i',
            '/\blms\b/i',
            '/\bcompletion\b/i',
            '/\bcertificate\b/i',
            '/\bquiz\b/i',
            '/\bassessment\b/i',
            '/\bstudy\b/i',
            '/my (courses?|learning|progress|training|modules?)/i',
            '/how (am i doing|do i) (with|on|in) (my )?(course|training|learning|progress)/i',
            '/what (courses?|training) (should i|do i need to|can i)/i',
            '/complete (the |this |a )?course/i',
            '/pending (course|training|module)/i',
            '/finished (course|module|training)/i',
        ],

        // ── Sales intents ─────────────────────────────────────────────────────

        'full_report' => [
            '/full (report|summary|overview|breakdown)/i',
            '/complete (report|summary|overview|breakdown)/i',
            '/give me (everything|all (the )?(data|numbers|figures|info))/i',
            '/overall (report|summary|overview|performance)/i',
            '/all (data|stores?|branches?|figures|numbers)/i',
            '/entire (report|summary|overview)/i',
            '/comprehensive (report|summary|overview)/i',
            '/big picture/i',
            '/everything (today|this week|this month|this year)/i',
            '/complete (picture|view|breakdown)/i',
            '/full (picture|view|breakdown)/i',
            '/run (the |a )?full report/i',
            '/show (me )?everything/i',
        ],

        'yearly' => [
            '/this year/i',
            '/\bannual\b/i',
            '/\byearly\b/i',
            '/\bytd\b/i',
            '/year to date/i',
            '/year\'?s (sales|performance|total|revenue|figures|numbers)/i',
            '/past (12|twelve) months/i',
            '/for (the )?year/i',
            '/since january/i',
            '/full year/i',
            '/annual (total|sales|performance|summary|report)/i',
        ],

        'monthly' => [
            '/this month/i',
            '/\bmonthly\b/i',
            '/month so far/i',
            '/current month/i',
            '/\bmtd\b/i',
            '/month to date/i',
            '/past (30|thirty) days/i',
            '/last month/i',
            '/for (the )?month/i',
            '/month\'?s (sales|performance|total|revenue|figures|numbers)/i',
            '/monthly (total|summary|overview|breakdown|report|figures)/i',
            '/(january|february|march|april|may|june|july|august|september|october|november|december)/i',
        ],

        'weekly' => [
            '/this week/i',
            '/\bweekly\b/i',
            '/week so far/i',
            '/past week/i',
            '/last 7 days/i',
            '/\bwtd\b/i',
            '/week to date/i',
            '/for (the )?week/i',
            '/week\'?s (sales|performance|total|revenue|figures|numbers)/i',
            '/7.?day/i',
            '/weekly (total|summary|overview|breakdown|report|figures)/i',
            '/(monday|tuesday|wednesday|thursday|friday|saturday|sunday).*(week|sales)/i',
        ],

        'top_items' => [
            '/top (selling |)item/i',
            '/best.?sell(er|ing)/i',
            '/most (sold|popular|ordered|purchased|bought)/i',
            '/popular item/i',
            '/top product/i',
            '/top seller/i',
            '/what\'?s (selling|moving)/i',
            '/selling (the )?most/i',
            '/top dish(es)?/i',
            '/best (item|product|dish|food|menu)/i',
            '/highest (selling|revenue|sales) item/i',
            '/item (ranking|performance|breakdown)/i',
            '/product (ranking|performance|breakdown)/i',
            '/menu (performance|ranking|breakdown)/i',
            '/what (items?|products?|dishes?) (are )?(perform|sell|do)ing (well|best)/i',
            '/which (item|product|dish) (sells?|sold|is selling) (the )?most/i',
            '/fast.?moving (item|product)/i',
            '/slow.?moving (item|product)/i',
            '/item (sales|revenue|performance)/i',
            '/product (sales|revenue|performance)/i',
            '/what (are|were|is) (people |customers? )?(buying|ordering|purchasing)/i',
        ],

        'cashier_perf' => [
            '/\bcashier\b/i',
            '/staff (performance|ranking|comparison|standings?)/i',
            '/who (sold|earned|made) (the )?most/i',
            '/top (performer|earner|salespers)/i',
            '/best (cashier|salespers|performer|earner)/i',
            '/cashier (ranking|comparison|performance|breakdown|stats|standings?|list)/i',
            '/employee (performance|ranking|sales|stats)/i',
            '/who is (performing|leading|on top|winning|ahead)/i',
            '/top earner/i',
            '/highest earner/i',
            '/sales leader/i',
            '/who (is|\'?s) #?1/i',
            '/team (performance|ranking|sales|standings?)/i',
            '/staff ranking/i',
            '/sales ?person(nel)?/i',
            '/salesperson/i',
            '/who (performed|did) (the )?best/i',
            '/who has (the )?most (sales|transactions)/i',
            '/leading (cashier|salespers|performer)/i',
            '/how is (the )?team (doing|performing)/i',
            '/how (are|is) (my|the) (staff|team|crew|cashiers?) (doing|performing)/i',
            '/rank (the )?(cashiers?|staff|team|employees?)/i',
            '/employee rankings?/i',
            '/teller (performance|ranking)/i',
            '/who (is )?performing (best|well|most)/i',
        ],

        // ── Simple fact intents (1 query, no Ollama) ─────────────────────────
        // Driven by aria_simple_facts.json — patterns here must mirror that file

        'store_count' => [
            '/how many (store|branch|location)/i',
            '/count.*(store|branch|location)/i',
            '/number of (store|branch|location)/i',
            '/total (store|branch|location)/i',
        ],

        'cashier_count' => [
            '/how many cashier/i',
            '/count.*(cashier|staff|employee)/i',
            '/number of (cashier|staff|employee)/i',
            '/total (cashier|staff|employee)/i',
        ],

        'item_count' => [
            '/how many (item|product|dish)/i',
            '/count.*(item|product|dish)/i',
            '/number of (item|product|dish)/i',
            '/total (item|product|dish) sold/i',
        ],

        'transaction_count' => [
            '/how many transaction/i',
            '/count.*(transaction|order|sale)/i',
            '/number of transaction/i',
            '/total transaction/i',
            '/transaction count/i',
        ],

        'customer_count' => [
            '/how many customer/i',
            '/count.*customer/i',
            '/number of customer/i',
            '/total customer/i',
            '/customer count/i',
        ],

        'store_list' => [
            '/name.*stores?/i',
            '/list.*stores?/i',
            '/list.*branches?/i',
            '/name.*branches?/i',
            '/what stores?/i',
            '/which stores?/i',
            '/our stores?/i',
            '/our branches?/i',
            '/how many stores?/i',
            '/how many branches?/i',
            '/what branches?/i',
            '/which branches?/i',
            '/show.*stores?/i',
            '/show.*branches?/i',
            '/all (our )?(stores?|branches?|locations?)/i',
            '/what locations?/i',
            '/which locations?/i',
            '/how many locations?/i',
            '/our locations?/i',
            '/name.*locations?/i',
            '/list.*locations?/i',
            '/enumerate.*(stores?|branches?|locations?)/i',
            '/do (we|you) have (stores?|branches?|locations?)/i',
            '/where are (our|the|your) (stores?|branches?|locations?)/i',
        ],

        'store_compare' => [
            '/\bbranch(es)?\b/i',
            '/\bstore(s)?\b/i',
            '/which (store|branch|location) (is|has|did|performed|leads?|topped?)/i',
            '/compare (stores?|branches?|locations?)/i',
            '/(store|branch|location) (performance|comparison|breakdown|ranking|standings?)/i',
            '/per (store|branch|location)/i',
            '/by (store|branch|location)/i',
            '/across (stores?|branches?|locations?)/i',
            '/each (store|branch|location)/i',
            '/top (store|branch|location)/i',
            '/best (store|branch|location)/i',
            '/worst (performing )?(store|branch|location)/i',
            '/highest (performing )?(store|branch|location)/i',
            '/lowest (performing )?(store|branch|location)/i',
            '/leading (store|branch|location)/i',
            '/(store|branch|location) (vs|versus)/i',
            '/how (did|is|are) (each|every|all) (store|branch|location)/i',
        ],

        'returns' => [
            '/\breturn(s|ed)?\b/i',
            '/\brefund(s|ed)?\b/i',
            '/\bdiscount(s|ed)?\b/i',
            '/\bvoid(s|ed)?\b/i',
            '/cancelled (transaction|order|sale)/i',
            '/reversal/i',
            '/how much (was |were )?(returned|refunded|discounted|voided)/i',
            '/(return|refund|discount|void) (rate|amount|total|summary|count)/i',
            '/promo (discount|usage|summary)/i',
            '/how many (returns?|refunds?|voids?|discounts?)/i',
            '/total (returns?|refunds?|discounts?|voids?)/i',
            '/discount (usage|applied|given|total|amount)/i',
            '/any (returns?|refunds?|voids?) (today|this week|this month)/i',
        ],

        'hourly' => [
            '/\bhour(ly|s)?\b/i',
            '/peak (hour|time|period)/i',
            '/busiest (hour|time|period|part of the day)/i',
            '/what time (did|does|do|is|are)/i',
            '/per hour/i',
            '/rush hour/i',
            '/slow (hour|period|time)/i',
            '/time of day/i',
            '/breakdown by (hour|time)/i',
            '/when (is|was|are|were) (sales|transactions) (highest|lowest|best|most|peak)/i',
            '/what (hour|time) (had|has|gets?) (the )?(most|highest|best)/i',
            '/hour.?by.?hour/i',
            '/by the hour/i',
            '/which hour/i',
            '/at what time/i',
            '/slowest (time|hour|period)/i',
            '/best (time|hour) (of day|for sales)/i',
            '/morning (vs|versus|compared to) afternoon/i',
        ],

        'trend' => [
            '/\btrend\b/i',
            '/day.?by.?day/i',
            '/last (few|[0-9]+) days/i',
            '/recent days/i',
            '/compare days/i',
            '/\byesterday\b/i',
            '/daily (trend|breakdown|summary|performance)/i',
            '/over (the )?(past|last) (few )?(days|week)/i',
            '/how (have|has) (sales|numbers|performance) (been|changed|trended)/i',
            '/sales (history|pattern|over time)/i',
            '/this vs (last|previous)/i',
            '/going up|going down/i',
            '/is (sales|revenue|performance) (improving|declining|growing|dropping|falling)/i',
            '/(sales|revenue|performance) (over|across) (the )?(past|last) (few )?days/i',
            '/how (was|were) (sales|numbers) (recently|lately|this past)/i',
            '/daily (figures|numbers|data|performance)/i',
            '/past few days/i',
            '/recent (sales|performance|transactions)/i',
            '/compared to (yesterday|last (week|month|year))/i',
        ],

        'today_summary' => [
            '/\btoday\b/i',
            '/right now/i',
            '/so far today/i',
            '/current (sales|numbers|figures|total|performance)/i',
            '/how (are|is|we doing|\'?s it going)/i',
            '/what\'?s (the )?(total|revenue|sales|numbers?|figure|status)/i',
            '/how (much|many) (did we|have we|did the|sales)/i',
            '/sales (today|now|currently|so far)/i',
            '/daily (total|summary|sales|revenue)/i',
            '/today\'?s (sales|total|revenue|figures|numbers|performance)/i',
            '/how (are|is) (business|things|we) (doing|going|looking)/i',
            '/what\'?s (happening|going on) (today|right now)/i',
            '/update (me|on sales|on today)/i',
            '/give me (a |an )?(update|summary|snapshot|overview)/i',
            '/quick (update|summary|look|check)/i',
            '/any (sales|transactions|activity) (today|so far)/i',
            '/how (did|have) (we|things) (do|gone|been) (today|so far)/i',
            '/status (update|report|check)/i',
        ],

        'cashier_self' => [
            '/my (sales|performance|numbers|transactions|figures|total|stats)/i',
            '/how (am i|am i doing|have i been|\'?m i doing)/i',
            '/how did i (do|perform)/i',
            '/my (daily|today\'?s|this week\'?s|weekly|monthly) (sales|performance|numbers)/i',
            '/what (have i|did i) (sold|made|earned|done)/i',
            '/my (ranking|rank|standing|position)/i',
            '/where (do i|am i) rank/i',
            '/how many (sales|transactions|items) (did i|have i)/i',
            '/my top (items?|products?|sellers?)/i',
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect the intent of a message.
     *
     * @param  string $message     Raw user message
     * @param  string $accessLevel User role (admin/manager/cashier/etc.)
     * @return string              Intent name
     */
    public function detect(string $message, string $accessLevel): string
    {
        $m = mb_strtolower(trim($message));

        // Cashiers always get their own context — route immediately
        if ($this->isCashier($accessLevel)) {
            // Still check for learning/general intents for cashiers
            if ($this->matchesGroup($m, ['learning', 'greeting', 'farewell', 'thanks',
                'how_are_you', 'who_are_you', 'who_created_you', 'what_is_geniex',
                'what_can_you_do', 'can_access_sales', 'what_is_platform',
                'industries', 'help_general'])) {
                return $this->matchGroup($m, ['learning', 'greeting', 'farewell', 'thanks',
                    'how_are_you', 'who_are_you', 'who_created_you', 'what_is_geniex',
                    'what_can_you_do', 'can_access_sales', 'what_is_platform',
                    'industries', 'help_general']);
            }
            return 'cashier_self';
        }

        // Ordered intent check — more specific first
        $order = [
            // Zero-query instant intents first
            'greeting', 'farewell', 'thanks', 'how_are_you',
            'who_are_you', 'who_created_you',
            'what_is_geniex', 'what_can_you_do', 'can_access_sales',
            'what_is_platform', 'industries', 'help_general',
            // Learning
            'learning',
            // Simple fact intents — before generic sales (1 query, no Ollama)
            'store_count', 'cashier_count', 'item_count', 'transaction_count', 'customer_count',
            // Sales — specific before general
            'full_report',
            'yearly', 'monthly', 'weekly',
            'top_items', 'cashier_perf', 'store_list', 'store_compare',
            'returns', 'hourly', 'trend',
            'today_summary',
        ];

        foreach ($order as $intent) {
            if ($this->matches($m, $intent)) {
                return $intent;
            }
        }

        return 'unknown';
    }

    /**
     * Whether the intent requires zero Oracle queries.
     */
    public function isInstant(string $intent): bool
    {
        return in_array($intent, [
            'greeting', 'farewell', 'thanks', 'how_are_you',
            'who_are_you', 'who_created_you', 'what_is_geniex',
            'what_can_you_do', 'can_access_sales', 'what_is_platform',
            'industries', 'help_general',
        ]);
    }

    /**
     * Whether the intent needs zero Oracle queries (instant + learning).
     */
    public function isZeroQuery(string $intent): bool
    {
        return $this->isInstant($intent) || $intent === 'learning';
    }

    /**
     * Whether the intent is a sales-data intent.
     */
    public function isSalesIntent(string $intent): bool
    {
        return in_array($intent, [
            'today_summary', 'top_items', 'cashier_perf', 'store_list', 'store_compare',
            'returns', 'hourly', 'trend', 'weekly', 'monthly', 'yearly',
            'cashier_self', 'full_report', 'unknown',
            'store_count', 'cashier_count', 'item_count', 'transaction_count', 'customer_count',
        ]);
    }

    /**
     * Human-readable label for logging.
     */
    public function label(string $intent): string
    {
        return match($intent) {
            'greeting'         => 'Greeting',
            'farewell'         => 'Farewell',
            'thanks'           => 'Thanks',
            'how_are_you'      => 'How are you',
            'who_are_you'      => 'Who are you',
            'who_created_you'  => 'Who created you',
            'what_is_geniex'   => 'What is GenieX',
            'what_can_you_do'  => 'What can you do',
            'can_access_sales' => 'Can access sales',
            'what_is_platform' => 'What is the platform',
            'industries'       => 'Industries served',
            'help_general'     => 'General help',
            'learning'         => 'Learning / LMS',
            'full_report'      => 'Full report',
            'today_summary'    => 'Today summary',
            'top_items'        => 'Top selling items',
            'cashier_perf'     => 'Cashier performance',
            'store_list'       => 'Store list',
            'store_compare'    => 'Store comparison',
            'returns'          => 'Returns & discounts',
            'hourly'           => 'Hourly breakdown',
            'trend'            => 'Daily trend',
            'weekly'           => 'Weekly summary',
            'monthly'          => 'Monthly summary',
            'yearly'           => 'Yearly summary',
            'cashier_self'     => 'Cashier own sales',
            'unknown'          => 'Unknown (default)',
            'store_count'      => 'Store count',
            'cashier_count'    => 'Cashier count',
            'item_count'       => 'Item count',
            'transaction_count'=> 'Transaction count',
            'customer_count'   => 'Customer count',
            default            => ucfirst($intent),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function matches(string $message, string $intent): bool
    {
        $patterns = self::INTENTS[$intent] ?? [];
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '/')) {
                if (preg_match($pattern, $message)) return true;
            } else {
                if (str_contains($message, mb_strtolower($pattern))) return true;
            }
        }
        return false;
    }

    private function matchesGroup(string $message, array $intents): bool
    {
        foreach ($intents as $intent) {
            if ($this->matches($message, $intent)) return true;
        }
        return false;
    }

    private function matchGroup(string $message, array $intents): string
    {
        foreach ($intents as $intent) {
            if ($this->matches($message, $intent)) return $intent;
        }
        return 'unknown';
    }

    private function isCashier(string $accessLevel): bool
    {
        return !in_array($accessLevel, array_merge(self::ADMIN_ROLES, self::MANAGER_ROLES));
    }
}
