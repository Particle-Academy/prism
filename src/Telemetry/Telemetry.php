<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Generator;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Neutral telemetry emission layer.
 *
 * Dispatches plain Laravel events across the generation lifecycle; consumers
 * (an OpenTelemetry bridge, Telescope, Pulse, custom listeners) subscribe. When
 * `prism.telemetry.enabled` is false every method is a no-op — no context is
 * minted, no event is dispatched.
 */
class Telemetry
{
    public static function enabled(): bool
    {
        return (bool) config('prism.telemetry.enabled', false);
    }

    public static function capturesContent(): bool
    {
        return (bool) config('prism.telemetry.capture_content', false);
    }

    public static function stack(): ContextStack
    {
        return app(ContextStack::class);
    }

    public static function current(): ?TelemetryContext
    {
        if (! self::enabled()) {
            return null;
        }

        return self::stack()->current();
    }

    public static function start(TelemetryOperation $operation, string $provider, string $model, mixed $request = null): ?TelemetryContext
    {
        if (! self::enabled()) {
            return null;
        }

        $context = new TelemetryContext(
            traceId: (string) Str::uuid(),
            operation: $operation,
            provider: $provider,
            model: $model,
            startedAt: microtime(true),
        );

        self::stack()->push($context);

        event(new GenerationStarted($context, self::capturesContent() ? $request : null));

        return $context;
    }

    public static function completed(?TelemetryContext $context, mixed $response = null, ?FinishReason $finishReason = null, ?Usage $usage = null): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        if ($response instanceof TextResponse || $response instanceof StructuredResponse) {
            $index = 0;

            foreach ($response->steps as $step) {
                event(new StepCompleted(
                    $context->withStep($index),
                    $step->finishReason,
                    $step->usage,
                    self::capturesContent() ? $step : null,
                ));

                $index++;
            }
        }

        event(new GenerationCompleted(
            $context,
            $context->elapsedMs(),
            $finishReason,
            $usage,
            self::capturesContent() ? $response : null,
        ));
    }

    public static function failed(?TelemetryContext $context, Throwable $exception): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        event(new GenerationFailed($context, $context->elapsedMs(), $exception));
    }

    public static function end(?TelemetryContext $context): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        self::stack()->pop($context);
    }

    public static function toolInvoked(?TelemetryContext $context, ToolCall $toolCall, ToolResult $toolResult, float $durationMs, ?int $toolIndex = null): void
    {
        if (! $context instanceof TelemetryContext) {
            return;
        }

        $capturesContent = self::capturesContent();

        event(new ToolInvoked(
            $toolIndex === null ? $context : $context->withTool($toolIndex),
            $toolCall->name,
            $toolCall->id,
            $durationMs,
            $capturesContent ? $toolCall : null,
            $capturesContent ? $toolResult : null,
        ));
    }

    /**
     * Wrap a provider stream generator, emitting step and completion telemetry as
     * the uniform stream events flow through. Events pass through untouched; tool
     * spans are emitted separately from the tool-execution chokepoint.
     *
     * @param  Generator<StreamEvent>  $events
     * @return Generator<StreamEvent>
     */
    public static function instrumentStream(TelemetryContext $context, Generator $events): Generator
    {
        $stepIndex = 0;
        $lastEnd = null;

        foreach ($events as $event) {
            if ($event instanceof StepFinishEvent) {
                event(new StepCompleted($context->withStep($stepIndex), null, $event->usage));

                $stepIndex++;
            } elseif ($event instanceof StreamEndEvent) {
                $lastEnd = $event;
            }

            yield $event;
        }

        self::completed($context, null, $lastEnd?->finishReason, $lastEnd?->usage);
    }
}
