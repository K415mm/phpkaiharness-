<?php

namespace Phpkaiharness\Http\Middleware;

use Closure;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Prompts\AgentPrompt;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Monitor\SqliteMonitorStore;
use Phpkaiharness\Support\HarnessConfig;

/**
 * Context Compression Middleware for laravel/ai.
 * Strips comments, collapses extra whitespace, and performs JIT signature
 * extraction on code documents attached to the prompt.
 */
class CompressContextMiddleware
{
    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $enabled = HarnessConfig::isNodeEnabled('context_compression', 'harness.compaction.compression.enabled', false);
        if (! $enabled) {
            return $next($prompt);
        }

        $sessionId = function_exists('app') && app()->bound('harness.active_session_id') ? app('harness.active_session_id') : null;
        $collector = $this->resolveCollector();

        $originalPromptLength = strlen($prompt->prompt);
        $compressedPrompt = $this->compressText($prompt->prompt);
        $compressedPromptLength = strlen($compressedPrompt);

        $revisedAttachments = [];
        $compressedFiles = [];
        $lineThreshold = (function_exists('config') && function_exists('app') && app()->bound('config')) ? (int) config('harness.compaction.compression.line_threshold', 150) : 150;

        foreach ($prompt->attachments as $attachment) {
            if ($attachment instanceof LocalDocument) {
                $path = $attachment->path;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $codeExtensions = ['php', 'js', 'ts', 'py', 'go', 'rb', 'java', 'c', 'cpp', 'rs'];

                if (in_array($ext, $codeExtensions, true) && file_exists($path)) {
                    $content = $attachment->content();
                    $lines = explode("\n", $content);
                    $originalLinesCount = count($lines);

                    $isSignatureOnly = false;
                    $compressedContent = '';

                    if ($originalLinesCount > $lineThreshold) {
                        // Extract JIT signature-only representation
                        $compressedContent = $this->extractSignatures($content, $ext);
                        $isSignatureOnly = true;
                    } else {
                        // Regular compression (strip comments and collapse spaces)
                        $compressedContent = $this->compressCode($content, $ext);
                    }

                    // Save compressed representation to a temp file
                    $tempDir = function_exists('storage_path') ? storage_path('app/phpkaiharness/temp_compress') : sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpkaiharness'.DIRECTORY_SEPARATOR.'temp_compress';
                    if (! is_dir($tempDir)) {
                        @mkdir($tempDir, 0777, true);
                    }

                    $tempPath = $tempDir.'/comp_'.uniqid().'.'.$ext;
                    file_put_contents($tempPath, $compressedContent);

                    // Create new revised local document
                    $newAttachment = new LocalDocument($tempPath, $attachment->mimeType());
                    $newAttachment->as($attachment->name());
                    $revisedAttachments[] = $newAttachment;

                    $compressedFiles[] = [
                        'file' => basename($path),
                        'original_lines' => $originalLinesCount,
                        'compressed_lines' => count(explode("\n", $compressedContent)),
                        'mode' => $isSignatureOnly ? 'signatures_only' : 'comment_stripped',
                    ];
                } else {
                    $revisedAttachments[] = $attachment;
                }
            } else {
                $revisedAttachments[] = $attachment;
            }
        }

        // Revise prompt with compressed contents
        $finalPrompt = $prompt->revise($compressedPrompt, $revisedAttachments);

        // Record event always if collector and session ID are active
        if ($collector && $sessionId) {
            $occurred = ($originalPromptLength !== $compressedPromptLength || ! empty($compressedFiles));
            $collector->recordEvent(
                $sessionId,
                'compression',
                'CompressContextMiddleware',
                [
                    'prompt_original_length' => $originalPromptLength,
                    'prompt_compressed_length' => $compressedPromptLength,
                    'compressed_files' => $compressedFiles,
                    'compression_occurred' => $occurred,
                ],
                $occurred ? 'Successfully compressed prompt context and/or attachments.' : 'No compression required. Context was below threshold.'
            );
        }

        return $next($finalPrompt);
    }

    /**
     * Resolve the analytics collector.
     */
    protected function resolveCollector(): ?AnalyticsCollectorInterface
    {
        if (function_exists('app')) {
            try {
                if (app()->bound(AnalyticsCollectorInterface::class)) {
                    return app(AnalyticsCollectorInterface::class);
                } elseif (function_exists('config') && function_exists('app') && app()->bound('config')) {
                    $dbPath = config('harness.cache.db_path', config('harness.semantic_cache.db_path')) ?: SqliteMonitorStore::defaultDbPath();

                    return new SqliteMonitorStore($dbPath);
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    /**
     * Strip comments and collapse double spaces/newlines in general text.
     */
    protected function compressText(string $text): string
    {
        // Strip duplicate blank lines
        $text = preg_replace("/(\r?\n){3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Strip comments and collapse excess white spaces in source code.
     */
    protected function compressCode(string $code, string $ext): string
    {
        if ($ext === 'py') {
            // Python: strip docstrings and single-line comments
            $code = preg_replace('/#.*$/m', '', $code) ?? $code;
            $code = preg_replace('/"{3}(.*?)"{3}/s', '', $code) ?? $code;
            $code = preg_replace("/'{3}(.*?)'{3}/s", '', $code) ?? $code;
        } else {
            // PHP/JS/TS/Go/Java/C++: strip single and multi-line comments
            $code = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
            $code = preg_replace('!//.*?$!m', '', $code) ?? $code;
        }

        // Collapse excess empty lines
        $lines = explode("\n", $code);
        $cleanedLines = array_filter(array_map('trim', $lines), fn ($line) => $line !== '');

        return implode("\n", $cleanedLines);
    }

    /**
     * Extract class/function signatures and replace bodies with stubs.
     */
    protected function extractSignatures(string $code, string $ext): string
    {
        $lines = explode("\n", $code);
        $signatures = [];

        $signatures[] = '// ==========================================================================';
        $signatures[] = '// [JIT SIGNATURE REPRESENTATION] - Body collapsed to save token budget.';
        $signatures[] = '// If you need to view the full body of any function, call the GetFileContent tool.';
        $signatures[] = "// ==========================================================================\n";

        if ($ext === 'py') {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'class ') || str_starts_with($trimmed, 'def ')) {
                    $indent = str_repeat(' ', strlen($line) - strlen(ltrim($line)));
                    $signatures[] = $indent.$trimmed.' ... (collapsed)';
                }
            }
        } elseif ($ext === 'php') {
            $inClass = false;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (preg_match('/^(class|interface|trait|namespace|use)\s/i', $trimmed)) {
                    $signatures[] = $line;
                    if (str_contains($trimmed, 'class') || str_contains($trimmed, 'trait')) {
                        $inClass = true;
                    }
                } elseif (preg_match('/^(public|protected|private|function)\s/i', $trimmed) || (str_contains($trimmed, 'function ') && ! str_contains($trimmed, '='))) {
                    $signatures[] = preg_replace('/\{.*/', ' { ... }', $line) ?? $line;
                }
            }
        } else { // JS, TS, Go, Java, Rust
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (preg_match('/^(class|function|export class|export function|fn|func|pub fn)\s/', $trimmed)) {
                    $signatures[] = preg_replace('/\{.*/', ' { ... }', $line) ?? $line;
                }
            }
        }

        return implode("\n", $signatures);
    }
}
