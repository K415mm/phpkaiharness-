<?php

namespace Phpkaiharness\Optimize;

use Illuminate\Support\LazyCollection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Phpkaiharness\Contracts\AnalyticsCollectorInterface;
use Phpkaiharness\Contracts\LlmClientInterface;

/**
 * Draft-Verification Orchestrator.
 * Implements the state-of-the-art multi-step verification pipeline:
 * 1. Draft phase: Generates a raw initial solution using a fast model.
 * 2. Retrieval phase: Searches vector memory / database for confirming/challenging records matching the draft.
 * 3. Verification phase: Instructs the main model to audit and refine the draft based on retrieved context.
 */
class DraftVerificationOrchestration
{
    public function __construct(
        protected OntologicalContextInjector $injector
    ) {}

    /**
     * Execute the Draft-Verification pipeline on the input prompt.
     *
     * @return array{prompt: string, draft: string, evidence: string}
     */
    public function orchestrate(
        string $userPrompt,
        string $systemPrompt,
        string $model,
        LlmClientInterface $client,
        ?string $sessionId = null,
        ?AnalyticsCollectorInterface $collector = null
    ): array {
        try {
            // Step 1: Draft Call
            $draftInstructions = 'Provide a quick, raw draft solution for the user task below. Focus on direct assumptions and list any database records or configurations you assume to exist.';
            $draftResponse = $client->chat(
                systemPrompt: $draftInstructions,
                messages: [['role' => 'user', 'content' => $userPrompt]],
                tools: [],
                model: $model,
                sessionId: $sessionId,
                collector: null // run internally
            );

            $draft = trim($draftResponse['content'] ?? '');

            // Step 2: Retrieve evidence matching the draft
            $ontologyModelClass = 'App\\Models\\ClientAsset';
            $similarityThreshold = 0.30;
            if (function_exists('config')) {
                $ontologyModelClass = (string) config('harness.ontology.model_class', 'App\\Models\\ClientAsset');
                $similarityThreshold = (float) config('harness.ontology.similarity_threshold', 0.30);
            }

            $evidence = '';
            if (class_exists($ontologyModelClass)) {
                $dummyAgent = new class implements Agent
                {
                    use Promptable;

                    public function instructions(): \Stringable|string
                    {
                        return '';
                    }
                };
                $dummyProvider = new class implements TextProvider
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
                };
                $tempPrompt = new AgentPrompt($dummyAgent, $draft, [], $dummyProvider, $model);

                $injectedPrompt = $this->injector->inject(
                    $tempPrompt,
                    $ontologyModelClass,
                    'embedding',
                    $similarityThreshold,
                    2
                );

                if ($injectedPrompt->prompt !== $draft) {
                    $evidence = trim(str_replace($draft, '', $injectedPrompt->prompt));
                }
            }

            // Step 3: Format the prompt for final verification
            $verificationPrompt = $userPrompt;
            if (! empty($draft)) {
                $verificationPrompt .= "\n\n--- [INTERNAL CONTEXT — DO NOT REFERENCE IN YOUR RESPONSE] ---\n".
                    "The system generated this internal draft prediction for your reference only:\n{$draft}\n\n";

                if (! empty($evidence)) {
                    $verificationPrompt .= "--- [INTERNAL EVIDENCE — DO NOT REFERENCE IN YOUR RESPONSE] ---\n".
                        "The following internal records were retrieved for verification:\n{$evidence}\n\n";
                }

                $verificationPrompt .= "IMPORTANT INSTRUCTIONS:\n".
                    "1. Use the internal draft and evidence above as silent background context only.\n".
                    "2. NEVER mention, quote, or reference the draft, evidence, or verification process in your response.\n".
                    "3. NEVER discuss what the draft got wrong or right — simply produce the correct response directly.\n".
                    "4. If the draft contains false assumptions, ignore them and answer accurately.\n".
                    "5. Respond naturally to the user as if no draft or verification step exists.\n".
                    '6. Output your response or execute the necessary tools to answer the user query.';
            }

            if ($collector && $sessionId) {
                $collector->recordEvent(
                    $sessionId,
                    'draft_verification',
                    'DraftVerification',
                    ['draft_length' => strlen($draft), 'has_evidence' => ! empty($evidence)],
                    'Draft-Verification Orchestration completed. Extracted evidence: '.(empty($evidence) ? 'None' : 'Found')
                );
            }

            return [
                'prompt' => $verificationPrompt,
                'draft' => $draft,
                'evidence' => $evidence,
            ];
        } catch (\Throwable $e) {
            // Record failure in telemetry so the evaluator sees it executed
            if ($collector && $sessionId) {
                $collector->recordEvent(
                    $sessionId,
                    'draft_verification',
                    'DraftVerification',
                    ['draft_length' => 0, 'has_evidence' => false, 'error' => $e->getMessage()],
                    'Draft-Verification failed: '.$e->getMessage()
                );
            }

            // Fallback to original prompt in case of failures
            return [
                'prompt' => $userPrompt,
                'draft' => '',
                'evidence' => '',
            ];
        }
    }
}
