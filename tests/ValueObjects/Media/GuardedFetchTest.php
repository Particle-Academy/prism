<?php

declare(strict_types=1);

namespace Tests\ValueObjects\Media;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Support\HostResolver;
use Prism\Prism\ValueObjects\Media\Image;

/*
|--------------------------------------------------------------------------
| prism#44: an opt-in guarded fetch
|--------------------------------------------------------------------------
|
| `fetchUrlContent()` is deliberately unguarded and says so. The migration it
| invites is the dangerous one: code that broke when implicit fetching was
| removed gets `->fetchUrlContent()` added wherever it failed, often on a URL
| that came from a request or from model output. That is server-side request
| forgery, now written as an explicit line.
|
| `fetchPublicUrlContent()` is for callers who cannot trust the URL. Every test
| here asks what a HOSTILE url does, because the well-behaved one tells us
| almost nothing.
|
*/

/** Resolve any host to whatever the test says, so no test touches real DNS. */
function resolvesTo(array $map): void
{
    app()->instance(HostResolver::class, new class($map) implements HostResolver
    {
        public function __construct(private array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    });
}

it('refuses the cloud metadata endpoint, the address this exists for', function (): void {
    // 169.254.169.254 hands out role credentials to anything inside the
    // network that asks. It is link-local, so the IP check alone refuses it.
    Http::fake();

    expect(fn (): Image => Image::fromUrl('http://169.254.169.254/latest/meta-data/iam/security-credentials/')
        ->fetchPublicUrlContent())
        ->toThrow(PrismException::class);

    Http::assertNothingSent();
});

it('refuses a private or loopback literal', function (string $url): void {
    Http::fake();

    expect(fn (): Image => Image::fromUrl($url)->fetchPublicUrlContent())->toThrow(PrismException::class);

    Http::assertNothingSent();
})->with([
    'loopback' => ['http://127.0.0.1/x.png'],
    'loopback by name' => ['http://localhost/x.png'],
    'private 10/8' => ['http://10.0.0.5/x.png'],
    'private 192.168' => ['http://192.168.1.10/x.png'],
    'ipv6 loopback' => ['http://[::1]/x.png'],
    'ipv6 unique local' => ['http://[fd00::1]/x.png'],
]);

it('refuses a public NAME that resolves to a private address', function (): void {
    // The bypass the IP check alone misses: an attacker controls the DNS
    // record, so the host looks public and the connection is internal.
    resolvesTo(['evil.test' => ['10.0.0.5']]);
    Http::fake();

    expect(fn (): Image => Image::fromUrl('http://evil.test/x.png')->fetchPublicUrlContent())
        ->toThrow(PrismException::class);

    Http::assertNothingSent();
});

it('refuses a host that resolves to nothing', function (): void {
    resolvesTo([]);
    Http::fake();

    expect(fn (): Image => Image::fromUrl('http://nowhere.test/x.png')->fetchPublicUrlContent())
        ->toThrow(PrismException::class);
});

it('refuses a scheme that is not http or https', function (string $url): void {
    Http::fake();

    expect(fn (): Image => Image::fromUrl($url)->fetchPublicUrlContent())->toThrow(PrismException::class);

    Http::assertNothingSent();
})->with([
    'file' => ['file:///etc/passwd'],
    'gopher' => ['gopher://example.test/x'],
    'no scheme' => ['//example.test/x.png'],
]);

it('does not follow a redirect into a private address', function (): void {
    // The check that makes the rest worth having. A public host answering 302
    // to http://127.0.0.1/ defeats a guard that only validates the URL it was
    // given, and redirects are followed by default.
    resolvesTo(['public.test' => ['93.184.216.34']]);

    Http::fake([
        'public.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response('SECRET', 200),
    ]);

    expect(fn (): Image => Image::fromUrl('http://public.test/x.png')->fetchPublicUrlContent())
        ->toThrow(PrismException::class);

    // And it never asked for the internal address at all.
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '169.254.169.254'));
});

it('fetches a genuinely public url', function (): void {
    // The control. A guard that refuses everything would pass every test above.
    resolvesTo(['public.test' => ['93.184.216.34']]);

    Http::fake([
        'public.test/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200),
    ]);

    $image = Image::fromUrl('https://public.test/x.png')->fetchPublicUrlContent();

    expect($image->hasRawContent())->toBeTrue()
        ->and($image->mimeType())->toBe('image/png');
});

it('leaves the unguarded fetch unguarded, which is the documented contract', function (): void {
    // The control for the guard itself: this test failing would mean the guard
    // had been applied to `fetchUrlContent()`, changing behaviour for every
    // existing caller rather than offering them something safer.
    Http::fake([
        '127.0.0.1/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200),
    ]);

    expect(Image::fromUrl('http://127.0.0.1/x.png')->fetchUrlContent()->hasRawContent())->toBeTrue();
});
