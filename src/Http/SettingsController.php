<?php

namespace McpHub\Laravel\Http;

use Illuminate\Http\Request;
use InvalidArgumentException;
use McpHub\Laravel\Commands\CommandException;
use McpHub\Laravel\Pairing;
use McpHub\Laravel\Security\WebAccess;
use McpHub\Laravel\Settings;
use McpHub\Laravel\Support\Json;
use Throwable;

final class SettingsController
{
    public function show(WebAccess $access, Settings $settings)
    {
        try {
            $access->user('manage_gate');
            return response()->view('mcp-hub::settings', ['settings' => $settings->snapshot()], 200, $this->headers());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function save(Request $request, WebAccess $access, Settings $settings)
    {
        try {
            $access->user('manage_gate');
            return $this->result($settings->save($this->input($request)));
        } catch (CommandException $error) {
            return $this->error($error->status());
        } catch (InvalidArgumentException $error) {
            return $this->error(422);
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function connect(Request $request, WebAccess $access, Pairing $pairing)
    {
        try {
            $access->user('manage_gate');
            $input = $this->input($request);
            if (array_diff(array_keys($input), ['hub', 'code']) || ! is_string($input['hub'] ?? null)
                || ! is_string($input['code'] ?? null) || strlen($input['hub']) > 2048 || strlen($input['code']) > 128) {
                return $this->error(422);
            }
            return $this->result($pairing->connect($input['hub'], $input['code']));
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function resume(WebAccess $access, Pairing $pairing)
    {
        try {
            $access->user('manage_gate');
            return $this->result($pairing->resume());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function sync(WebAccess $access, Pairing $pairing)
    {
        try {
            $access->user('manage_gate');
            return $this->result($pairing->sync());
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    public function check(WebAccess $access, \McpHub\Laravel\Doctor $doctor)
    {
        try {
            $access->user('manage_gate');
            return $this->result($doctor->inspect(true));
        } catch (Throwable $error) {
            return $this->error(403);
        }
    }

    private function input(Request $request): array
    {
        if ($request->query() !== [] || substr(ltrim($request->getContent()), 0, 1) !== '{'
            || strtolower(explode(';', (string) $request->header('Content-Type'))[0]) !== 'application/json') {
            throw new InvalidArgumentException('Settings request is invalid.');
        }
        $input = Json::decode($request->getContent(), 16);
        if (! is_array($input)) {
            throw new InvalidArgumentException('Settings request is invalid.');
        }
        return $input;
    }

    private function result(array $data)
    {
        return response()->json(['success' => true, 'data' => $data], 200, $this->headers());
    }

    private function error(int $status)
    {
        return response()->json(['success' => false, 'error' => [
            'code' => $status === 409 ? 'CONFLICT' : 'SETTINGS_REJECTED',
            'message' => 'Settings could not be updated. Refresh the page and check application permissions.',
        ]], $status, $this->headers());
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"];
    }
}
