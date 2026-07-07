<?php

namespace Phpkaiharness\Contracts;

interface AgentDiscoveryInterface
{
    /**
     * Discover all available agents in the host project.
     *
     * @return array<string, array{
     *     name: string,
     *     class: string,
     *     instructions: string,
     *     provider: string,
     *     model: string,
     *     tools: array<string>
     * }>
     */
    public function discover(): array;

    /**
     * Get the details of a specific agent by its name or FQCN.
     *
     * @return array{
     *     name: string,
     *     class: string,
     *     instructions: string,
     *     provider: string,
     *     model: string,
     *     tools: array<string>
     * }|null
     */
    public function find(string $agentName): ?array;
}
