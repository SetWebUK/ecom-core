<?php

namespace Pine\Commerce\Services\LiveChat\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\RequestOptions;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * Claude through the official Anthropic PHP SDK (Messages API, beta endpoint for the refusal fallback).
 *
 * - Adaptive thinking at effort "low" (a shop chat needs quick, short answers; thinking can't be switched off on the
 *   current models). Thinking blocks go back verbatim within one reply's tool loop, then are dropped.
 * - fallbacks: "default" (server-side-fallback-2026-07-01): a request the model declines is retried server-side on
 *   Anthropic's recommended fallback model; a model without fallbacks simply runs alone.
 * - Top-level cache_control: the system prompt, tools and earlier turns are cached between messages.
 */
class AnthropicProvider implements ChatProvider
{
    public const MAX_TOOL_ROUNDS = 5;

    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected ?ClientInterface $transporter = null,
    ) {}

    public function respond(string $system, array $transcript, array $tools, callable $runTool): array
    {
        $client = new Client(apiKey: $this->apiKey, requestOptions: RequestOptions::with(
            timeout: 60.0, maxRetries: 2, transporter: $this->transporter,
        ));
        $toolDefs = array_map(fn (array $t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['parameters']], $tools);
        $messages = $this->wire($transcript);
        $entries = [];
        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            try {
                $response = $client->beta->messages->create(
                    maxTokens: 8000,
                    messages: $messages,
                    model: $this->model,
                    cacheControl: ['type' => 'ephemeral'],
                    fallbacks: 'default',
                    outputConfig: ['effort' => 'low'],
                    system: $system,
                    thinking: ['type' => 'adaptive'],
                    tools: $toolDefs ?: null,
                    betas: ['server-side-fallback-2026-07-01'],
                );
            } catch (APIException $e) {
                throw new RuntimeException('Claude API: '.$e->getMessage(), 0, $e);
            }

            $usage['input'] += (int) ($response->usage->inputTokens ?? 0);
            $usage['output'] += (int) ($response->usage->outputTokens ?? 0);
            $usage['cache_read'] += (int) ($response->usage->cacheReadInputTokens ?? 0);

            if ($response->stopReason === 'refusal') {
                $entries[] = ['role' => 'assistant', 'text' => "Sorry, I can't help with that here. I can put you through to our team if you like.", 'tool_calls' => []];
                break;
            }

            $text = '';
            $calls = [];
            foreach ($response->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                } elseif ($block->type === 'tool_use') {
                    $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
                }
            }
            $entries[] = ['role' => 'assistant', 'text' => trim($text), 'tool_calls' => $calls];

            if ($response->stopReason !== 'tool_use' || ! $calls) {
                break;
            }
            if ($round === self::MAX_TOOL_ROUNDS) {
                // Too many lookups for one message: answer what we have rather than loop.
                $entries[] = ['role' => 'tool', 'results' => array_map(fn ($c) => ['id' => $c['id'], 'content' => '{"error":"lookup limit reached"}'], $calls)];
                $entries[] = ['role' => 'assistant', 'text' => 'Sorry, I could not find that just now. Would you like me to put you through to our team?', 'tool_calls' => []];
                break;
            }

            $results = [];
            foreach ($calls as $call) {
                $results[] = ['id' => $call['id'], 'content' => $runTool($call['name'], $call['input'])];
            }
            $entries[] = ['role' => 'tool', 'results' => $results];

            // Within this reply the assistant turn goes back exactly as received (thinking blocks included).
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => array_map(fn ($r) => ['type' => 'tool_result', 'toolUseID' => $r['id'], 'content' => $r['content']], $results)];
        }

        return ['entries' => $entries, 'usage' => $usage, 'model' => $this->model];
    }

    /** Neutral transcript → Messages API messages (consecutive same-role turns merged, as the API alternates roles). */
    protected function wire(array $transcript): array
    {
        $messages = [];
        foreach ($transcript as $entry) {
            [$role, $blocks] = match ($entry['role']) {
                'user' => ['user', [['type' => 'text', 'text' => (string) $entry['text']]]],
                'assistant' => ['assistant', array_merge(
                    trim((string) ($entry['text'] ?? '')) !== '' ? [['type' => 'text', 'text' => (string) $entry['text']]] : [],
                    array_map(fn ($c) => ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) $c['input']], $entry['tool_calls'] ?? []),
                )],
                'tool' => ['user', array_map(fn ($r) => ['type' => 'tool_result', 'toolUseID' => $r['id'], 'content' => (string) $r['content']], $entry['results'] ?? [])],
                default => [null, []],
            };
            if ($role === null || ! $blocks) {
                continue;
            }
            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $role) {
                $messages[$last]['content'] = array_merge($messages[$last]['content'], $blocks);
            } else {
                $messages[] = ['role' => $role, 'content' => $blocks];
            }
        }

        return $messages;
    }
}
