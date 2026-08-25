<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\Perplexity\Maps\InputMapper;
use Prism\Prism\Providers\Perplexity\Maps\PresetMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

trait HandlesHttpRequests
{
    protected function sendRequest(PendingRequest $client, TextRequest|StructuredRequest $request, bool $stream = false): Response
    {
        return $client->post(
            '/v1/agent',
            $this->buildHttpRequestPayload($request, $stream)
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    protected function buildHttpRequestPayload(TextRequest|StructuredRequest $request, bool $stream = false): array
    {
        $payload = [
            'input' => InputMapper::map($request->messages()),
            'stream' => $stream,
        ];

        // `preset` and `model` are mutually exclusive, and NOT because the API
        // rejects the pair — it accepts both and lets the explicit model win,
        // so leaving `model` populated silently ignores the preset. Sending
        // exactly one is what makes the translation actually take effect.
        $payload += $this->modelOrPreset($request);

        return array_merge($payload, Arr::whereNotNull([
            'instructions' => $this->instructions($request),
            'response_format' => $this->responseFormat($request),
            'max_output_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => $request->providerOptions('tools'),
            'background' => $request->providerOptions('background'),
            'search_mode' => $request->providerOptions('search_mode'),
            'search_domain_filter' => $request->providerOptions('search_domain_filter'),
            'search_recency_filter' => $request->providerOptions('search_recency_filter'),
            'search_after_date_filter' => $request->providerOptions('search_after_date_filter'),
            'search_before_date_filter' => $request->providerOptions('search_before_date_filter'),
            'last_updated_after_filter' => $request->providerOptions('last_updated_after_filter'),
            'last_updated_before_filter' => $request->providerOptions('last_updated_before_filter'),
            'return_images' => $request->providerOptions('return_images'),
            'return_related_questions' => $request->providerOptions('return_related_questions'),
            'language_preference' => $request->providerOptions('language_preference'),
            'reasoning_effort' => $request->providerOptions('reasoning_effort'),
        ]));
    }

    /**
     * Exactly one of `preset` or `model`, never both.
     *
     * An explicit `preset` provider option always wins — that is the escape
     * hatch for anyone who disagrees with the slug translation and wants to pay
     * for a different tier.
     *
     * @return array<string, string>
     */
    protected function modelOrPreset(TextRequest|StructuredRequest $request): array
    {
        $explicit = $request->providerOptions('preset');

        if (is_string($explicit) && $explicit !== '') {
            return ['preset' => $explicit];
        }

        $model = $request->model();
        $preset = PresetMap::presetFor($model);

        // An unrecognised string is a real model id (`openai/gpt-5.6-sol`), not
        // a preset we should guess at.
        return $preset === null
            ? ['model' => $model]
            : ['preset' => $preset];
    }

    /**
     * The system prompt, as the Agent API's `instructions`.
     *
     * Worth knowing before you set one: `instructions` REPLACES the preset's
     * own system prompt rather than being appended to it. A preset is a model
     * plus a prompt tuned against real workloads, and passing instructions
     * discards that half of it. Nothing errors; the answers just change.
     *
     * Prism only sends this when the caller actually set a system prompt, so
     * the preset keeps its own prompt by default.
     */
    protected function instructions(TextRequest|StructuredRequest $request): ?string
    {
        $prompts = array_map(
            fn (\Prism\Prism\ValueObjects\Messages\SystemMessage $message): string => $message->content,
            $request->systemPrompts(),
        );

        $joined = trim(implode("\n\n", array_filter($prompts, fn (string $p): bool => trim($p) !== '')));

        return $joined === '' ? null : $joined;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function responseFormat(TextRequest|StructuredRequest $request): ?array
    {
        if (! $request instanceof StructuredRequest) {
            return null;
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'schema' => $request->schema()->toArray(),
            ],
        ];
    }
}
