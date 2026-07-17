<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documentation — {{ config('brand.name') }}</title>
    <x-tailwind-cdn />
    <style>
        .doc-prose p{color:#475569;line-height:1.7}
        .doc-prose strong{color:#0f172a;font-weight:600}
        .doc-prose a{color:var(--color-brand-700,#1d4ed8);text-decoration:underline;text-underline-offset:2px}
        .doc-prose code:not(pre code){background:#eef2f7;color:#0f172a;border-radius:.3rem;padding:.05rem .35rem;font-size:.85em}
        .doc-prose ul{margin-top:.25rem}
        mark{background:#fde68a;color:inherit;border-radius:.2rem;padding:0 .1rem}
        /* Left navigation — carded, with an active accent rail + icon chips. */
        .docnav{position:sticky;top:6rem}
        .docnav-card{border:1px solid #e2e8f0;border-radius:1rem;background:#fff;box-shadow:0 1px 2px rgba(2,6,23,.05);padding:.5rem}
        .docnav-title{font-size:.66rem;letter-spacing:.09em;text-transform:uppercase;color:#94a3b8;font-weight:700;padding:.55rem .75rem .35rem}
        .nav-link{position:relative;display:flex;align-items:center;gap:.6rem;border-radius:.7rem;padding:.5rem .7rem .5rem .85rem;color:#475569;font-weight:500;transition:background .15s ease,color .15s ease}
        .nav-link .n-ico{display:inline-flex;height:1.65rem;width:1.65rem;flex:none;align-items:center;justify-content:center;border-radius:.5rem;background:#f1f5f9;color:#94a3b8;transition:all .15s ease}
        .nav-link:hover{background:#f8fafc;color:#0f172a}
        .nav-link:hover .n-ico{color:#475569;background:#e2e8f0}
        .nav-link.active{background:color-mix(in srgb, var(--color-brand-600,#2563eb) 9%, transparent);color:var(--color-brand-800,#1e40af);font-weight:600}
        .nav-link.active .n-ico{background:var(--color-brand-600,#2563eb);color:#fff;box-shadow:0 2px 8px color-mix(in srgb,var(--color-brand-600,#2563eb) 45%,transparent)}
        .nav-link.active::before{content:"";position:absolute;left:.28rem;top:.45rem;bottom:.45rem;width:3px;border-radius:9px;background:var(--color-brand-600,#2563eb)}
        .docnav-back{display:flex;align-items:center;gap:.4rem;padding:.5rem .85rem;margin-top:.5rem;font-size:.78rem;color:#94a3b8;transition:color .15s ease}
        .docnav-back:hover{color:var(--color-brand-700,#1d4ed8)}
        /* Documentation panels: header / body / footer. */
        .doc-panel{border:1px solid #e2e8f0;border-radius:1rem;background:#fff;box-shadow:0 1px 2px rgba(2,6,23,.05);overflow:hidden}
        .panel-head{display:flex;align-items:center;gap:.75rem;padding:.8rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0}
        .panel-head h2{font-size:1.05rem;font-weight:600;color:#0f172a}
        .panel-head .chip{display:inline-flex;height:2rem;width:2rem;align-items:center;justify-content:center;border-radius:.6rem;background:var(--color-brand-50,#eff6ff);color:var(--color-brand-700,#1d4ed8);box-shadow:inset 0 0 0 1px var(--color-brand-100,#dbeafe)}
        .panel-body{padding:1.25rem}
        .panel-body>*+*{margin-top:.75rem}
        .panel-foot{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.6rem 1.25rem;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:.75rem;color:#94a3b8}
        .panel-foot a{color:var(--color-brand-700,#1d4ed8)}
    </style>
</head>
@php
    $host = rtrim(config('app.url'), '/');
    $ver  = \App\Services\UpdateService::currentVersion();
    $sections = [
        ['overview',  'Overview',            'M4 6h16M4 12h16M4 18h7'],
        ['install',   'Install the Agent',   'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3'],
        ['host',      'Add a Host',          'M12 4v16m8-8H4'],
        ['metrics',   'What It Monitors',    'M4 19h16M7 19v-6M11 19V9M15 19v-4M19 19V6'],
        ['dashboard', 'The Dashboard',       'M3 12a9 9 0 0118 0M12 12l4-3M12 12a1.5 1.5 0 100 3 1.5 1.5 0 000-3z'],
        ['service',   'The Agent Service',   'M5 4h14a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1zm0 9h14a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4a1 1 0 011-1z'],
    ];
@endphp
<body class="min-h-full text-slate-800">

{{-- Top bar with search --}}
<header id="top" class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
    <div class="mx-auto max-w-6xl px-4 h-14 flex items-center gap-4">
        <a href="{{ url('/') }}" class="flex items-center gap-2 font-semibold text-slate-900 shrink-0">
            <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-brand-600 text-white text-sm">◈</span>
            {{ config('brand.name') }}
        </a>
        <span class="hidden sm:inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 tabular">v{{ $ver }} · Docs</span>
        <div class="ml-auto relative w-full max-w-xs">
            <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
            <input id="docsearch" type="search" autocomplete="off" placeholder="Search the docs…  ( / )"
                class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-8 text-sm text-slate-800 placeholder-slate-400 focus:border-brand-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100">
            <button id="docsearch-clear" type="button" class="absolute right-2 top-1/2 -translate-y-1/2 hidden h-5 w-5 items-center justify-center rounded text-slate-400 hover:text-slate-700">✕</button>
        </div>
    </div>
</header>

<div class="mx-auto max-w-6xl px-4 py-10 lg:grid lg:grid-cols-[220px_1fr] lg:gap-12">
    {{-- Sidebar --}}
    <aside class="hidden lg:block">
        <div class="docnav">
            <div class="docnav-card">
                <p class="docnav-title">On This Page</p>
                <nav id="docnav" class="flex flex-col gap-0.5 text-sm">
                    @foreach ($sections as [$id, $label, $d])
                        <a href="#{{ $id }}" data-nav="{{ $id }}" class="nav-link">
                            <span class="n-ico"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}"/></svg></span>
                            <span>{{ $label }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
            <a href="{{ url('/') }}" class="docnav-back">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7 7-7M3 12h18"/></svg>
                Back to {{ config('brand.name') }}
            </a>
        </div>
    </aside>

    <main class="min-w-0">
        {{-- Hero --}}
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-brand-600 to-brand-800 px-6 py-8 text-white shadow-sm">
            <div class="absolute -right-8 -top-10 h-40 w-40 rounded-full bg-white/10"></div>
            <div class="absolute -bottom-12 right-16 h-28 w-28 rounded-full bg-white/5"></div>
            <h1 class="relative text-3xl font-bold">{{ config('brand.name') }} Documentation</h1>
            <p class="relative mt-2 max-w-2xl text-white/85">Agent-based server monitoring: install a lightweight agent on each host and watch CPU, memory, disk, load and network stream into a live dashboard. The agent dials out to the panel over outbound HTTPS — there are no inbound ports to open on the machine you monitor.</p>
            <div class="relative mt-4 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-white/15 px-2.5 py-1">Linux x86_64</span>
                <span class="rounded-full bg-white/15 px-2.5 py-1">Ubuntu 22.04+ · Debian 12+</span>
                <span class="rounded-full bg-white/15 px-2.5 py-1">Outbound HTTPS only</span>
            </div>
        </div>

        @php
            $open = fn ($id, $title, $d) =>
                '<section data-doc id="sec-'.$id.'" class="doc-panel">'
                .'<div class="panel-head"><span class="chip"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="'.$d.'"/></svg></span>'
                .'<h2 id="'.$id.'" class="scroll-mt-24"><a href="#'.$id.'" class="hover:text-brand-700">'.$title.'</a></h2></div>'
                .'<div class="panel-body doc-prose">';
            $close = fn ($foot) =>
                '</div><div class="panel-foot"><span>'.$foot.'</span><a href="#top">Back to top ↑</a></div></section>';
            $code = fn ($c) =>
                '<div class="group relative">'
                .'<button type="button" class="copy-btn absolute right-2 top-2 rounded-md bg-white/10 px-2 py-1 text-xs text-slate-300 opacity-0 transition hover:bg-white/20 hover:text-white group-hover:opacity-100">Copy</button>'
                .'<pre class="overflow-x-auto rounded-xl bg-slate-900 px-4 py-3 text-sm text-slate-100"><code>'.$c.'</code></pre></div>';
        @endphp

        <div class="mt-8 space-y-6">
            {!! $open('overview', 'Overview', $sections[0][2]) !!}
                <p>The <strong>Panel</strong> — this app — stores metrics and renders dashboards; it never reaches out to your servers. On each host you want to watch, a small <strong>agent</strong> posts a metrics snapshot to the panel over outbound HTTPS every few seconds, so there are <strong>no inbound ports</strong> to open on the monitored machine. Every agent authenticates with a per-host key that is issued once, at enrollment.</p>
                <p>Because the flow is agent-to-panel, hosts behind NAT or a firewall work with no extra plumbing — if the box can reach the panel URL, it can be monitored.</p>
                <p>Supported OS: Linux x86_64 (Ubuntu 22.04+, Debian 12+).</p>
            {!! $close('The big picture') !!}

            {!! $open('install', 'Install the Agent', $sections[1][2]) !!}
                <p>Adding a host gives you a one-time enrollment token. On the server you want to monitor, run the installer as root with your panel URL and that token:</p>
                {!! $code("curl -fsSL {$host}/downloads/agent-install.sh | sudo bash -s -- \\\n  {$host} <enroll-token>") !!}
                <p>The installer downloads a small static agent to <code>/opt/monitor-agent</code>, trades the token for a permanent agent key, writes its config to <code>/etc/monitor-agent/agent.json</code>, and installs a <code>monitor-agent</code> systemd service that begins reporting immediately.</p>
            {!! $close('One line, per host') !!}

            {!! $open('host', 'Add a Host', $sections[2][2]) !!}
                <p>In the panel, open <strong>Hosts → Add Host</strong> and give it a name. You'll be shown a <strong>one-time enrollment token</strong> and the exact install command — copy that command and run it on the target server.</p>
                <p>Within a few seconds the host flips from <em>Pending</em> to <em>Online</em> and its metrics start streaming in. The enrollment token is single-use and shown only once; if you need a new one, regenerate a fresh token from the host's page and re-run the installer.</p>
            {!! $close('From the panel to the box') !!}

            {!! $open('metrics', 'What It Monitors', $sections[3][2]) !!}
                <p>Each snapshot the agent sends carries a full picture of the host's health:</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="font-semibold text-slate-900">CPU</p><p class="mt-1 text-sm text-slate-600">Overall utilization plus a per-core breakdown.</p></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="font-semibold text-slate-900">Memory</p><p class="mt-1 text-sm text-slate-600">Used vs. total RAM, plus swap usage.</p></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="font-semibold text-slate-900">Disk</p><p class="mt-1 text-sm text-slate-600">Per-filesystem usage across every mounted volume.</p></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="font-semibold text-slate-900">Load</p><p class="mt-1 text-sm text-slate-600">1, 5 and 15-minute load averages.</p></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="font-semibold text-slate-900">Uptime</p><p class="mt-1 text-sm text-slate-600">Time since the last boot.</p></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><p class="font-semibold text-slate-900">Network</p><p class="mt-1 text-sm text-slate-600">Receive and transmit throughput (rx / tx bytes per second).</p></div>
                </div>
            {!! $close('One snapshot, full health') !!}

            {!! $open('dashboard', 'The Dashboard', $sections[4][2]) !!}
                <p>Each host gets a live view with animated <strong>rings</strong> for CPU, memory and disk and <strong>sparklines</strong> for the recent time series. The page refreshes on its own every <strong>5 seconds</strong>, so you're always looking at near-real-time numbers without reloading.</p>
                <p><strong>Offline detection.</strong> Every snapshot updates the host's last check-in time. If a host hasn't reported within the offline window (default <strong>90 seconds</strong>, configurable), it's shown as <strong>Offline</strong> in the list and on its page — even if its last stored status said online — so a crashed agent or an unplugged server surfaces quickly.</p>
            {!! $close('Live & self-refreshing') !!}

            {!! $open('service', 'The Agent Service', $sections[5][2]) !!}
                <p>The agent runs as a systemd service, so it survives reboots and restarts on failure. Key paths:</p>
                <ul class="space-y-2 text-slate-600 list-disc list-inside">
                    <li><strong>Binary</strong> — <code>/opt/monitor-agent/monitor-agent</code></li>
                    <li><strong>Config</strong> — <code>/etc/monitor-agent/agent.json</code> (the panel URL and the per-host agent key)</li>
                    <li><strong>Service</strong> — <code>monitor-agent.service</code></li>
                </ul>
                <p>Check its status, follow its logs, or restart it:</p>
                {!! $code("systemctl status monitor-agent\njournalctl -u monitor-agent -f\nsystemctl restart monitor-agent") !!}
                <p>To retire a host, delete it in the panel and run <code>systemctl disable --now monitor-agent</code> on the server.</p>
            {!! $close('Runs as systemd') !!}

            <p id="noresults" class="hidden rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-slate-500">No sections match “<span id="noresults-q" class="font-medium text-slate-700"></span>”.</p>
        </div>

        <footer class="mt-12 border-t border-slate-200 pt-6 text-sm text-slate-400">{{ config('brand.name') }} · agent-based monitoring · v{{ $ver }}</footer>
    </main>
</div>

<script>
    // Copy buttons on code blocks.
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var code = btn.parentElement.querySelector('code');
            if (!code) return;
            navigator.clipboard.writeText(code.textContent).then(function () {
                var old = btn.textContent; btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = old; }, 1400);
            });
        });
    });

    // Client-side search: filter sections + sidebar, with match highlighting.
    (function () {
        var input = document.getElementById('docsearch');
        var clear = document.getElementById('docsearch-clear');
        var sections = Array.prototype.slice.call(document.querySelectorAll('section[data-doc]'));
        var none = document.getElementById('noresults');
        var noneQ = document.getElementById('noresults-q');

        function clearMarks(el) {
            el.querySelectorAll('mark').forEach(function (m) {
                var t = document.createTextNode(m.textContent);
                m.parentNode.replaceChild(t, m);
                m.parentNode.normalize && m.parentNode.normalize();
            });
        }
        function highlight(el, q) {
            var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
            var nodes = [], n;
            while ((n = walker.nextNode())) {
                if (n.parentElement.closest('pre')) continue; // don't touch code
                if (n.nodeValue.toLowerCase().indexOf(q) !== -1) nodes.push(n);
            }
            nodes.forEach(function (node) {
                var frag = document.createDocumentFragment(), text = node.nodeValue, low = text.toLowerCase(), i = 0, idx;
                while ((idx = low.indexOf(q, i)) !== -1) {
                    if (idx > i) frag.appendChild(document.createTextNode(text.slice(i, idx)));
                    var mk = document.createElement('mark'); mk.textContent = text.slice(idx, idx + q.length);
                    frag.appendChild(mk); i = idx + q.length;
                }
                if (i < text.length) frag.appendChild(document.createTextNode(text.slice(i)));
                node.parentNode.replaceChild(frag, node);
            });
        }
        function run() {
            var q = input.value.trim().toLowerCase();
            clear.classList.toggle('hidden', q === '');
            var shown = 0;
            sections.forEach(function (sec) {
                clearMarks(sec);
                var match = !q || sec.textContent.toLowerCase().indexOf(q) !== -1;
                sec.classList.toggle('hidden', !match);
                if (match) { shown++; if (q) highlight(sec, q); }
                var id = sec.id.replace('sec-', '');
                var nav = document.querySelector('[data-nav="' + id + '"]');
                if (nav) nav.classList.toggle('hidden', !match);
            });
            none.classList.toggle('hidden', shown !== 0);
            noneQ.textContent = input.value;
        }
        input.addEventListener('input', run);
        clear.addEventListener('click', function () { input.value = ''; run(); input.focus(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && document.activeElement !== input) { e.preventDefault(); input.focus(); }
            if (e.key === 'Escape' && document.activeElement === input) { input.value = ''; run(); input.blur(); }
        });
    })();

    // Scrollspy: highlight the sidebar link for the section in view.
    (function () {
        var links = {};
        document.querySelectorAll('[data-nav]').forEach(function (a) { links[a.getAttribute('data-nav')] = a; });
        var heads = Object.keys(links).map(function (id) { return document.getElementById(id); }).filter(Boolean);
        function spy() {
            var top = null, y = 120;
            heads.forEach(function (h) { if (h.getBoundingClientRect().top - y <= 0) top = h.id; });
            Object.keys(links).forEach(function (id) { links[id].classList.toggle('active', id === top); });
        }
        document.addEventListener('scroll', spy, { passive: true });
        spy();
    })();
</script>
</body>
</html>
