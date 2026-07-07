<?php

namespace Phpkaiharness\Contracts;

use Phpkaiharness\Core\Prompt\PromptContext;

/**
 * Strategy interface for a single prompt processing stage.
 *
 * Implementations transform the PromptContext in place and return it.
 * The PromptProcessorPipeline runs enabled stages in sequence.
 */
interface PromptProcessorInterface
{
    /**
     * Process the prompt context and return the (mutated) context.
     */
    public function process(PromptContext $context): PromptContext;

    /**
     * Whether this stage should run given the current context/config.
     */
    public function isEnabled(PromptContext $context): bool;
}
