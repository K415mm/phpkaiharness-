<?php

/**
 * phpkaiharness JSON API
 *
 * Endpoints:
 *   GET  ?action=stats              Aggregated statistics
 *   GET  ?action=sessions[&limit=N] Session list
 *   GET  ?action=session&id=X       Single session detail
 *   POST ?action=run                Run an agent prompt (JSON body: {url, model, prompt})
 */

require __DIR__.'/bootstrap.php';

use Laravel\Ai\Ai;
use Phpkaiharness\Core\AgentLoop;
use Phpkaiharness\Core\AgentSelector;
use Phpkaiharness\Core\Registry\ToolRegistry;
use Phpkaiharness\Llm\LlmClientFactory;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Optimize\ContextCompactor;
use Phpkaiharness\Optimize\Guardrails;
use Phpkaiharness\Optimize\SemanticCache;
use Phpkaiharness\Tools\WslCommandTool;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

// ── GET: stats ────────────────────────────────────────────────────────────────
if ($action === 'stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $report = getReport($dbPath);
    if (! $report) {
        echo json_encode(['success' => true, 'stats' => null, 'empty' => true]);
        exit;
    }
    echo json_encode(['success' => true, 'stats' => $report->getStats(), 'daily' => $report->getDailyStats(7)]);
    exit;
}

// ── GET: sessions ─────────────────────────────────────────────────────────────
if ($action === 'sessions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = min((int) ($_GET['limit'] ?? 50), 200);
    $offset = (int) ($_GET['offset'] ?? 0);
    $report = getReport($dbPath);
    if (! $report) {
        echo json_encode(['success' => true, 'sessions' => [], 'total' => 0]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'sessions' => $report->getSessions($limit, $offset),
        'total' => $report->getSessionCount(),
    ]);
    exit;
}

// ── GET: single session ───────────────────────────────────────────────────────
if ($action === 'session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'] ?? '';
    $report = getReport($dbPath);
    if (! $report || empty($id)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }
    $session = $report->getSession($id);
    if (! $session) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }
    echo json_encode(['success' => true, 'session' => $session]);
    exit;
}

