<?php

namespace Phpkaiharness\Optimize;

use Illuminate\Support\Facades\Redis;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use PDO;
use Phpkaiharness\Contracts\LlmClientInterface;
use Phpkaiharness\Contracts\SemanticMemoryInterface;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Session\SessionManager;

/**
 * Semantic Caching with vector-based matching (Palantir AIP-inspired).
 *
 * Checks incoming prompts against cached responses using:
 * 1. Vector semantic search (via SemanticMemoryInterface) - true AI-native matching
 * 2. Exact string matching
 * 3. Levenshtein fuzzy string distance similarity (fallback)
 */
class SemanticCache
{
    private ?PDO $pdo = null;

    private float $threshold;

    private string $dbPath;

    private ?SemanticMemoryInterface $semanticMemory = null;

    /** @var array<string> Patterns that make a response ineligible for caching */
    private array $rejectPatterns;

    private bool $rejectEmpty;

    private int $rejectMinLength;

    private string $namespace;

    public function __construct(
        ?PDO $pdo = null,
        float $threshold = 0.88,
        ?string $dbPath = null,
        ?SemanticMemoryInterface $semanticMemory = null,
        ?array $eligibilityConfig = null,
        string $namespace = 'default'
    ) {
        $this->pdo = $pdo;
        $this->threshold = $threshold;
        $this->dbPath = $dbPath ?? (getenv('PHPKAIHARNESS_DB') ?: SqliteMonitorStore::defaultDbPath());
        $this->semanticMemory = $semanticMemory;
        $this->namespace = $namespace;

        // Resolve eligibility config from parameter or config()
        if ($eligibilityConfig !== null) {
            $this->rejectPatterns = $eligibilityConfig['reject_patterns'] ?? ['⚠️', 'cURL error', 'LLM execution error', 'iteration limit'];
            $this->rejectEmpty = $eligibilityConfig['reject_empty'] ?? true;
            $this->rejectMinLength = $eligibilityConfig['reject_min_length'] ?? 20;
        } elseif (function_exists('config')) {
            $cfg = config('harness.cache.eligibility', []);
            $this->rejectPatterns = $cfg['reject_patterns'] ?? ['⚠️', 'cURL error', 'LLM execution error', 'iteration limit'];
            $this->rejectEmpty = $cfg['reject_empty'] ?? true;
            $this->rejectMinLength = $cfg['reject_min_length'] ?? 20;
        } else {
            $this->rejectPatterns = ['⚠️', 'cURL error', 'LLM execution error', 'iteration limit'];
            $this->rejectEmpty = true;
            $this->rejectMinLength = 20;
        }
    }

    /**
     * Set or update the SemanticMemoryInterface for vector-based lookups.
     */
    public function setSemanticMemory(?SemanticMemoryInterface $semanticMemory): self
    {
        $this->semanticMemory = $semanticMemory;

        return $this;
    }

