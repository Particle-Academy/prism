<?php

declare(strict_types=1);

namespace Tests\Telemetry;

use Generator;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

beforeEach(function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
});

it('emits nothing when telemetry is disabled', function (): void {
    config()->set('prism.telemetry.enabled', false);

    Event::fake();

    Prism::fake([TextResponseFake::make()->withText('hi')]);

    Prism::text()->using('anthropic', 'claude-3')->withPrompt('q')->asText();

    Event::assertNotDispatched(GenerationStarted::class);
    Event::assertNotDispatched(GenerationCompleted::class);
});

it('emits started and completed around a successful text generation', function (): void {
    Event::fake();

    Prism::fake([
        TextResponseFake::make()
            ->withText('The meaning of life is 42')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(42, 7)),
    ]);

    Prism::text()->using('anthropic', 'claude-3-sonnet')->withPrompt('q')->asText();

    Event::assertDispatched(GenerationStarted::class, fn (GenerationStarted $e): bool => $e->context->operation === TelemetryOperation::Text
        && $e->context->provider === 'anthropic'
        && $e->context->model === 'claude-3-sonnet'
        && $e->context->traceId !== '');

    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->finishReason === FinishReason::Stop
        && $e->usage?->promptTokens === 42
        && $e->usage?->completionTokens === 7
        && $e->durationMs >= 0.0);
});

it('omits request content unless capture_content is enabled', function (): void {
    Event::fake();

    Prism::fake([TextResponseFake::make()->withText('x')]);

    Prism::text()->using('anthropic', 'm')->withPrompt('secret prompt')->asText();

    Event::assertDispatched(GenerationStarted::class, fn (GenerationStarted $e): bool => $e->request === null);
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->response === null);
});

it('includes request and response content when capture_content is enabled', function (): void {
    config()->set('prism.telemetry.capture_content', true);

    Event::fake();

    Prism::fake([TextResponseFake::make()->withText('x')]);

    Prism::text()->using('anthropic', 'm')->withPrompt('secret prompt')->asText();

    Event::assertDispatched(GenerationStarted::class, fn (GenerationStarted $e): bool => $e->request !== null);
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->response !== null);
});

it('emits a StepCompleted event per response step', function (): void {
    Event::fake();

    Prism::fake([
        TextResponseFake::make()
            ->withText('done')
            ->withSteps(collect([
                TextStepFake::make()->withUsage(new Usage(1, 1)),
                TextStepFake::make()->withUsage(new Usage(2, 2)),
            ])),
    ]);

    Prism::text()->using('anthropic', 'm')->withPrompt('q')->asText();

    Event::assertDispatchedTimes(StepCompleted::class, 2);
    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $e): bool => $e->context->stepIndex === 1);
});

it('start returns null and dispatches nothing when disabled', function (): void {
    config()->set('prism.telemetry.enabled', false);

    Event::fake();

    expect(Telemetry::start(TelemetryOperation::Text, 'openai', 'm'))->toBeNull();

    Event::assertNotDispatched(GenerationStarted::class);
});

it('emits GenerationFailed for a failed generation', function (): void {
    Event::fake();

    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'm', microtime(true));

    Telemetry::failed($context, new RuntimeException('boom'));

    Event::assertDispatched(GenerationFailed::class, fn (GenerationFailed $e): bool => $e->exception->getMessage() === 'boom'
        && $e->context->traceId === 'trace'
        && $e->durationMs >= 0.0);
});

it('instruments a stream: passes events through and emits step + completion telemetry', function (): void {
    Event::fake();

    $context = Telemetry::start(TelemetryOperation::Stream, 'openai', 'gpt-x');
    expect($context)->not->toBeNull();

    $inner = (function (): Generator {
        yield new StepFinishEvent('s1', time(), new Usage(1, 2));
        yield new StreamEndEvent('e1', time(), FinishReason::Stop, new Usage(3, 4));
    })();

    $events = iterator_to_array(Telemetry::instrumentStream($context, $inner), false);

    Telemetry::end($context);

    expect($events)->toHaveCount(2);

    Event::assertDispatchedTimes(StepCompleted::class, 1);
    Event::assertDispatched(GenerationCompleted::class, fn (GenerationCompleted $e): bool => $e->finishReason === FinishReason::Stop
        && $e->usage?->completionTokens === 4);
});

it('emits ToolInvoked with identity + duration and withholds content by default', function (): void {
    Event::fake();

    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'm', microtime(true));

    Telemetry::toolInvoked(
        $context,
        new ToolCall('call-1', 'weather', ['city' => 'NYC']),
        new ToolResult(toolCallId: 'call-1', toolName: 'weather', args: ['city' => 'NYC'], result: 'Sunny'),
        12.5,
        0,
    );

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e): bool => $e->context->toolIndex === 0
        && $e->durationMs === 12.5
        && $e->toolName === 'weather'
        && $e->toolCallId === 'call-1'
        // content withheld because capture_content is off (the default)
        && ! $e->toolCall instanceof ToolCall
        && ! $e->toolResult instanceof ToolResult);
});

it('includes tool call and result content only when capture_content is enabled', function (): void {
    config()->set('prism.telemetry.capture_content', true);

    Event::fake();

    $context = new TelemetryContext('trace', TelemetryOperation::Text, 'openai', 'm', microtime(true));

    Telemetry::toolInvoked(
        $context,
        new ToolCall('call-1', 'weather', ['city' => 'NYC']),
        new ToolResult(toolCallId: 'call-1', toolName: 'weather', args: ['city' => 'NYC'], result: 'Sunny'),
        12.5,
        0,
    );

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e): bool => $e->toolName === 'weather'
        && $e->toolCall?->name === 'weather'
        && $e->toolResult?->result === 'Sunny');
});
