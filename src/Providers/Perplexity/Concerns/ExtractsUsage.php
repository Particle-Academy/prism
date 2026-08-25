<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait ExtractsUsage
{
    /**
     * The Agent API names these input/output rather than prompt/completion.
     * The old keys are read as a fallback so a fixture or a proxy still
     * speaking the Sonar shape does not silently report zero tokens.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): Usage
    {
        return new Usage(
            promptTokens: (int) (data_get($data, 'usage.input_tokens')
                ?? data_get($data, 'usage.prompt_tokens')
                ?? 0),
            completionTokens: (int) (data_get($data, 'usage.output_tokens')
                ?? data_get($data, 'usage.completion_tokens')
                ?? 0),
        );
    }
}
