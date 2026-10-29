<?php

namespace McpHub\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Security\Assertions;
use McpHub\Laravel\Security\Privacy;
use McpHub\Laravel\Support\Json;
use RuntimeException;

/** Server SDK. Caller identity always comes from the application's resolver. */
final class Gateway
{
    private $transport;
    private $resolver;
    private $assertions;

    public function __construct(HubTransport $transport, ActorResolver $resolver, Assertions $assertions)
    {
        $this->transport = $transport;
        $this->resolver = $resolver;
        $this->assertions = $assertions;
    }

    public function models(Authenticatable $user): array
    {
        $this->resolver->forUser($user);
        $result = $this->json('GET', '/gateway/v1/models');
        if (! is_array($result['data'] ?? null)) {
            throw new RuntimeException('Hub model catalog is invalid.');
        }
        return $result;
    }

    public function complete(Authenticatable $user, array $input): array
    {
        if (array_diff(array_keys($input), ['model', 'messages', 'tools', 'tool_choice', 'max_tokens', 'temperature', 'top_p'])) {
            throw new InvalidArgumentException('Completion contains unsupported fields.');
        }
        if (! is_string($input['model'] ?? null) || strlen($input['model']) > 240
            || ! is_array($input['messages'] ?? null) || count($input['messages']) > 50) {
            throw new InvalidArgumentException('Completion input is invalid.');
        }
        Privacy::assertSafe($input['messages']);
        $actor = $this->resolver->forUser($user);
        $identity = strtr(hash('sha256', Json::encode([$actor->reference, $actor->tenant])),
            '0123456789abcdef', 'abcdefghijklmnop');
        $result = $this->json('POST', '/gateway/v1/chat/completions',
            $input + ['stream' => false, 'metadata' => ['end_user_id' => $identity]],
            ['X-Hub-Delegation' => $this->assertions->delegate($user)]);
        if (! is_array($result['choices'] ?? null)) {
            throw new RuntimeException('Hub completion is invalid.');
        }
        foreach ($result['choices'] as $choice) {
            Privacy::assertSafe($choice['message'] ?? []);
        }
        return $result;
    }

    public function chatConfig(Authenticatable $user): array
    {
        $this->resolver->forUser($user);
        $result = $this->json('GET', '/chat/v1/config');
        if (($result['success'] ?? null) !== true || ! is_array($result['data'] ?? null)) {
            throw new RuntimeException('Hub chat configuration is invalid.');
        }
        return $result['data'];
    }

    public function turn(Authenticatable $user, array $input): array
    {
        if (array_diff(array_keys($input), ['message', 'history', 'skill', 'skill_arguments', 'locale'])
            || ! is_string($input['message'] ?? null) || mb_strlen($input['message'], 'UTF-8') > 8000
            || ! is_array($input['history'] ?? []) || count($input['history'] ?? []) > 20
            || ! is_array($input['skill_arguments'] ?? [])) {
            throw new InvalidArgumentException('Chat input is invalid.');
        }
        foreach ($input['history'] ?? [] as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['role', 'text'])
                || ! in_array($item['role'] ?? null, ['user', 'assistant'], true)
                || ! is_string($item['text'] ?? null) || mb_strlen($item['text'], 'UTF-8') > 8000) {
                throw new InvalidArgumentException('Chat history is invalid.');
            }
        }
        Privacy::assertSafe($input);
        $this->resolver->forUser($user);
        $result = $this->json('POST', '/chat/v1/turn', $input + ['stream' => false],
            ['X-Hub-Delegation' => $this->assertions->delegate($user)]);
        if (($result['success'] ?? null) !== true || ! is_array($result['data'] ?? null)
            || ! is_string($result['data']['text'] ?? null)) {
            throw new RuntimeException('Hub chat response is invalid.');
        }
        Privacy::assertSafe($result['data']['text']);
        return $result['data'];
    }

    private function json(string $method, string $path, array $input = [], array $headers = []): array
    {
        $response = Json::decode($this->transport->send($method, $path, $input, $headers,
            max(1, min(120, (int) config('mcp-hub.chat_timeout', 120)))), 64);
        if (! is_array($response) || isset($response['error'])) {
            throw new RuntimeException('Hub response is invalid.');
        }
        return $response;
    }
}