    /**
     * Check if a response is eligible for caching.
     *
     * Rejects empty responses, error responses, iteration-limit messages,
     * and responses matching configured reject patterns.
     */
    public function isCacheable(string $response): bool
    {
        if ($this->rejectEmpty && trim($response) === '') {
            return false;
        }

        if (mb_strlen($response) < $this->rejectMinLength) {
            return false;
        }

        $lower = strtolower($response);
        foreach ($this->rejectPatterns as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Invalidate cached entries matching the given prompt pattern.
     *
     * Called after tool writes that mutate state, to prevent stale cache hits.
     */
    public function invalidate(?string $promptPattern = null): int
    {
        $db = $this->getPdo();
        if (! $db) {
            return 0;
        }

        try {
            if ($promptPattern !== null) {
                $stmt = $db->prepare(
                    "DELETE FROM harness_sessions
                     WHERE prompt LIKE :pattern
                       AND method != 'semantic-cache-hit'"
                );
                $stmt->execute([':pattern' => '%'.$promptPattern.'%']);

                return $stmt->rowCount();
            }

            // Invalidate all non-cache-hit sessions
            $stmt = $db->prepare(
                "DELETE FROM harness_sessions
                 WHERE method != 'semantic-cache-hit'"
            );
            $stmt->execute();

            return $stmt->rowCount();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Build the SQL fragment for rejecting ineligible responses.
     */
    private function rejectClause(): string
    {
        $clauses = [
            "response != ''",
            "method != 'semantic-cache-hit'",
        ];

        foreach ($this->rejectPatterns as $pattern) {
            $escaped = str_replace("'", "''", $pattern);
            $clauses[] = "response NOT LIKE '%{$escaped}%'";
        }

        return implode(' AND ', $clauses);
    }

    /**
     * Resolve the PDO connection if not already provided.
     */
    private function getPdo(): ?PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (file_exists($this->dbPath)) {
            try {
                $this->pdo = new PDO('sqlite:'.$this->dbPath);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // Return null if cannot connect
                return null;
            }
        }

        return $this->pdo;
    }

    /**
     * Lookup a prompt in the cache using three-tier matching:
     * 1. Vector semantic search (AI-native matching) - if SemanticMemoryInterface available
     * 2. Exact string match
     * 3. Levenshtein fuzzy string similarity
     *
     * Returns the cached response string on success, or null on cache miss.
     */
    /**
     * Normalize prompt by stripping task wrappers and whitespace.
     */
    public static function normalizePrompt(string $prompt): string
    {
        $clean = trim($prompt);
        if (preg_match('/TASK:\s*(.*?)(?:\n\nCONVERSATION CONTEXT:|$)/s', $clean, $m)) {
            $clean = trim($m[1]);
        }

        return mb_strtolower($clean);
    }

    /**
     * Check if numbers/digits inside the two prompts are compatible.
     * If the set of numbers/digits present in the query does not exactly match
     * the set of numbers/digits in the cached prompt, we reject the match.
     */
    public static function matchDigits(string $promptA, string $promptB): bool
    {
        preg_match_all('/\d+/', $promptA, $matchesA);
        preg_match_all('/\d+/', $promptB, $matchesB);

        $digitsA = array_map('intval', $matchesA[0] ?? []);
        $digitsB = array_map('intval', $matchesB[0] ?? []);

        sort($digitsA);
        sort($digitsB);

        return array_values(array_unique($digitsA)) === array_values(array_unique($digitsB));
    }

    /**
     * Extract semantic core tokens (the "poetry filter").
     *
     * Strips filler/stop words and returns only high-value nouns, action verbs,
     * and core entities — the "semantic eigenvalues" that define the true intent.
     *
     * @return array<string>
     */
    public static function extractSemanticCore(string $prompt): array
    {
        $norm = self::normalizePrompt($prompt);

        // Common English + French stop words and filler expressions
        $stopWords = [
            // English
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'may', 'might', 'must', 'can', 'shall', 'to', 'of', 'in',
            'on', 'at', 'by', 'for', 'with', 'about', 'as', 'into', 'like',
            'through', 'after', 'over', 'between', 'out', 'against', 'during',
            'without', 'before', 'under', 'around', 'among', 'from', 'up',
            'down', 'or', 'and', 'but', 'if', 'then', 'else', 'when', 'where',
            'why', 'how', 'all', 'each', 'every', 'both', 'few', 'more', 'most',
            'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same',
            'so', 'than', 'too', 'very', 'just', 'also', 'now', 'here', 'there',
            'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those',
            'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'him', 'her',
            'us', 'them', 'my', 'your', 'his', 'its', 'our', 'their',
            'please', 'tell', 'give', 'show', 'help', 'need', 'want', 'know',
            'think', 'make', 'use', 'get', 'let', 'hey', 'hi', 'hello',
            'task', 'context', 'conversation',
            // French
            'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'ou',
            'dans', 'sur', 'pour', 'avec', 'par', 'que', 'qui', 'ne', 'pas',
            'est', 'sont', 'avez', 'donnez', 'montrez', 'aidez', 'besoin',
            'veut', 'savoir', 'pense', 'faire', 'utiliser', 'obtenir',
            'comment', 'pourquoi', 'quand', 'où', 'tout', 'chaque',
            'je', 'tu', 'il', 'elle', 'nous', 'vous', 'ils', 'elles',
            'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'son', 'sa', 'ses',
            'notre', 'votre', 'leur',
        ];

        // Split into words, filter stop words and short tokens
        $words = preg_split('/[\s\p{P}]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY);
        if (! $words) {
            return [];
        }

        $core = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) < 3) {
                continue;
            }
            if (in_array($word, $stopWords, true)) {
                continue;
            }
            $core[] = $word;
        }

        return $core;
    }

    /**
     * Compute a non-commutative sequence hash of the semantic core.
     *
     * The order of tokens matters (A×B ≠ B×A), so "user hacked system"
     * produces a different hash than "system hacked user".
     * This implements the non-commutative cognition concept from the
     * quantum matrix matching research.
     */
    public static function semanticCoreHash(string $prompt): string
    {
        $core = self::extractSemanticCore($prompt);
        if (empty($core)) {
            return md5(self::normalizePrompt($prompt));
        }

        // Non-commutative rolling hash: position-weighted XOR
        $hash = 0;
        foreach ($core as $i => $token) {
            $tokenHash = crc32($token);
            // Position weight: shift by position so order matters
            $shifted = ($tokenHash << ($i % 16)) | ($tokenHash >> (32 - ($i % 16)));
            $hash ^= $shifted;
        }

        return dechex($hash);
    }

    /**
     * Compute the overlap ratio between two semantic cores.
     *
     * This is the "overlap probability" P = ⟨ψ|ρ|ψ⟩ — the quantum measurement
     * of resonance between the incoming query vector and the cached state.
     *
     * @param  array<string>  $coreA
     * @param  array<string>  $coreB
     */
    public static function coreOverlap(array $coreA, array $coreB): float
    {
        if (empty($coreA) || empty($coreB)) {
            return 0.0;
        }

        $setA = array_unique($coreA);
        $setB = array_unique($coreB);
        $intersection = array_intersect($setA, $setB);
        $union = array_unique(array_merge($setA, $setB));

        return (float) count($intersection) / (float) count($union);
    }

    public function lookup(string $prompt): ?string
    {
        $cachedResponse = $this->performLookup($prompt);

        if ($cachedResponse !== null) {
            // 1. Verify existence of referenced entities in DB
            if (! self::verifyOntologyExistence($prompt)) {
                if (function_exists('info')) {
                    info("Semantic Cache Hit rejected: Entity in prompt '{$prompt}' does not exist in host database.");
                }

                return null;
            }

            // 2. Perform fast LLM validation (Draft-Verification)
            if (! $this->verifyCacheWithLlm($prompt, $cachedResponse)) {
                if (function_exists('info')) {
                    info("Semantic Cache Hit rejected: LLM verification failed for prompt '{$prompt}'.");
                }

                return null;
            }
        }

        return $cachedResponse;
    }

    protected function performLookup(string $prompt): ?string
    {
        $cleanPrompt = trim($prompt);
        $normPrompt = self::normalizePrompt($cleanPrompt);
        $queryCore = self::extractSemanticCore($cleanPrompt);
        $queryHash = self::semanticCoreHash($cleanPrompt);

        // ── L1 Redis Superposition Layer ──
        if (config('harness.cache.redis.enabled', true) && class_exists(Redis::class)) {
            try {
                $redis = Redis::connection(config('harness.cache.redis.connection', 'default'));

                // 1. Decay other cached keys (Environmental Noise Decay)
                if (config('harness.cache.redis.decay_mode', 'dissipative') === 'dissipative') {
                    $this->decayRedisCoherence($redis, $queryHash);
                }

                // 2. Lookup by semantic core hash
                $redisKey = "harness:cache:core:{$queryHash}";
                $cachedJson = $redis->get($redisKey);
                if ($cachedJson) {
                    $cached = json_decode($cachedJson, true);
                    if ($cached && ! empty($cached['response']) && self::matchDigits($cleanPrompt, $cached['prompt'])) {
                        // Boost coherence on hit (Zeno effect)
                        $redis->zadd('harness:cache:coherence', 1.0, $queryHash);

                        return $cached['response'];
                    }
                }

                // 3. Ambient resonance search in Redis (Superposition state match)
                $candidates = $this->searchRedisCandidates($redis, $cleanPrompt, $queryCore);
                if (! empty($candidates)) {
                    // Spontaneous Symmetry Breaking using Subjective Field
                    $best = $this->breakSymmetry($candidates);
                    if ($best['score'] >= $this->threshold) {
                        $bestHash = self::semanticCoreHash($best['prompt']);
                        $redis->zadd('harness:cache:coherence', 1.0, $bestHash);

                        return $best['response'];
                    }
                }
            } catch (\Throwable $e) {
                // Silently fallback to L2 (SQLite)
            }
        }

        // 1. Vector semantic search (AI-native matching)
        if ($this->semanticMemory !== null) {
            $semanticResult = $this->lookupSemantic($cleanPrompt);
            if ($semanticResult !== null) {
                return $semanticResult;
            }
        }

        // 2. Lookup in primary session PDO connection
        $primaryPdo = $this->getPdo();
        if ($primaryPdo !== null) {
            $hit = $this->lookupInPdo($primaryPdo, $cleanPrompt, $queryCore, $queryHash);
            if ($hit !== null) {
                return $hit;
            }
        }

        // 3. Fallback to global shared monitor database
        $globalDbPath = SqliteMonitorStore::defaultDbPath();
        if (file_exists($globalDbPath) && realpath($globalDbPath) !== realpath($this->dbPath)) {
            try {
                $globalPdo = new PDO('sqlite:'.$globalDbPath);
                $globalPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $globalPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $hit = $this->lookupInPdo($globalPdo, $cleanPrompt, $queryCore, $queryHash);
                if ($hit !== null) {
                    return $hit;
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        // 4. Cross-session lookup across all isolated session folders
        if (class_exists(SessionManager::class)) {
            try {
                $sm = new SessionManager;
                $basePath = $sm->getBasePath();
                if (is_dir($basePath)) {
                    $dirs = glob($basePath.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
                    if ($dirs) {
                        foreach ($dirs as $dir) {
                            $monDb = $dir.DIRECTORY_SEPARATOR.'monitor.db';
                            if (! file_exists($monDb) || realpath($monDb) === realpath($this->dbPath)) {
                                continue;
                            }
                            try {
                                $sessionPdo = new PDO('sqlite:'.$monDb);
                                $sessionPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                $sessionPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                                $hit = $this->lookupInPdo($sessionPdo, $cleanPrompt, $queryCore, $queryHash);
                                if ($hit !== null) {
                                    return $hit;
                                }
                            } catch (\Throwable $e) {
                                // Non-fatal
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        return null;
    }

    /**
     * Perform exact and Levenshtein fuzzy string lookup against a specific PDO SQLite connection.
     *
     * Now uses semantic core overlap and non-commutative hash to prevent
     * cross-prompt false cache hits. A fuzzy match is only accepted when:
     * 1. The exact normalized prompt matches, OR
     * 2. The semantic core hash matches (same concepts in same order), OR
     * 3. Both the Levenshtein similarity AND the core overlap exceed threshold
     */
    private function lookupInPdo(PDO $db, string $cleanPrompt, array $queryCore, string $queryHash): ?string
    {
        $reject = $this->rejectClause();
        $normTarget = self::normalizePrompt($cleanPrompt);

        // Try exact match on raw and normalized prompt
        try {
            $stmt = $db->prepare(
                "SELECT prompt, response FROM harness_sessions
                 WHERE {$reject}
                 ORDER BY created_at DESC LIMIT 150"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                if (empty($row['response'])) {
                    continue;
                }
                $storedPrompt = trim($row['prompt'] ?? '');
                if ($storedPrompt === $cleanPrompt || self::normalizePrompt($storedPrompt) === $normTarget) {
                    return $row['response'];
                }
            }
        } catch (\Throwable $e) {
            // Table might not exist
        }

        // Try semantic core hash match (non-commutative, order-sensitive)
        try {
            $stmt = $db->query(
                "SELECT prompt, response FROM harness_sessions
                 WHERE {$reject}
                 ORDER BY created_at DESC LIMIT 150"
            );
            $sessions = $stmt->fetchAll();

            foreach ($sessions as $session) {
                $cachedPrompt = $session['prompt'] ?? '';
                if (empty($cachedPrompt)) {
                    continue;
                }

                // Check non-commutative hash first — same concepts in same order
                $cachedHash = self::semanticCoreHash($cachedPrompt);
                if ($cachedHash === $queryHash && ! empty($session['response'])) {
                    if (self::matchDigits($cleanPrompt, $cachedPrompt)) {
                        return $session['response'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Continue to fuzzy
        }

        // Try Levenshtein fuzzy match with semantic core overlap guard
        try {
            $stmt = $db->query(
                "SELECT prompt, response FROM harness_sessions
                 WHERE {$reject}
                 ORDER BY created_at DESC LIMIT 150"
            );
            $sessions = $stmt->fetchAll();

            foreach ($sessions as $session) {
                $cachedPromptRaw = $session['prompt'] ?? '';
                $cachedPrompt = self::normalizePrompt($cachedPromptRaw);
                if (empty($cachedPrompt)) {
                    continue;
                }

                $lenNew = strlen($normTarget);
                $lenCached = strlen($cachedPrompt);
                $maxLen = max($lenNew, $lenCached);
                if ($maxLen === 0) {
                    continue;
                }

                $lenDiff = abs($lenNew - $lenCached);
                if (($lenDiff / $maxLen) > (1.0 - $this->threshold)) {
                    continue;
                }

                if ($maxLen <= 255) {
                    $dist = levenshtein($normTarget, $cachedPrompt);
                    $similarity = 1.0 - ($dist / $maxLen);

                    if ($similarity >= $this->threshold) {
                        // Quantum-inspired guard: verify semantic core overlap
                        // This prevents false hits where string similarity is high
                        // but the actual semantic intent is different
                        $cachedCore = self::extractSemanticCore($cachedPromptRaw);
                        $overlap = self::coreOverlap($queryCore, $cachedCore);

                        // Require both string similarity AND semantic core overlap
                        // The overlap threshold scales with the string threshold
                        $overlapThreshold = $this->threshold - 0.05;
                        if ($overlap >= $overlapThreshold && self::matchDigits($cleanPrompt, $cachedPromptRaw)) {
                            return $session['response'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Vector-based semantic lookup using SemanticMemoryInterface.
     *
     * Searches for semantically similar prompts (e.g., "What is the weather?" matches "Tell me the forecast")
     * instead of relying on string similarity.
     */
    private function lookupSemantic(string $prompt): ?string
    {
        if ($this->semanticMemory === null) {
            return null;
        }

        try {
            $results = $this->semanticMemory->search(
                query: $prompt,
                threshold: $this->threshold,
                limit: 1
            );

            if (! empty($results) && $results[0]['score'] >= $this->threshold) {
                $text = $results[0]['text'];
                // Don't return ineligible cached responses
                if (! $this->isCacheable($text)) {
                    return null;
                }

                // Quantum-inspired guard: verify semantic core overlap
                // The embedding similarity alone can produce false positives
                // when prompts share domain vocabulary but ask different questions.
                // We extract the semantic core from the source prompt stored in
                // the memory metadata and compare it to the incoming prompt's core.
                $sourcePrompt = $results[0]['source'] ?? '';
                // Strip the 'semantic-cache:' prefix to get the original prompt
                if (str_starts_with($sourcePrompt, 'semantic-cache:')) {
                    $sourcePrompt = substr($sourcePrompt, 15);
                }
                if (! empty($sourcePrompt)) {
                    $queryCore = self::extractSemanticCore($prompt);
                    $cachedCore = self::extractSemanticCore($sourcePrompt);
                    $overlap = self::coreOverlap($queryCore, $cachedCore);
                    if ($overlap < ($this->threshold - 0.05)) {
                        // Semantic cores don't overlap enough — this is a false hit
                        return null;
                    }
                    if (! self::matchDigits($prompt, $sourcePrompt)) {
                        // Digits mismatched — different parameters/IDs
                        return null;
                    }
                }

                return $text;
            }
        } catch (\Exception $e) {
            // Silently fall back to string-based matching
            return null;
        }

        return null;
    }

    /**
     * Store a prompt-response pair in the semantic memory cache.
     *
     * Note: The embedding must be computed externally before calling this method,
     * as the cache layer does not have direct access to an embedding model.
     *
     * @param  string  $prompt  The original user prompt/query.
     * @param  string  $response  The LLM response to cache.
     * @param  array<float>  $embedding  Pre-computed vector embedding of the prompt.
     */
    public function store(string $prompt, string $response, array $embedding): void
    {
        if (! $this->isCacheable($response)) {
            return;
        }

        // ── L1 Redis Superposition Layer Store ──
        if (config('harness.cache.redis.enabled', true) && class_exists(Redis::class)) {
            try {
                $redis = Redis::connection(config('harness.cache.redis.connection', 'default'));
                $queryHash = self::semanticCoreHash($prompt);

                $redisKey = "harness:cache:core:{$queryHash}";
                $redis->set($redisKey, json_encode([
                    'prompt' => $prompt,
                    'response' => $response,
                    'created_at' => time(),
                ]));

                // Add to coherence ZSET with initial coherence = 1.0 (fully coherent)
                $redis->zadd('harness:cache:coherence', 1.0, $queryHash);
            } catch (\Throwable $e) {
                // Silently ignore
            }
        }

        if ($this->semanticMemory === null) {
            return;
        }

        try {
            $this->semanticMemory->addMemory(
                text: $response,
                embedding: $embedding,
                source: 'semantic-cache:'.substr($prompt, 0, 100)
            );
        } catch (\Exception $e) {
            // Silently ignore storage failures - caching is best-effort
        }
    }

    /**
     * Get active context keywords (Subjective Field) to bias ambiguous cache lookups.
     */
    public static function getSubjectiveField(): array
    {
        $keywords = [];

        if (function_exists('config') && config('harness.active_agent')) {
            $keywords[] = strtolower((string) config('harness.active_agent'));
        }

        if (function_exists('app') && app()->bound('request') && function_exists('request')) {
            $req = request();
            if ($req->route('client')) {
                $keywords[] = 'client';
            }
            if ($req->input('client_id')) {
                $keywords[] = 'client';
            }
        }

        if (function_exists('app') && app()->bound('auth') && function_exists('auth') && auth()->user()) {
            $role = auth()->user()->role ?? '';
            if ($role) {
                $keywords[] = strtolower((string) $role);
            }
        }

        return array_unique($keywords);
    }

    /**
     * Spontaneous Symmetry Breaking (SSB) resolver.
     * Collapses ambiguous cache matches into a single outcome based on active context bias.
     */
    public function breakSymmetry(array $candidates): array
    {
        $subjectiveField = self::getSubjectiveField();
        if (empty($subjectiveField)) {
            return $candidates[0];
        }

        // Split subjective field terms into individual words for broader semantic matching
        $fieldWords = [];
        foreach ($subjectiveField as $term) {
            $words = preg_split('/[\s\p{P}_]+/u', strtolower((string) $term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $fieldWords = array_merge($fieldWords, $words);
        }
        $fieldWords = array_unique($fieldWords);

        $bestCandidate = $candidates[0];
        $maxResonance = -1.0;
        $biasWeight = (float) config('harness.cache.redis.subjective_field.bias_weight', 0.15);

        foreach ($candidates as $candidate) {
            $promptWords = preg_split('/[\s\p{P}]+/u', strtolower($candidate['prompt']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $responseWords = preg_split('/[\s\p{P}]+/u', strtolower($candidate['response']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $allWords = array_unique(array_merge($promptWords, $responseWords));

            $overlap = count(array_intersect($allWords, $fieldWords));
            $resonance = (float) $candidate['score'] + ($overlap * $biasWeight);

            if ($resonance > $maxResonance) {
                $maxResonance = $resonance;
                $bestCandidate = $candidate;
            }
        }

        return $bestCandidate;
    }

    /**
     * Decrements the coherence of cached elements in Redis (Environmental Noise Decay).
     */
    private function decayRedisCoherence($redis, string $excludeHash): void
    {
        $coherenceKey = 'harness:cache:coherence';
        $entries = $redis->zrangebyscore($coherenceKey, '-inf', '+inf', ['WITHSCORES' => true]);
        if (empty($entries)) {
            return;
        }

        foreach ($entries as $hash => $score) {
            if ($hash === $excludeHash) {
                continue; // Stabilized by active query (Necker-Zeno effect)
            }

            $newScore = (float) $score * 0.95;

            if ($newScore < 0.2) {
                $redis->zrem($coherenceKey, $hash);
                $redis->del("harness:cache:core:{$hash}");
            } else {
                $redis->zadd($coherenceKey, $newScore, $hash);
            }
        }
    }

    /**
     * Search Redis L1 cache for semantically overlapping candidates.
     */
    private function searchRedisCandidates($redis, string $cleanPrompt, array $queryCore): array
    {
        if (empty($queryCore)) {
            return [];
        }

        $candidates = [];
        $coherenceKey = 'harness:cache:coherence';

        $hashes = $redis->zrevrange($coherenceKey, 0, 50);
        if (empty($hashes)) {
            return [];
        }

        foreach ($hashes as $hash) {
            $cachedJson = $redis->get("harness:cache:core:{$hash}");
            if (! $cachedJson) {
                continue;
            }

            $cached = json_decode($cachedJson, true);
            if (! $cached || empty($cached['response'])) {
                continue;
            }

            $cachedPrompt = $cached['prompt'] ?? '';
            $cachedCore = self::extractSemanticCore($cachedPrompt);
            $overlap = self::coreOverlap($queryCore, $cachedCore);

            if ($overlap > 0.0 && self::matchDigits($cleanPrompt, $cachedPrompt)) {
                $candidates[] = [
                    'prompt' => $cachedPrompt,
                    'response' => $cached['response'],
                    'score' => $overlap,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Check if the entities (e.g. client ID, scenario ID) referenced in the prompt
     * actually exist in the database.
     */
    public static function verifyOntologyExistence(string $prompt): bool
    {
        // Extract key entity IDs (e.g. client 3, scenario 12, id 784555)
        preg_match_all('/\b(client|scenario|asset|user|id)\s*(?:with\s+)?(?:id\s*)?[:=]?\s*(\d+)/i', $prompt, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return true; // No explicit entity IDs mentioned, skip validation
        }

        foreach ($matches as $match) {
            $type = strtolower($match[1]);
            $id = intval($match[2]);
            $found = false;

            // Define which models to check based on the entity type
            $modelsToCheck = [];
            if ($type === 'client') {
                $modelsToCheck = ['App\Models\Client'];
            } elseif ($type === 'scenario') {
                $modelsToCheck = ['App\Models\Scenario'];
            } elseif ($type === 'asset') {
                $modelsToCheck = ['App\Models\ClientAsset'];
            } elseif ($type === 'user') {
                $modelsToCheck = ['App\Models\User'];
            } else {
                // 'id' - fallback to all primary models
                $modelsToCheck = ['App\Models\Client', 'App\Models\Scenario', 'App\Models\ClientAsset'];
            }

            foreach ($modelsToCheck as $modelClass) {
                if (class_exists($modelClass)) {
                    try {
                        if ($modelClass::where('id', $id)->exists()) {
                            $found = true;
                            break;
                        }
                    } catch (\Throwable $e) {
                        // Silently ignore DB errors
                    }
                }
            }

            // If we identified a requested entity ID that is completely missing, reject the cache hit
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Use a fast LLM verification loop (Draft-Verification pattern) to validate
     * if the cached response (acting as the draft) is correct relative to the prompt.
     */
    public function verifyCacheWithLlm(string $prompt, string $cachedResponse): bool
    {
        if (! config('harness.cache.verify_with_llm', false)) {
            return true; // Verification disabled, accept cache hit
        }

        // 1. Retrieve Ontological Context (Evidence)
        $evidence = '';
        $ontologyModelClass = config('harness.ontology.model_class', 'App\Models\ClientAsset');
        $similarityThreshold = (float) config('harness.ontology.similarity_threshold', 0.30);

        if (class_exists($ontologyModelClass)) {
            try {
                // We create a dummy agent prompt and inject it using the OntologicalContextInjector
                $dummyAgent = new class implements Agent
                {
                    use Promptable;

                    public function instructions(): \Stringable|string
                    {
                        return '';
                    }
                };

                // Construct a temporary prompt with the user query
                $tempPrompt = new AgentPrompt(
                    $dummyAgent,
                    $prompt,
                    [],
                    app(TextProvider::class),
                    config('harness.qwen_provider.light_model', 'qwen-turbo')
                );

                $injector = new OntologicalContextInjector;
                $injected = $injector->inject(
                    $tempPrompt,
                    $ontologyModelClass,
                    'embedding',
                    $similarityThreshold,
                    3
                );

                if ($injected->prompt !== $prompt) {
                    $evidence = trim(str_replace($prompt, '', $injected->prompt));
                }
            } catch (\Throwable $e) {
                // Fallback to empty evidence
            }
        }

        // 2. Perform fast LLM validation
        try {
            if (function_exists('app') && app()->bound(LlmClientInterface::class)) {
                $client = app(LlmClientInterface::class);

                $verificationPrompt = "User Query: \"{$prompt}\"\n\n".
                    "Cached Response Draft: \"{$cachedResponse}\"\n\n";

                if (! empty($evidence)) {
                    $verificationPrompt .= "Real-time Database Context:\n{$evidence}\n\n";
                }

                $verificationPrompt .= 'Task: Validate if the Cached Response Draft is 100% correct, matches the user query, and aligns with the database context. '.
                    'Respond with ONLY a JSON object: { "valid": true } or { "valid": false, "reason": "explanation" }.';

                $response = $client->chat(
                    systemPrompt: 'You are a strict cache verification unit. Validate the cached response against current query and database context.',
                    messages: [['role' => 'user', 'content' => $verificationPrompt]],
                    tools: [],
                    model: config('harness.qwen_provider.light_model', 'qwen-turbo'),
                    sessionId: 'cache_verify'
                );

                $content = trim($response['content'] ?? '');
                // Parse JSON
                if (preg_match('/\{.*\}/s', $content, $m)) {
                    $json = json_decode($m[0], true);
                    if (isset($json['valid'])) {
                        return (bool) $json['valid'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // In case of any validation errors, fail-safe to accepting the cache hit
            return true;
        }

        return true;
    }
}
