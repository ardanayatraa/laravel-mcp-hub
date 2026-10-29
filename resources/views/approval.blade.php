<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('mcp-hub::messages.approval_title') }}</title>
    <link rel="stylesheet" href="{{ route('mcp-hub.asset', ['asset' => 'chat.css']) }}">
</head>
<body class="mcp-hub-approval"><main>
    <h1>{{ __('mcp-hub::messages.approval_title') }}</h1>
    <p>{{ __('mcp-hub::messages.operation') }}: <strong>{{ $receipt['operation'] }}</strong> · {{ __('mcp-hub::messages.resource') }}: <strong>{{ $resource }}</strong></p>
    <p role="status">{{ __('mcp-hub::messages.proposal_status') }}: {{ __('mcp-hub::messages.status_'.$receipt['status']) }} · {{ __('mcp-hub::messages.expires') }}: {{ $receipt['expires_at'] }}</p>
    @if(!empty($before))
    <h2>{{ __('mcp-hub::messages.current_record') }}</h2>
    <dl>
    @foreach($before as $field => $value)
        <dt>{{ $field }}</dt><dd>{{ $value === null ? '(empty)' : (is_bool($value) ? ($value ? 'true' : 'false') : $value) }}</dd>
    @endforeach
    </dl>
    @endif
    @if(count(array_diff_key($input, ['ref' => true])) > 0)
    <h2>{{ __('mcp-hub::messages.proposed_values') }}</h2>
    <dl>
    @foreach($input as $field => $value)
        @if($field !== 'ref')
            <dt>{{ $field }}</dt>
            <dd>{{ $value === null ? '(empty)' : (is_bool($value) ? ($value ? 'true' : 'false') : $value) }}</dd>
        @endif
    @endforeach
    </dl>
    @endif
    @if($receipt['requires_confirmation'])
        <p>{{ __('mcp-hub::messages.approval_advice') }}</p>
        <form method="post" action="{{ route('mcp-hub.approval.confirm', ['proposal' => $receipt['proposal_ref']]) }}">
            @csrf
            <button type="submit" name="decision" value="approve">{{ __('mcp-hub::messages.approve_change') }}</button>
            <button type="submit" name="decision" value="cancel">{{ __('mcp-hub::messages.cancel_proposal') }}</button>
        </form>
    @endif
    <p><a href="{{ route('mcp-hub.chat.show') }}">{{ __('mcp-hub::messages.back_to_chat') }}</a></p>
</main></body>
</html>
