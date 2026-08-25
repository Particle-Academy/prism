<?php

declare(strict_types=1);

use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\UserMessage;

it('keeps a prompt that is exactly "0"', function (): void {
    // "0" is a legitimate prompt — an answer to "how many?", a menu choice, a
    // test fixture. Gating on truthiness drops it.
    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withPrompt('0')
        ->toRequest();

    expect($request->messages())->toHaveCount(1)
        ->and($request->messages()[0]->text())->toBe('0');
});

it('still refuses prompt and messages together when the prompt is "0"', function (): void {
    // The worse half. The refusal is gated on the same truthiness, so a caller
    // who set both gets no error AND no prompt — a successful call that
    // answered a different question than the one asked.
    expect(fn (): Request => (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withMessages([new UserMessage('What is 2 + 2?')])
        ->withPrompt('0')
        ->toRequest())
        ->toThrow(PrismException::class);
});
