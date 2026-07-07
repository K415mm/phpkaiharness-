<?php

namespace Phpkaiharness\Tools;

use Phpkaiharness\Contracts\ToolInterface;

class WslCommandTool implements ToolInterface
{
    protected string $name;

    protected string $description;

    protected array $schema;

    protected array $allowedBinaries;

    public function __construct(
        string $name = 'wsl_terminal',
        string $description = 'Executes whitelisted terminal commands inside the local environment (e.g., security scan, DNS lookup).',
        ?array $allowedBinaries = null
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->allowedBinaries = $allowedBinaries ?? ['nmap', 'whois', 'dig', 'curl', 'ping', 'nslookup'];

        $this->schema = [
            'type' => 'object',
            'properties' => [
                'binary' => [
                    'type' => 'string',
                    'description' => 'The command binary to execute (e.g., nmap, whois). Must be on the whitelist: '.implode(', ', $this->allowedBinaries),
                ],
                'arguments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'List of command arguments to pass (e.g., ["-sV", "localhost"]).',
                ],
            ],
            'required' => ['binary'],
        ];
    }

    /**
     * Get the tool name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Get the parameter schema.
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * Execute the command binary with escaped arguments.
     */
    public function execute(array $args): string
    {
        $binary = $args['binary'] ?? '';
        $arguments = $args['arguments'] ?? [];

        if (! in_array($binary, $this->allowedBinaries)) {
            return json_encode([
                'status' => 'rejected',
                'message' => "Execution blocked: Binary '{$binary}' is not on the whitelist.",
            ]);
        }

        // Sanitize binary name
        $escapedBinary = escapeshellcmd($binary);

        // Escape each argument separately
        $escapedArgs = array_map(function ($arg) {
            return escapeshellarg($arg);
        }, $arguments);

        $commandLine = $escapedBinary.' '.implode(' ', $escapedArgs);

        // Execute proc_open to capture output safely
        $descriptors = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($commandLine, $descriptors, $pipes);

        if (! is_resource($process)) {
            return json_encode([
                'status' => 'error',
                'message' => 'Failed to initiate process execution.',
            ]);
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return json_encode([
            'status' => $exitCode === 0 ? 'success' : 'failed',
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ], JSON_PRETTY_PRINT);
    }
}
