<?php

namespace Phpkaiharness\Core\Prompt;

use Illuminate\Support\LazyCollection;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;

/**
 * Minimal no-op TextProvider used to construct AgentPrompt objects
 * in standalone (non-Laravel-AI) environments.
 *
 * All methods return empty/dummy responses since the provider is never
 * actually called — it only satisfies the AgentPrompt constructor's
 * type requirement.
 */
class DummyTextProvider implements TextProvider
{
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        return new AgentResponse('', '', new Usage, new Meta('', ''));
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $stream = new class extends LazyCollection
        {
            public function getIterator(): \Traversable
            {
                return new \ArrayIterator([]);
            }
        };

        return new StreamableAgentResponse('', $stream, new Meta('', ''));
    }

    public function textGateway(): TextGateway
    {
        return new class implements TextGateway
        {
            public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
            {
                return new TextResponse('', new Usage, new Meta('', ''));
            }

            public function streamText(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): \Generator
            {
                yield '';
            }

            public function onToolInvocation(\Closure $invoking, \Closure $invoked): self
            {
                return $this;
            }
        };
    }

    public function useTextGateway(TextGateway $gateway): self
    {
        return $this;
    }

    public function defaultTextModel(): string
    {
        return 'dummy';
    }

    public function cheapestTextModel(): string
    {
        return 'dummy';
    }

    public function smartestTextModel(): string
    {
        return 'dummy';
    }
}
