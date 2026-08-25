<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Perplexity\Maps\PresetMap;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx-FJr'));
});

it('posts to the agent endpoint, not the retired sonar one', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/v1/agent'));
});

it('reads the answer out of the typed output array', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    $response = Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt("How's the weather in southern Brazil?")
        ->asText();

    expect($response->text)->toContain('Southern Brazil in mid-November')
        ->and($response->usage->promptTokens)->toBe(8)
        ->and($response->usage->completionTokens)->toBe(346)
        ->and($response->meta->id)->toBe('resp_5f9c1a2b3d4e5f60');
});

it('keeps sources as structured data rather than prose', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

    $results = $response->additionalContent['search_results'];

    // Resolvable sources are the whole value of this provider — a caller
    // repeating an answer has to be able to check where it came from.
    expect($results)->toHaveCount(2)
        ->and($results[0]['url'])->toBe('https://www.sunheron.com/south-america/brazil/south-brazil-weather-november/')
        ->and($results[1]['title'])->toBe('Climate of South Brazil')
        ->and($response->text)->not->toContain('http');
});

it('surfaces which model a preset actually resolved to', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-no-search-results');

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('2+2?')->asText();

    // A preset can route to a third party. Token ledgers and data-handling
    // decisions both turn on knowing which vendor served the call.
    expect($response->additionalContent['resolved_model'])->toBe('google/gemini-3-pro');
});

it('treats an empty source list on a completed run as normal', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-no-search-results');

    $response = Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('2+2?')->asText();

    expect($response->text)->toBe('Four.')
        ->and($response->additionalContent)->not->toHaveKey('search_results');
});

it('fails on a failed run even though the transport said 200', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-run-failed');

    expect(fn (): Response => Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Hi')
        ->asText())
        ->toThrow(PrismException::class, 'run_failed');
});

it('fails on a cancelled run too, not only a failed one', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-run-cancelled');

    expect(fn (): Response => Prism::text()
        ->using(Provider::Perplexity, 'sonar')
        ->withPrompt('Hi')
        ->asText())
        ->toThrow(PrismException::class, 'run_cancelled');
});

describe('preset translation', function (): void {
    it('sends a preset and omits model for a retired slug', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar-pro')->withPrompt('Hi')->asText();

        Http::assertSent(function ($request): bool {
            // Both fields together is not an error the API reports — it
            // silently prefers `model`, so the preset would be ignored.
            expect($request->data())->toHaveKey('preset')
                ->and($request->data()['preset'])->toBe('low')
                ->and($request->data())->not->toHaveKey('model');

            return true;
        });
    });

    it('maps deep research to medium, the behavioural equivalent', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar-deep-research')->withPrompt('Hi')->asText();

        // Perplexity's migration overview suggests `high`, but their own preset
        // rename shows the preset formerly called deep-research is now called
        // medium. `high` is the tier above — a costlier upgrade, not an
        // equivalent, and one that bills more while returning a plausible
        // answer.
        Http::assertSent(fn ($request): bool => $request->data()['preset'] === 'medium');
    });

    it('passes a real model id through as model, not a guessed preset', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'openai/gpt-5.6-sol')->withPrompt('Hi')->asText();

        Http::assertSent(function ($request): bool {
            expect($request->data())->toHaveKey('model')
                ->and($request->data()['model'])->toBe('openai/gpt-5.6-sol')
                ->and($request->data())->not->toHaveKey('preset');

            return true;
        });
    });

    it('lets an explicit preset override the translation', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Perplexity, 'sonar-deep-research')
            ->withProviderOptions(['preset' => 'high'])
            ->withPrompt('Hi')
            ->asText();

        Http::assertSent(fn ($request): bool => $request->data()['preset'] === 'high');
    });

    it('accepts a preset name given directly as the model', function (): void {
        expect(PresetMap::presetFor('xhigh'))->toBe('xhigh')
            ->and(PresetMap::presetFor('sonar'))->toBe('fast')
            ->and(PresetMap::presetFor('openai/gpt-5.6-sol'))->toBeNull();
    });
});

describe('input mapping', function (): void {
    it('sends a single user turn as a plain string', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Just this')->asText();

        Http::assertSent(fn ($request): bool => $request->data()['input'] === 'Just this');
    });

    it('keeps roles when there is a conversation', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Perplexity, 'sonar')
            ->withMessages([
                new UserMessage('First question'),
                new AssistantMessage('First answer'),
                new UserMessage('Follow up'),
            ])
            ->asText();

        // Flattening a conversation into one string loses which side said
        // what, and a model that cannot tell its own answers from the user's
        // questions answers worse.
        Http::assertSent(function ($request): bool {
            expect($request->data()['input'])->toBe([
                ['role' => 'user', 'content' => 'First question'],
                ['role' => 'assistant', 'content' => 'First answer'],
                ['role' => 'user', 'content' => 'Follow up'],
            ]);

            return true;
        });
    });

    it('sends a system prompt as instructions, and only when set', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()->using(Provider::Perplexity, 'sonar')->withPrompt('Hi')->asText();

        // Absent by default on purpose: instructions REPLACE a preset's own
        // system prompt, so sending an empty one would throw away half of what
        // the preset is.
        Http::assertSent(fn ($request): bool => ! array_key_exists('instructions', $request->data()));
    });

    it('passes a system prompt through when the caller sets one', function (): void {
        FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

        Prism::text()
            ->using(Provider::Perplexity, 'sonar')
            ->withSystemPrompt('Answer only in French.')
            ->withPrompt('Hi')
            ->asText();

        Http::assertSent(fn ($request): bool => $request->data()['instructions'] === 'Answer only in French.');
    });
});
