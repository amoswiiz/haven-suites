<nav class="actions" aria-label="Communications">
    @if(auth()->user()->can('messaging.manage'))
        <a href="{{ route('communications.guests',app('currentTenant')->slug) }}">Guest messages</a>
    @endif
    <a href="{{ route('communications.templates',app('currentTenant')->slug) }}">Message templates</a>
    <a href="{{ route('communications.sms',app('currentTenant')->slug) }}">Custom SMS &amp; history</a>
</nav>
