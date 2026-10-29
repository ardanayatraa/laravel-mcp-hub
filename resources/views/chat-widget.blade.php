@php($widgetId = 'mcp-message-'.bin2hex(random_bytes(6)))
<link rel="stylesheet" href="{{ route('mcp-hub.asset', ['asset' => 'chat.css']) }}">
<script src="{{ route('mcp-hub.asset', ['asset' => 'chat.js']) }}" defer></script>
<section class="mcp-chat" data-mcp-chat data-endpoint="{{ route('mcp-hub.chat.turn') }}"
    data-approval="{{ route('mcp-hub.approval.show', ['proposal' => '__proposal__']) }}"
    data-export="{{ route('mcp-hub.exports.download', ['reference' => '__export__']) }}"
    data-download="{{ __('mcp-hub::messages.download_report') }}"
    data-error="{{ __('mcp-hub::messages.chat_error') }}" data-review="{{ __('mcp-hub::messages.review') }}"
    data-wait="{{ __('mcp-hub::messages.waiting') }}" data-locale="{{ app()->getLocale() }}">
    <header><h1>{{ __('mcp-hub::messages.chat_title') }}</h1>
        <p>{{ __('mcp-hub::messages.chat_hint') }}</p></header>
    <div class="mcp-messages" aria-live="polite" aria-relevant="additions" data-messages></div>
    <p class="mcp-status" role="status" data-status></p>
    <form data-chat-form>
        @csrf
        <label for="{{ $widgetId }}">{{ __('mcp-hub::messages.message') }}</label>
        <textarea id="{{ $widgetId }}" name="message" required maxlength="8000" rows="3"></textarea>
        <button type="submit">{{ __('mcp-hub::messages.send') }}</button>
    </form>
</section>
