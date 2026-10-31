# Request payload projection

For Anthropic and OpenAI, `projectPayload()` returns the JSON body for a text,
structured, or streaming request without sending it. It includes the provider's
message mapping, tools, options, and structured output instructions. OpenAI uses
the configured `api_format`.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

$generation = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-4-6')
    ->withPrompt('Describe the purpose of a queue.');

$payload = $generation->provider()->projectPayload(
    $generation->toRequest(),
    'text', // 'text', 'structured', or 'stream'
);
```

Projection preserves the supplied request and does not execute tools, evaluate
approval callbacks, dispatch telemetry, or fetch remote media. Structured
instructions are added to a clone. The mode must match the request type.

Other providers return `null`. A custom provider can override `projectPayload()`
to expose its own mapping; it must keep that projection consistent with its
sender.

## Approval resolution

If the sender must resolve outstanding tool approvals before dispatch, projection
throws `Prism\Prism\Exceptions\PrismUnprojectableRequestException`. Approval
resolution can execute a tool and produce new input whose contents cannot be
predicted without running it. History that already contains those tool results
can be projected.

## Limits

The returned body contains prompt and tool content. Treat it as sensitive data.
Credentials, headers, and transport options are excluded. This is a JSON payload
projection, not a token count or price estimate. Media, server tools, and context
stored by the provider can incur costs beyond the returned JSON bytes.
