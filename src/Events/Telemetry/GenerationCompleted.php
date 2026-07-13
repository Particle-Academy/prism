<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;

/**
 * Dispatched when a generation finishes successfully.
 *
 * `$usage`/`$finishReason` carry the terminal outcome for text/structured and
 * streaming paths; embeddings/images leave them null (read `$response->usage`).
 * `$response` is the full response object for non-streaming generations, or null
 * for streaming and when `prism.telemetry.capture_content` is disabled.
 */
readonly class GenerationCompleted
{
    public function __construct(
        public TelemetryContext $context,
        public float $durationMs,
        public ?FinishReason $finishReason = null,
        public ?Usage $usage = null,
        public mixed $response = null,
    ) {}
}
