<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRunException;

/**
 * Reads the Agent API's typed output array.
 *
 * The Sonar shape was `choices[]` with a `citations` array beside it. The Agent
 * API returns `output[]` — one item per step, each with a `type` — so the
 * answer and its sources are separate items rather than a field and a sibling.
 */
trait ExtractsAgentResponse
{
    use ExtractsUsage;

    /**
     * Reject unsuccessful non-streaming text and structured runs despite HTTP 200.
     * Text and Structured call this guard. Stream does not: its terminal-event
     * handling still lacks this run-failure diagnostics contract.
     *
     * This is the trap that makes Agent API errors invisible: a failed or
     * cancelled run comes back as HTTP 200 with `status` set to "failed" or
     * "cancelled" and a populated `error`. Code that branches on the HTTP
     * status alone treats it as a success with a suspiciously empty answer.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertRunSucceeded(#[\SensitiveParameter] array $data): void
    {
        $status = data_get($data, 'status');

        if ($status === null || $status === 'completed') {
            return;
        }

        $message = data_get($data, 'error.message')
            ?? data_get($data, 'error.type')
            ?? 'no error detail was returned';

        // The 200 in this message is not a mistake — it is the finding. The
        // run failed and the transport said everything was fine.
        $errorCode = 'run_'.(is_string($status) ? $status : 'unknown');
        $exception = PrismException::providerRequestErrorWithDetails(
            provider: 'Perplexity',
            statusCode: 200,
            errorType: $errorCode,
            errorMessage: is_string($message) ? $message : 'no error detail was returned',
        );

        throw new PrismRunException(
            errorCode: $errorCode,
            message: $exception->getMessage(),
            runData: $data,
            runUsage: is_array($data['usage'] ?? null) ? $this->extractUsage($data) : null,
        );
    }

    /**
     * The answer text, concatenated across message items.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractsText(array $data): string
    {
        $text = '';

        foreach ($this->outputItemsOfType($data, 'message') as $item) {
            foreach (data_get($item, 'content') ?? [] as $content) {
                if (data_get($content, 'type') === 'output_text') {
                    $text .= (string) data_get($content, 'text', '');
                }
            }
        }

        return $text;
    }

    /**
     * Sources, kept as structured data.
     *
     * Deliberately not flattened into the answer prose. A caller repeating an
     * answer to somebody else needs to be able to resolve where it came from,
     * and that is only possible while the sources are still addressable.
     *
     * An empty list on a COMPLETED run is normal, not an error — a preset may
     * answer without searching.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected function extractsSearchResults(array $data): array
    {
        $results = [];

        foreach ($this->outputItemsOfType($data, 'search_results') as $item) {
            foreach (data_get($item, 'results') ?? [] as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected function extractsFetchResults(array $data): array
    {
        $results = [];

        foreach ($this->outputItemsOfType($data, 'fetch_url_results') as $item) {
            foreach (data_get($item, 'results') ?? [] as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * The text delta carried by one streamed chunk.
     *
     * The Agent API streams Responses-shaped events — a `type` naming the event
     * and a `delta` carrying the text — where Sonar streamed OpenAI chat
     * chunks. Both are read: a proxy or a recorded fixture may still speak the
     * older shape, and returning '' for a chunk we simply failed to recognise
     * would drop text with nothing to show for it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractsStreamDelta(array $data): string
    {
        $type = data_get($data, 'type');

        if (is_string($type) && str_ends_with($type, 'output_text.delta')) {
            return (string) data_get($data, 'delta', '');
        }

        return (string) data_get($data, 'choices.0.delta.content', '');
    }

    /**
     * Has this chunk ended the run?
     *
     * @param  array<string, mixed>  $data
     */
    protected function isStreamTerminal(array $data): bool
    {
        $type = data_get($data, 'type');

        if (is_string($type) && (str_ends_with($type, '.completed') || str_ends_with($type, '.failed'))) {
            return true;
        }

        return data_get($data, 'status') !== null
            || data_get($data, 'choices.0.finish_reason') !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected function outputItemsOfType(array $data, string $type): array
    {
        $items = data_get($data, 'output', []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            $items,
            fn ($item): bool => data_get($item, 'type') === $type,
        ));
    }
}
