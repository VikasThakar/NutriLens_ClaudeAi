<?php

namespace App\Services\AI\Data;

/** A validated weekly insight, ready to be stored. */
readonly class GeneratedInsight
{
    /**
     * @param  list<string>  $observations
     * @param  list<string>  $suggestions
     */
    public function __construct(
        public string $headline,
        public string $summary,
        public array $observations,
        public array $suggestions,
        public string $provider,
        public string $model,
    ) {
    }
}
