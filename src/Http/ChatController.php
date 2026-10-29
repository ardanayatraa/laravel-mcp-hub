<?php

namespace McpHub\Laravel\Http;

use Illuminate\Http\Request;
use InvalidArgumentException;
use McpHub\Laravel\Contracts\ActorResolver;
use McpHub\Laravel\Gateway;
use McpHub\Laravel\HubException;
use McpHub\Laravel\Security\WebAccess;
use McpHub\Laravel\Support\Json;
use Throwable;

final class ChatController
{
    public function show(WebAccess $access, ActorResolver $resolver)
    {
        try {
            $resolver->forUser($access->user('chat_gate'));
            return response()->view('mcp-hub::chat', [], 200, $this->headers());
        } catch (Throwable $error) {
            return $this->error('PERMISSION_DENIED', 403);
        }
    }

    public function models(WebAccess $access, Gateway $gateway)
    {
        try {
            return $this->result($gateway->models($access->user('chat_gate')));
        } catch (HubException $error) {
            return $this->error($error->errorCode(), $error->status());
        } catch (Throwable $error) {
            return $this->error('PERMISSION_DENIED', 403);
        }
    }

    public function turn(Request $request, WebAccess $access, Gateway $gateway)
    {
        try {
            $user = $access->user('chat_gate');
            if ($request->query() !== [] || substr(ltrim($request->getContent()), 0, 1) !== '{'
                || strtolower(explode(';', (string) $request->header('Content-Type'))[0]) !== 'application/json') {
                return $this->error('VALIDATION_FAILED', 422);
            }
            $input = Json::decode($request->getContent(), 16);
            return $this->result($gateway->turn($user, $input));
        } catch (HubException $error) {
            return $this->error($error->errorCode(), $error->status());
        } catch (InvalidArgumentException $error) {
            return $this->error('VALIDATION_FAILED', 422);
        } catch (Throwable $error) {
            return $this->error('REQUEST_REJECTED', 403);
        }
    }

    private function result(array $result)
    {
        return response()->json(['success' => true, 'data' => $result], 200, $this->headers());
    }

    private function error(string $code, int $status)
    {
        return response()->json(['success' => false, 'error' => [
            'code' => $code, 'message' => 'The assistant could not complete this request.',
        ]], $status, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"];
    }
}
