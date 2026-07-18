<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;

/**
 * Dispatched once per step of a multi-step generation. The step ordinal lives on
 * `$context->stepIndex`. `$step` is the full step object for non-streaming
 * generations (when content capture is enabled), null for the streaming path
 * where only usage is known at the step boundary.
 */
readonly class StepCompleted
{
    public function __construct(
        public TelemetryContext $context,
        public ?FinishReason $finishReason = null,
        public ?Usage $usage = null,
        public mixed $step = null,
    ) {}
}
