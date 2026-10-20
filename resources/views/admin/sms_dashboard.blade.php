@extends('layouts.admin')
@section('title', 'SMS delivery')
@section('content')
    <div class="workspace-heading">
        <div><h1>SMS delivery</h1><p class="page-description">Check whether passenger text messages were accepted by the sending service.</p></div>
        <a href="{{ route('admin.sms') }}" class="btn-secondary">Refresh status</a>
    </div>
    <section class="card" style="margin-bottom:24px" aria-labelledby="sms-connection-heading">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap"><h2 class="card-title" id="sms-connection-heading">{{ $smsProvider ?? 'TextLink' }} connection</h2><span class="badge {{ $smsConfigured ? 'success' : 'warning' }}">{{ $smsConfigured ? 'Configured' : 'Not configured' }}</span></div>
        <p class="page-description">{{ $smsConfigured ? 'SeaPass can submit messages to the SMS provider. The linked Android phone needs power, internet and a working SIM text allowance.' : 'SMS is not connected yet. Passenger messages cannot be sent until the provider and sending phone are configured.' }}</p>
        <p class="page-description">“Sent” means the provider accepted the message. It does not confirm that the passenger's phone received it.</p>
    </section>
    <section class="card" aria-labelledby="sms-log-heading">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px"><h2 class="card-title" id="sms-log-heading">Message activity</h2><span class="muted" style="font-size:12px">Latest first</span></div>
        <table><thead><tr><th>Recipient</th><th>Status</th><th>Submitted</th><th>Details</th></tr></thead><tbody>
        @forelse($smsMessages ?? [] as $message)
            @php $state = $message['status']; $tone = match($state) { 'sent' => 'success', 'queued' => 'info', 'failed' => 'danger', default => 'warning' }; @endphp
            <tr><td data-label="Recipient">{{ $message['to'] }}</td><td data-label="Status"><span class="badge {{ $tone }}">{{ ucfirst(str_replace('_', ' ', $state)) }}</span></td><td data-label="Submitted">{{ optional($message['created_at'])->timezone('Asia/Manila')->format('M j, g:i A') }}</td><td data-label="Details">{{ match($state) { 'sent' => 'Accepted by provider', 'queued' => 'Waiting to send', 'failed' => 'Provider could not send this message', 'not_configured' => 'Sending service is not configured', 'retry_requested' => 'A retry was requested', default => 'Delivery status could not be verified' } }}
                @if($smsConfigured && in_array($state, ['failed', 'not_configured', 'unknown'], true))
                <form class="sms-retry-form" action="{{ route('admin.sms.retry', $message['id']) }}" method="POST" style="margin-top:10px">@csrf
                    <label style="display:flex;gap:8px;font-size:12px;align-items:flex-start"><input type="checkbox" name="confirm_not_sent" value="1" required style="margin-top:3px"><span>I checked TextLink. This message was not sent.</span></label>
                    <button type="submit" class="btn-secondary" style="height:32px;font-size:12px">Retry SMS</button>
                    <p class="sms-retry-result muted" role="status" style="font-size:12px;margin-top:6px"></p>
                </form>
                @endif
            </td></tr>
        @empty
            <tr><td colspan="4"><div class="empty-state"><strong>No SMS activity yet</strong><span>Messages sent by SeaPass will appear here with their actual sending status.</span><a class="btn-secondary" href="{{ route('admin.travel-advisory') }}">Travel advisories</a></div></td></tr>
        @endforelse
        </tbody></table>
        @if(isset($smsMessages) && method_exists($smsMessages, 'hasPages') && $smsMessages->hasPages())
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:24px">
                @if($smsMessages->previousPageUrl())<a class="btn-secondary" href="{{ $smsMessages->previousPageUrl() }}">Newer messages</a>@else<span></span>@endif
                <span class="muted" style="font-size:12px">Page {{ $smsMessages->currentPage() }}</span>
                @if($smsMessages->nextPageUrl())<a class="btn-secondary" href="{{ $smsMessages->nextPageUrl() }}">Older messages</a>@endif
            </div>
        @endif
    </section>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.sms-retry-form').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const button = form.querySelector('button');
        const result = form.querySelector('.sms-retry-result');
        button.disabled = true;
        button.textContent = 'Requesting retry…';
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value }, body: JSON.stringify({ confirm_not_sent: true }) });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'The message could not be retried.');
            result.textContent = 'Retry recorded. Refresh to check its sending status.';
            button.textContent = 'Retry recorded';
        } catch (error) {
            result.textContent = error.message;
            button.disabled = false;
            button.textContent = 'Retry SMS';
        }
    });
});
</script>
@endpush
