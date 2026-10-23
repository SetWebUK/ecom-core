<?php

namespace Pine\Commerce\Services\LiveChat\Providers;

/**
 * One AI provider of the live chat (Claude or OpenAI). The conversation reaches it as a provider-neutral transcript,
 * so a shop can switch provider mid-conversation:
 *
 *   ['role' => 'user', 'text' => '…']
 *   ['role' => 'assistant', 'text' => '…', 'tool_calls' => [['id' => '…', 'name' => '…', 'input' => [...]]]]
 *   ['role' => 'tool', 'results' => [['id' => '…', 'content' => '…']]]
 *
 * respond() runs the whole tool loop for one customer message (calling $runTool for each lookup) and returns the new
 * entries in order, in the same neutral shape, plus usage for the admin. Model reasoning ("thinking") is never part of
 * the stored transcript: it is passed back verbatim within one reply's tool loop and dropped afterwards, which keeps
 * the stored history append-only and provider-neutral.
 */
interface ChatProvider
{
    /**
     * @param  list<array<string,mixed>>  $transcript  neutral entries, oldest first, ending with the customer's message
     * @param  list<array{name:string, description:string, parameters:array}>  $tools
     * @param  callable(string $name, array $input): string  $runTool
     * @return array{entries: list<array<string,mixed>>, usage: array<string,int>, model: string}
     */
    public function respond(string $system, array $transcript, array $tools, callable $runTool): array;
}
