<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

/**
 * prism#31 — Sonar chat/completions options against a strict-decode endpoint.
 *
 * The Agent API rejects an unknown field with a 400 rather than ignoring it,
 * and `Arr::whereNotNull` only sends what a caller set. So every one of these
 * was a latent failure that surfaced solely on the runs where somebody
 * populated it: the reporter's model narrowed a search on a fraction of its
 * runs, which is why this reached production instead of being caught at once.
 */
beforeEach(function (): void {
    config()->set('prism.providers.perplexity.api_key', env('PERPLEXITY_API_KEY', 'pplx-FJr'));
});

it('reproduces the reported call WITHOUT the field that 400d', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar-pro')
        ->withProviderOptions(['search_domain_filter' => ['example.com']])
        ->withPrompt('What is their current pricing?')->asText();

    Http::assertSent(function ($request): bool {
        expect($request->data())->not->toHaveKey('search_domain_filter');

        return true;
    });
});

it('moves a top-level filter onto a web_search tool rather than dropping it', function (): void {
    // Translated, not discarded. Silently dropping a domain allowlist is the
    // worse of the two failures: the request succeeds, the search is quietly
    // broader than asked, and the answer cites sources deliberately excluded.
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')
        ->withProviderOptions(['search_domain_filter' => ['example.com']])
        ->withPrompt('Hi')->asText();

    Http::assertSent(function ($request): bool {
        expect($request->data()['tools'])->toBe([
            ['type' => 'web_search', 'filters' => ['search_domain_filter' => ['example.com']]],
        ]);

        return true;
    });
});

it('merges a top-level filter into a web_search tool the caller already declared', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')->withProviderOptions([
        'tools' => [['type' => 'web_search', 'filters' => ['search_recency_filter' => 'week']]],
        'search_domain_filter' => ['example.com'],
    ])->withPrompt('Hi')->asText();

    Http::assertSent(function ($request): bool {
        expect($request->data()['tools'][0]['filters'])->toBe([
            'search_domain_filter' => ['example.com'],
            'search_recency_filter' => 'week',
        ]);

        return true;
    });
});

it('lets an explicit nested filter WIN over the translated one', function (): void {
    // The same principle as `preset` over `model`: the specific spelling beats
    // the translated one, so a caller who has already migrated is never
    // second-guessed.
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')->withProviderOptions([
        'tools' => [['type' => 'web_search', 'filters' => ['search_domain_filter' => ['migrated.test']]]],
        'search_domain_filter' => ['stale.test'],
    ])->withPrompt('Hi')->asText();

    Http::assertSent(function ($request): bool {
        expect($request->data()['tools'][0]['filters']['search_domain_filter'])->toBe(['migrated.test']);

        return true;
    });
});

it('adds a web_search tool when the declared tools cannot carry a filter', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')->withProviderOptions([
        'tools' => [['type' => 'fetch_url']],
        'search_recency_filter' => 'day',
    ])->withPrompt('Hi')->asText();

    Http::assertSent(function ($request): bool {
        expect($request->data()['tools'])->toBe([
            ['type' => 'fetch_url'],
            ['type' => 'web_search', 'filters' => ['search_recency_filter' => 'day']],
        ]);

        return true;
    });
});

it('translates every one of the six filters, not just the reported one', function (): void {
    // The reporter's point, and it was right: `search_domain_filter` was simply
    // the first a model happened to use. Fixing that one alone would have left
    // every sibling armed.
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    $filters = [
        'search_domain_filter' => ['example.com'],
        'search_recency_filter' => 'week',
        'search_after_date_filter' => '01/01/2026',
        'search_before_date_filter' => '31/12/2026',
        'last_updated_after_filter' => '01/06/2026',
        'last_updated_before_filter' => '30/06/2026',
    ];

    Prism::text()->using(Provider::Perplexity, 'sonar')
        ->withProviderOptions($filters)->withPrompt('Hi')->asText();

    Http::assertSent(function ($request) use ($filters): bool {
        foreach (array_keys($filters) as $option) {
            expect($request->data())->not->toHaveKey($option);
        }

        expect($request->data()['tools'][0]['filters'])->toBe($filters);

        return true;
    });
});

it('maps reasoning_effort onto reasoning.effort', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')
        ->withProviderOptions(['reasoning_effort' => 'high'])->withPrompt('Hi')->asText();

    Http::assertSent(function ($request): bool {
        expect($request->data())->not->toHaveKey('reasoning_effort');
        expect($request->data()['reasoning'])->toBe(['effort' => 'high']);

        return true;
    });
});

it('lets an explicit reasoning object win over reasoning_effort', function (): void {
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')->withProviderOptions([
        'reasoning' => ['effort' => 'low'],
        'reasoning_effort' => 'high',
    ])->withPrompt('Hi')->asText();

    Http::assertSent(fn ($request): bool => $request->data()['reasoning'] === ['effort' => 'low']);
});

it('REFUSES the three options the Agent API has no answer for', function (string $option): void {
    // Refused rather than dropped. The caller asked for something, and a
    // successful response that does not contain it is worse than an error --
    // the same argument `assertToolsAreReachable` already makes for withTools().
    expect(fn () => Prism::text()->using(Provider::Perplexity, 'sonar')
        ->withProviderOptions([$option => 'x'])->withPrompt('Hi')->asText())
        ->toThrow(PrismException::class, $option);
})->with(['search_mode', 'return_images', 'return_related_questions']);

it('still sends language_preference, which the Agent API DOES accept', function (): void {
    // Not every Sonar-era name moved. Removing this one along with its
    // neighbours would have been the opposite mistake, and just as invisible.
    FixtureResponse::fakeResponseSequence('v1/agent', 'perplexity/agent-generate-text-with-a-prompt');

    Prism::text()->using(Provider::Perplexity, 'sonar')
        ->withProviderOptions(['language_preference' => 'en'])->withPrompt('Hi')->asText();

    Http::assertSent(fn ($request): bool => $request->data()['language_preference'] === 'en');
});
