@auth
<link rel="stylesheet" href="{{ asset('css/assistant.css') }}?v={{ filemtime(public_path('css/assistant.css')) }}">
<button type="button" class="sp-assistant-launch" id="sp-assistant-launch" aria-controls="sp-assistant" aria-expanded="false" aria-label="Open SeaPass assistant"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H8l-5 3v-8a7.5 7.5 0 1 1 17-2.5Z"/><path d="M7 10h10M7 14h6"/></svg><span>Ask SeaPass</span></button>
<section class="sp-assistant" id="sp-assistant" role="dialog" aria-modal="false" aria-labelledby="sp-assistant-title" hidden data-endpoint="/assistant" data-user="{{ get_class(auth()->user()).':'.auth()->id() }}" data-csrf="{{ csrf_token() }}">
  <header><div><strong id="sp-assistant-title">SeaPass assistant</strong><small id="sp-assistant-state">Trips, tickets and a little guidance</small></div><button type="button" data-sp="close" aria-label="Close assistant">×</button></header>
  <nav aria-label="Conversation controls"><button type="button" data-sp="new">New chat</button><button type="button" data-sp="history">History</button><button type="button" data-sp="tour">Show me this page</button></nav>
  <div id="sp-assistant-history" hidden></div>
  <div id="sp-assistant-messages" class="sp-assistant-messages" role="log" aria-live="polite" aria-relevant="additions"></div>
  <div id="sp-assistant-notice" role="status"></div>
  <div id="sp-assistant-files"></div>
  <form id="sp-assistant-form"><label for="sp-assistant-input" class="sp-sr-only">Message SeaPass assistant</label><textarea id="sp-assistant-input" rows="2" placeholder="What would you like to do?" maxlength="12000" required></textarea><div class="sp-assistant-compose"><label class="sp-attachment" title="Attach image, PDF or text"><span aria-hidden="true">＋</span><span class="sp-sr-only">Attach a file</span><input id="sp-assistant-file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain" multiple></label><button type="button" data-sp="voice" aria-pressed="false">Voice</button><button type="submit" id="sp-assistant-send">Send</button></div></form>
  <footer>AI can make mistakes. Confirm important travel and payment details.</footer>
</section>
<div id="sp-guide" hidden role="dialog" aria-labelledby="sp-guide-title"><strong id="sp-guide-title"></strong><p></p><button type="button" data-guide="next">Next</button><button type="button" data-guide="close">Done</button></div>
<script type="module" src="{{ asset('js/assistant.js') }}?v={{ filemtime(public_path('js/assistant.js')) }}"></script>
@endauth
