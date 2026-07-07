<?php

namespace Phpkaiharness\Optimize;

/**
 * Cynefin complexity classifier using Dirac Bra-Ket state projection.
 * Models the query's state vector |ψ⟩ as a superposition of Simple, Complicated, and Complex bases:
 * |ψ⟩ = c_s |Simple⟩ + c_d |Complicated⟩ + c_x |Complex⟩
 * Triggers state collapse (measurement) into the highest probability density base.
 */
class ComplexityClassifier
{
    public const DOMAIN_SIMPLE = 'simple';

    public const DOMAIN_COMPLICATED = 'complicated';

    public const DOMAIN_COMPLEX = 'complex';

    /**
     * Classify prompt complexity using Dirac state projection amplitudes.
     *
     * @param  string  $prompt  The user query.
     * @param  array<string>  $registeredTools  Active agent tools.
     */
    public static function classify(string $prompt, array $registeredTools = []): string
    {
        $cleanPrompt = strtolower(trim($prompt));

        // 1. Define the Hilbert space coefficients (probability amplitudes)
        $c_s = 1.0; // Base Simple state amplitude
        $c_d = 0.0; // Complicated state amplitude
        $c_x = 0.0; // Complex state amplitude

        // 2. Project mutating tools onto the Complex basis |Complex⟩
        $hasActionTools = false;
        $mutatingKeywords = ['update', 'delete', 'modify', 'create', 'run', 'simulate', 'change', 'ingest', 'set'];
        foreach ($registeredTools as $tool) {
            $toolLower = strtolower($tool);
            foreach ($mutatingKeywords as $keyword) {
                if (str_contains($toolLower, $keyword)) {
                    $hasActionTools = true;
                    break 2;
                }
            }
        }

        // Project mutating intent onto the Complex basis
        $hasMutatingIntent = false;
        foreach ($mutatingKeywords as $keyword) {
            if (preg_match("/\b{$keyword}\b/i", $cleanPrompt)) {
                $hasMutatingIntent = true;
                break;
            }
        }

        if ($hasActionTools) {
            $c_x += 1.5;
            $c_s -= 1.0;
        }
        if ($hasMutatingIntent) {
            $c_x += 1.2;
            $c_s -= 0.8;
        }

        // 3. Project database entities onto the Complicated basis |Complicated⟩
        $entityKeywords = ['client', 'scenario', 'asset', 'sizing', 'profit', 'mssp', 'user', 'role', 'permission'];
        $hasEntities = false;
        foreach ($entityKeywords as $entity) {
            if (preg_match("/\b{$entity}\b/i", $cleanPrompt)) {
                $hasEntities = true;
                break;
            }
        }

        if ($hasEntities) {
            $c_d += 0.9;
            $c_s -= 0.8;
        }
        if (count($registeredTools) > 0) {
            $c_d += 1.0;
            $c_s -= 0.9;
        }

        // 4. Calculate measurement probability densities: P(Domain) = |c_Domain|^2
        $p_simple = pow(max(0.0, $c_s), 2);
        $p_complicated = pow(max(0.0, $c_d), 2);
        $p_complex = pow(max(0.0, $c_x), 2);

        // 5. Trigger spontaneous symmetry breaking / state collapse
        $maxProb = max($p_simple, $p_complicated, $p_complex);

        if ($maxProb === $p_complex && $c_x > 0.0) {
            return self::DOMAIN_COMPLEX;
        }
        if ($maxProb === $p_complicated && $c_d > 0.0) {
            return self::DOMAIN_COMPLICATED;
        }

        return self::DOMAIN_SIMPLE;
    }
}
