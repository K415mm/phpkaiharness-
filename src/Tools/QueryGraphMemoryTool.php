<?php

namespace Phpkaiharness\Tools;

use Phpkaiharness\Contracts\ToolInterface;
use Phpkaiharness\Monitor\SqliteMonitorStore;

/**
 * Tool for agents to query historical facts and relations
 * from the cognitive graph memory layer.
 */
class QueryGraphMemoryTool implements ToolInterface
{
    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'query_graph_memory';
    }

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return 'Queries the persistent cognitive graph memory layer to retrieve historical facts, configurations, updates, or rules resolved in previous agent sessions.';
    }

    /**
     * Get the parameter schema.
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'A keyword, client name, or setting name to search for in historical facts (e.g. "Acme Corp", "pricing").',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * Execute the tool to query the SQLite facts table.
     */
    public function execute(array $args): string
    {
        $query = trim($args['query'] ?? '');
        if (empty($query)) {
            return json_encode(['success' => false, 'message' => 'Query cannot be empty.']);
        }

        try {
            $dbPath = (function_exists('config') && function_exists('app') && app()->bound('config')) ? config('harness.cache.db_path') : null;
            $dbPath = $dbPath ?: SqliteMonitorStore::defaultDbPath();

            if (! file_exists($dbPath)) {
                return json_encode(['success' => true, 'message' => 'No historical facts found yet. Graph memory is empty.', 'facts' => []]);
            }

            $pdo = new \PDO('sqlite:'.$dbPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare(
                'SELECT fact, created_at 
                 FROM harness_facts 
                 WHERE fact LIKE :search 
                 ORDER BY created_at DESC 
                 LIMIT 15'
            );
            $stmt->execute([':search' => '%'.$query.'%']);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($results)) {
                return json_encode([
                    'success' => true,
                    'message' => "No facts found matching search query: '{$query}'.",
                    'facts' => [],
                ]);
            }

            return json_encode([
                'success' => true,
                'message' => 'Successfully retrieved facts from persistent memory.',
                'facts' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'success' => false,
                'message' => 'Failed to query cognitive graph memory: '.$e->getMessage(),
            ]);
        }
    }
}
