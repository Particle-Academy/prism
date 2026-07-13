<?php

declare(strict_types=1);

namespace Prism\Prism\Events\Telemetry;

use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Dispatched after a single tool call executes. `$durationMs` is measured around
 * the tool handler itself (accurate for concurrently-executed tools); the tool
 * ordinal lives on `$context->toolIndex`.
 */
readonly class ToolInvoked
{
    public function __construct(
        public TelemetryContext $context,
        public ToolCall $toolCall,
        public ToolResult $toolResult,
        public float $durationMs,
    ) {}
}