// ── POST: run agent ───────────────────────────────────────────────────────────
if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $provider = trim($body['provider'] ?? 'ollama');
    $connection = trim($body['connection'] ?? '');
    $agentClass = trim($body['agent'] ?? '');
    $url = trim($body['url'] ?? '');
    $model = trim($body['model'] ?? '');
    $prompt = trim($body['prompt'] ?? '');
    $useCache = (bool) ($body['cache'] ?? false);
    $useCompact = (bool) ($body['compact'] ?? false);
    $useGuard = (bool) ($body['guard'] ?? false);
    $useQuantum = (bool) ($body['quantum'] ?? false);

    if ($useQuantum && function_exists('config')) {
        config(['harness.quantum_harness.enabled' => true]);
    }

    if (empty($prompt)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Prompt is required']);
        exit;
    }

    try {
        $store = new SqliteMonitorStore($dbPath);
        $sessionId = bin2hex(random_bytes(8));

        $systemPrompt = 'You are a Kali WSL security assistant. Use your tools to run network diagnostics and report findings concisely.';
        $agentDisplayName = 'security-assistant';

        if (! empty($agentClass)) {
            $discovered = AgentSelector::discover();
            $matchedAgent = null;
            foreach ($discovered as $da) {
                if (strcasecmp($da['name'], $agentClass) === 0 || strcasecmp($da['class'], $agentClass) === 0) {
                    $matchedAgent = $da;
                    break;
                }
            }

            if ($matchedAgent) {
                $systemPrompt = $matchedAgent['instructions'];
                if (empty($model)) {
                    $model = $matchedAgent['model'];
                }
                if (empty($body['provider']) && ! empty($matchedAgent['provider'])) {
                    $provider = $matchedAgent['provider'];
                }
                $agentDisplayName = $matchedAgent['name'];
            } else {
                if (class_exists($agentClass)) {
                    try {
                        $instance = new $agentClass;
                        if (method_exists($instance, 'instructions')) {
                            $systemPrompt = (string) $instance->instructions();
                            $agentDisplayName = $agentClass;
                        } else {
                            $agentDisplayName = 'custom';
                        }
                    } catch (Throwable $e) {
                        $agentDisplayName = 'custom';
                    }
                } else {
                    $agentDisplayName = 'fallback';
                }
            }
        }

        if (empty($model)) {
            $model = $provider === 'lmstudio' ? 'lmstudio-community/gemma-2b-it-GGUF' : 'hermes-3-llama-3-8b';
        }

        if (empty($url)) {
            if ($provider === 'lmstudio') {
                $url = getenv('PHPKAIHARNESS_LMSTUDIO_URL') ?: 'http://localhost:1234';
            } else {
                $url = getenv('PHPKAIHARNESS_URL') ?: 'http://localhost:11434';
            }
        }

        // Instantiate LLM client via the provider factory
        $resolvedConnection = empty($connection)
            ? (class_exists(Ai::class) && function_exists('app') && app()->bound('config') ? config('ai.default', 'ollama') : 'ollama')
            : $connection;

        $llmClient = (new LlmClientFactory)->make($provider, $model, [
            'url' => $url,
            'connection' => $resolvedConnection,
        ]);

        $registry = new ToolRegistry;
        $registry->attach(new WslCommandTool(
            name: 'wsl_security_tool',
            description: 'Runs security diagnostics (ping, dig, whois, curl, nslookup).',
            allowedBinaries: ['ping', 'dig', 'whois', 'curl', 'nslookup']
        ));

        $agent = new AgentLoop(
            llmClient: $llmClient,
            registry: $registry,
            systemPrompt: $systemPrompt,
            model: $model,
            maxIterations: 5,
        );

        if ($useCache) {
            $agent->setSemanticCache(new SemanticCache(
                pdo: $store->getPdo(),
                threshold: 0.88
            ));
        }

        if ($useCompact) {
            $agent->setContextCompactor(new ContextCompactor(
                strategy: 'sliding_window',
                maxTurns: 4
            ));
        }

        if ($useGuard) {
            $agent->setGuardrails(new Guardrails);
        }

        $history = [];
        $store->startSession($sessionId, $prompt, 'web-ui-run ('.$agentDisplayName.')');
        $startTime = microtime(true);
        $response = $agent->run($prompt, $history, $sessionId, $store);
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        echo json_encode([
            'success' => true,
            'sessionId' => $sessionId,
            'response' => $response,
            'duration_ms' => $durationMs,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

    exit;
}

// ── GET: list available agents ───────────────────────────────────────────────
if ($action === 'agents' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $agents = AgentSelector::discover();
        echo json_encode(['success' => true, 'agents' => $agents]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'agents' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── GET: list available models (Ollama, LM Studio, OpenRouter, Laravel AI) ──────
if ($action === 'models' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $provider = $_GET['provider'] ?? 'ollama';
    $connection = $_GET['connection'] ?? '';
    $url = $_GET['url'] ?? '';

    // If laravel_ai is selected, resolve its actual driver, url, key from config
    if ($provider === 'laravel_ai' && ! empty($connection) && class_exists(Ai::class) && function_exists('app') && app()->bound('config')) {
        $driver = config("ai.providers.{$connection}.driver", '');
        $url = config("ai.providers.{$connection}.url", '');
        $key = config("ai.providers.{$connection}.key", '');

        if ($driver === 'ollama') {
            $provider = 'ollama';
            if (empty($url)) {
                $url = 'http://localhost:11434';
            }
        } elseif ($driver === 'openai') {
            $provider = 'openai_compatible';
            if (empty($url)) {
                $url = 'http://localhost:1234/v1'; // fallback to LM Studio default
            }
        } elseif ($driver === 'gemini') {
            $provider = 'gemini';
        } else {
            // General fallback based on connection name
            if ($connection === 'openrouter') {
                $provider = 'openrouter';
            } elseif ($connection === 'lmstudio') {
                $provider = 'lmstudio';
                if (empty($url)) {
                    $url = 'http://localhost:1234/v1';
                }
            }
        }
    }

    try {
        if ($provider === 'ollama') {
            $targetUrl = rtrim(empty($url) ? 'http://localhost:11434' : $url, '/').'/api/tags';
            $ch = curl_init($targetUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $result = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $data = json_decode($result, true);
                $models = array_map(fn ($m) => $m['name'], $data['models'] ?? []);
                echo json_encode(['success' => true, 'models' => $models]);
            } else {
                echo json_encode(['success' => false, 'models' => [], 'error' => "Ollama unreachable at $targetUrl (HTTP $code)"]);
            }
        } elseif ($provider === 'lmstudio' || $provider === 'openai_compatible') {
            // LM Studio / OpenAI-compatible endpoint
            $baseUrl = empty($url) ? 'http://localhost:1234/v1' : $url;
            if (! str_contains($baseUrl, '/v1') && $provider === 'lmstudio') {
                $baseUrl = rtrim($baseUrl, '/').'/v1';
            }
            $targetUrl = rtrim($baseUrl, '/').'/models';

            $ch = curl_init($targetUrl);
            $headers = [];
            if (! empty($key)) {
                $headers[] = 'Authorization: Bearer '.$key;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $result = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $data = json_decode($result, true);
                $models = array_map(fn ($m) => $m['id'], $data['data'] ?? []);
                echo json_encode(['success' => true, 'models' => $models]);
            } else {
                echo json_encode(['success' => false, 'models' => [], 'error' => "OpenAI compatible server unreachable at $targetUrl (HTTP $code)"]);
            }
        } elseif ($provider === 'openrouter') {
            $targetUrl = 'https://openrouter.ai/api/v1/models';
            $ch = curl_init($targetUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $result = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $data = json_decode($result, true);
                $models = array_map(fn ($m) => $m['id'], $data['data'] ?? []);
                echo json_encode(['success' => true, 'models' => $models]);
            } else {
                echo json_encode(['success' => false, 'models' => [], 'error' => "OpenRouter unreachable (HTTP $code)"]);
            }
        } elseif ($provider === 'gemini') {
            $models = [
                'gemini-1.5-flash',
                'gemini-1.5-pro',
                'gemini-2.0-flash',
                'gemini-2.5-flash',
                'gemini-2.5-pro',
            ];
            echo json_encode(['success' => true, 'models' => $models]);
        } else {
            echo json_encode(['success' => true, 'models' => ['hermes-3-llama-3-8b', 'gemma-2b']]);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'models' => [], 'error' => $e->getMessage()]);
    }

    exit;
}

// ── Fallback ──────────────────────────────────────────────────────────────────
http_response_code(404);
echo json_encode(['success' => false, 'error' => "Unknown action: $action"]);
