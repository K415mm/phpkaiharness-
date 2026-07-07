<?php

namespace Phpkaiharness\Core;

class AgentSelector
{
    /**
     * Discover all agents in the main application.
     *
     * @param  string|null  $agentsDir  Custom agents directory path.
     * @return array List of discovered agents metadata.
     */
    public static function discover(?string $agentsDir = null): array
    {
        if ($agentsDir === null) {
            $paths = [
                getcwd().'/app/Ai/Agents',
                realpath(__DIR__.'/../../../../app/Ai/Agents'),
                realpath(__DIR__.'/../../../app/Ai/Agents'),
            ];
            foreach ($paths as $path) {
                if ($path && is_dir($path)) {
                    $agentsDir = $path;
                    break;
                }
            }
        }

        if (! $agentsDir || ! is_dir($agentsDir)) {
            return [];
        }

        $agents = [];
        $files = glob($agentsDir.'/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);

            // Extract namespace
            $namespace = '';
            if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                $namespace = trim($matches[1]);
            }

            // Extract class name
            $className = '';
            if (preg_match('/class\s+(\w+)/', $content, $matches)) {
                $className = trim($matches[1]);
            }

            if (empty($className)) {
                continue;
            }

            $fqcn = $namespace ? $namespace.'\\'.$className : $className;

            // Extract instructions via regex first (as fallback)
            $instructions = '';
            if (preg_match('/<<<[\'"]?INSTRUCTIONS[\'"]?\s*\n(.*?)\n\s*INSTRUCTIONS/s', $content, $matches)) {
                $instructions = $matches[1];
            } elseif (preg_match('/return\s+[\'"](.*?)[\'"];/s', $content, $matches)) {
                $instructions = $matches[1];
            }

            // Extract Provider and Model attributes via regex
            $provider = 'ollama'; // default
            $model = '';

            if (preg_match('/#\[Provider\(([^)]+)\)\]/', $content, $matches)) {
                $rawProvider = trim($matches[1]);
                if (str_contains($rawProvider, 'Ollama')) {
                    $provider = 'ollama';
                } elseif (str_contains($rawProvider, 'OpenRouter')) {
                    $provider = 'openrouter';
                }
            }

            if (preg_match('/#\[Model\([\'"]([^`\'"]+)[\'"]\)\]/', $content, $matches)) {
                $model = trim($matches[1]);
            }

            // Try dynamic instantiation if class is loadable
            if (class_exists($fqcn)) {
                try {
                    $instance = new $fqcn;
                    if (method_exists($instance, 'instructions')) {
                        $instructions = (string) $instance->instructions();
                    }
                } catch (\Throwable $e) {
                    // Fall back to parsed instructions
                }
            }

            $agents[] = [
                'name' => $className,
                'class' => $fqcn,
                'file' => $file,
                'instructions' => $instructions,
                'provider' => $provider,
                'model' => $model,
            ];
        }

        return $agents;
    }
}
