<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mcp-hub::messages.settings_title') }}</title>
    <link rel="stylesheet" href="{{ route('mcp-hub.asset', ['asset' => 'chat.css']) }}">
    <script src="{{ route('mcp-hub.asset', ['asset' => 'settings.js']) }}" defer></script>
</head>
<body class="mcp-page">
<main class="mcp-settings" data-mcp-settings data-revision="{{ $settings['revision'] }}"
    data-save="{{ route('mcp-hub.settings.save') }}" data-connect="{{ route('mcp-hub.settings.connect') }}"
    data-resume="{{ route('mcp-hub.settings.resume') }}" data-sync="{{ route('mcp-hub.settings.sync') }}"
    data-check="{{ route('mcp-hub.settings.check') }}" data-error="{{ __('mcp-hub::messages.settings_error') }}"
    data-saved="{{ __('mcp-hub::messages.saved') }}" data-wait="{{ __('mcp-hub::messages.waiting') }}"
    data-check-ok="{{ __('mcp-hub::messages.check_ok') }}" data-check-failed="{{ __('mcp-hub::messages.check_failed') }}">
    <h1>{{ __('mcp-hub::messages.settings_title') }}</h1>
    <p>{{ __('mcp-hub::messages.settings_hint') }}</p>
    <input type="hidden" data-csrf value="{{ csrf_token() }}">
    <p role="status" data-settings-status></p>
    <section class="mcp-settings-card">
        <h2>{{ __('mcp-hub::messages.connection') }}</h2>
        <dl>
            <dt>{{ __('mcp-hub::messages.configuration') }}</dt>
            <dd>{{ $settings['configured'] ? __('mcp-hub::messages.configured') : __('mcp-hub::messages.not_configured') }}</dd>
            <dt>Hub</dt><dd>{{ $settings['hub'] ?: '—' }}</dd>
            <dt>{{ __('mcp-hub::messages.source') }}</dt><dd>{{ $settings['source'] ?: '—' }}</dd>
        </dl>
        <div class="mcp-actions">
            <button type="button" data-action="check">{{ __('mcp-hub::messages.check') }}</button>
            @if(($settings['pairing']['status'] ?? '') !== 'not_paired')
                <button type="button" data-action="resume">{{ __('mcp-hub::messages.resume') }}</button>
            @endif
            @if(($settings['pairing']['status'] ?? '') === 'active')
                <button type="button" data-action="sync">{{ __('mcp-hub::messages.sync') }}</button>
            @endif
        </div>
    </section>
    @if(!$settings['configured'])
    <form class="mcp-settings-card" data-connect-form>
        <h2>{{ __('mcp-hub::messages.connect') }}</h2>
        <label>Hub URL<input name="hub" type="url" required maxlength="2048" autocomplete="off" placeholder="https://hub.example.com/mcp"></label>
        <label>{{ __('mcp-hub::messages.pairing_code') }}<input name="code" type="password" required maxlength="128" autocomplete="off"></label>
        <button type="submit">{{ __('mcp-hub::messages.connect') }}</button>
    </form>
    @endif
    <form class="mcp-settings-card" data-settings-form>
        <h2>{{ __('mcp-hub::messages.permissions') }}</h2>
        <label class="mcp-checkbox"><input type="checkbox" data-enabled {{ $settings['enabled'] ? 'checked' : '' }}>
            {{ __('mcp-hub::messages.integration_enabled') }}</label>
        @if(!$settings['permissions_editable'])
            <p>{{ __('mcp-hub::messages.app_managed') }}</p>
        @else
            @forelse($settings['resources'] as $key => $resource)
            <fieldset data-resource="{{ $key }}">
                <legend>{{ $resource['label'] }}</legend>
                <label class="mcp-checkbox"><input type="checkbox" data-resource-enabled {{ $resource['grant']['enabled'] ? 'checked' : '' }}>
                    {{ __('mcp-hub::messages.resource_enabled') }}</label>
                <p>{{ __('mcp-hub::messages.read_fields') }}</p>
                <div class="mcp-field-options">
                @foreach($resource['fields'] as $field)
                    <label class="mcp-checkbox"><input type="checkbox" data-field="{{ $field }}" {{ in_array($field, $resource['grant']['fields'], true) ? 'checked' : '' }}>{{ $field }}</label>
                @endforeach
                </div>
                @if($resource['operations'])
                <p>{{ __('mcp-hub::messages.write_operations') }}</p>
                <div class="mcp-field-options">
                @foreach($resource['operations'] as $operation)
                    <label class="mcp-checkbox"><input type="checkbox" data-operation="{{ $operation }}" {{ in_array($operation, $resource['grant']['operations'], true) ? 'checked' : '' }}>{{ $operation }}</label>
                @endforeach
                </div>
                @endif
            </fieldset>
            @empty
                <p>{{ __('mcp-hub::messages.no_resources') }}</p>
            @endforelse
        @endif
        <button type="submit">{{ __('mcp-hub::messages.save') }}</button>
    </form>
</main>
</body>
</html>
