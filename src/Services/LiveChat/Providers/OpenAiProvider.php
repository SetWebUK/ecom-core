<?php

namespace Pine\Commerce\Services\LiveChat\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI Chat Completions with function calling, over Laravel's HTTP client. The model id is whatever the shop
 * enters in Admin › Live chat › Settings (OpenAI's model list changes often, so there is no built-in default).
 */
class OpenAiProvider implements ChatProvider
{
    public const MAX_TOOL_ROUNDS = 5;

    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(protected string $apiKey, protected string $model) {}

    public function respond(string $system, array $transcript, array $tools, callable $runTool): array
    {
        $messages = array_merge([['role' => 'system', 'content' => $system]], $this->wire($transcript));
        $functions = array_map(fn (array $t) => ['type' => 'function', 'function' => [
            'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['parameters'],
        ]], $tools);
        $entries = [];
        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            try {
                $response = Http::withToken($this->apiKey)->acceptJson()->timeout(60)->retry(2, 500, throw: false)
                    ->post(self::ENDPOINT, ['model' => $this->model, 'messages' => $messages] + ($functions ? ['tools' => $functions] : []));
            } catch (ConnectionException $e) {
                throw new RuntimeException('OpenAI API: '.$e->getMessage(), 0, $e);
            }
            if (! $response->successful()) {
                throw new RuntimeException('OpenAI API: HTTP '.$response->status().' '.($response->json('error.message') ?: ''));
            }

            $usage['input'] += (int) $response->json('usage.prompt_tokens', 0);
            $usage['output'] += (int) $response->json('usage.completion_tokens', 0);
            $usage['cache_read'] += (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0);

            $message = (array) $response->json('choices.0.message', []);
            $calls = [];
            foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                if (($call['type'] ?? 'function') !== 'function') {
                    continue;
                }
                $input = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $calls[] = ['id' => (string) $call['id'], 'name' => (string) ($call['function']['name'] ?? ''), 'input' => is_array($input) ? $input : []];
            }
            $text = trim((string) ($message['content'] ?? ''));
            if ($text === '' && ! $calls && filled($message['refusal'] ?? null)) {
                $text = "Sorry, I can't help with that here. I can put you through to our team if you like.";
            }
            $entries[] = $assistant = ['role' => 'assistant', 'text' => $text, 'tool_calls' => $calls];

            if (! $calls) {
                break;
            }
            if ($round === self::MAX_TOOL_ROUNDS) {
                $entries[] = ['role' => 'tool', 'results' => array_map(fn ($c) => ['id' => $c['id'], 'content' => '{"error":"lookup limit reached"}'], $calls)];
                $entries[] = ['role' => 'assistant', 'text' => 'Sorry, I could not find that just now. Would you like me to put you through to our team?', 'tool_calls' => []];
                break;
            }

            $results = [];
            foreach ($calls as $call) {
                $results[] = ['id' => $call['id'], 'content' => $runTool($call['name'], $call['input'])];
            }
            $entries[] = $toolEntry = ['role' => 'tool', 'results' => $results];
            $messages = array_merge($messages, $this->wire([$assistant, $toolEntry]));
        }

        return ['entries' => $entries, 'usage' => $usage, 'model' => $this->model];
    }

    /** Neutral transcript → Chat Completions messages. */
    protected function wire(array $transcript): array
    {
        $messages = [];
        foreach ($transcript as $entry) {
            switch ($entry['role'] ?? null) {
                case 'user':
                    $messages[] = ['role' => 'user', 'content' => (string) $entry['text']];
                    break;
                case 'assistant':
                    $calls = $entry['tool_calls'] ?? [];
                    $text = trim((string) ($entry['text'] ?? ''));
                    if ($text === '' && ! $calls) {
                        break;
                    }
                    $message = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
                    if ($calls) {
                        $message['tool_calls'] = array_map(fn ($c) => ['id' => $c['id'], 'type' => 'function', 'function' => [
                            'name' => $c['name'], 'arguments' => json_encode((object) $c['input']),
                        ]], $calls);
                    }
                    $messages[] = $message;
                    break;
                case 'tool':
                    foreach ($entry['results'] ?? [] as $result) {
                        $messages[] = ['role' => 'tool', 'tool_call_id' => $result['id'], 'content' => (string) $result['content']];
                    }
                    break;
            }
        }

        return $messages;
    }
}
