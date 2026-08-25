<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait ExtractsAdditionalContent
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    protected function extractsAdditionalContent(array $data): array
    {
        $searchResults = $this->extractsSearchResults($data);
        $fetchResults = $this->extractsFetchResults($data);

        return Arr::whereNotNull([
            // Kept under the same key the Sonar shape used, so consumers that
            // already read search_results keep working across the cut.
            'search_results' => $searchResults === [] ? null : $searchResults,
            'fetch_url_results' => $fetchResults === [] ? null : $fetchResults,
            // Which model a preset actually resolved to. Surfaced because a
            // preset can route to a third party, and both token ledgers and
            // data-handling decisions turn on knowing which one served a call.
            'resolved_model' => data_get($data, 'model'),
            'reasoning' => $this->extractsReasoning($this->extractsText($data)),
        ]);
    }

    /**
     * Pulls the reasoning out of content wrapped in <think> tags.
     *
     * @throws \JsonException
     */
    protected function extractsReasoning(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        $str = Str::of($content);
        if (! $str->contains('<think>')) {
            return null;
        }

        return $str->between('<think>', '</think>')->trim()->toString();
    }
}
