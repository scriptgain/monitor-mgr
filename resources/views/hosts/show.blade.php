<x-layouts.app :title="$host->name">
    @php $installCmd = "curl -fsSL {$installUrl} | sudo bash -s -- {$masterUrl} ".($token ?: 'YOUR_ENROLLMENT_TOKEN'); @endphp

    <div x-data="hostDashboard(@js(route('hosts.metrics', $host)))" x-init="init()">
        <x-page-header :title="$host->name" icon="server"
            :back="['href' => route('hosts.index'), 'label' => 'Back To Hosts']">
            <x-slot:actions>
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                      :class="statusChip()">
                    <span class="w-1.5 h-1.5 rounded-full" :class="statusDot()"></span>
                    <span x-text="statusLabel()">{{ ucfirst($host->effective_status) }}</span>
                </span>
                <form method="POST" action="{{ route('hosts.destroy', $host) }}"
                      @submit.prevent="$dispatch('open-modal', 'delete-host')">
                    @csrf @method('DELETE')
                    <x-button type="button" variant="danger" size="sm" icon="trash"
                        x-on:click="$dispatch('open-modal', 'delete-host')">Remove</x-button>
                </form>
            </x-slot:actions>
        </x-page-header>

        {{-- Enrollment instructions: shown until the agent first checks in. --}}
        <div x-show="!enrolled" x-cloak class="mb-6">
            <x-card title="Install The Monitoring Agent" subtitle="Run This On {{ $host->name }} As Root. The Agent Enrolls, Then Reports Every 30 Seconds.">
                @if ($token)
                    <div class="mb-4">
                        <p class="text-sm font-medium text-slate-700 mb-1.5">One-Time Enrollment Token</p>
                        <div class="flex items-center gap-2">
                            <code class="flex-1 rounded-lg bg-slate-900 text-emerald-300 text-sm px-3 py-2 font-mono break-all">{{ $token }}</code>
                            <x-button variant="secondary" size="sm" icon="key" x-on:click="copy('{{ $token }}')">Copy</x-button>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-500">This token is shown once. It is consumed the first time the agent enrolls.</p>
                    </div>
                @else
                    <div class="mb-4 rounded-lg bg-amber-50 ring-1 ring-amber-200 px-3 py-2.5 text-sm text-amber-800">
                        The enrollment token is only shown once. Generate a fresh one to install the agent.
                        <form method="POST" action="{{ route('hosts.token', $host) }}" class="inline">
                            @csrf
                            <button type="submit" class="ml-1 font-medium underline hover:no-underline">Generate New Token</button>
                        </form>
                    </div>
                @endif

                <p class="text-sm font-medium text-slate-700 mb-1.5">Install Command</p>
                <div class="flex items-start gap-2">
                    <code class="flex-1 rounded-lg bg-slate-900 text-slate-100 text-xs px-3 py-2.5 font-mono break-all leading-relaxed">{{ $installCmd }}</code>
                    <x-button variant="secondary" size="sm" icon="download" x-on:click="copy(@js($installCmd))">Copy</x-button>
                </div>
                <p class="mt-3 text-xs text-slate-500">The installer drops a static binary at <code class="text-slate-600">/opt/monitor-agent</code>, enrolls this host, and installs a <code class="text-slate-600">monitor-agent</code> systemd service (Restart=always). Waiting for the first check-in&hellip;</p>
            </x-card>
        </div>

        {{-- Live dashboard: rendered once metrics exist. --}}
        <template x-if="latest">
            <div class="space-y-6">
                {{-- CPU / Memory / Disk rings --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <template x-for="g in gauges()" :key="g.key">
                        <div class="bg-white rounded-xl ring-1 ring-slate-200 shadow-sm p-5">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-slate-500" x-text="g.label"></p>
                                <span class="text-xs tabular text-slate-400" x-text="g.sub"></span>
                            </div>
                            <div class="mt-3 flex items-center gap-4">
                                <svg width="76" height="76" viewBox="0 0 76 76" class="shrink-0 -rotate-90">
                                    <circle cx="38" cy="38" r="32" fill="none" stroke="#f1f5f9" stroke-width="8" />
                                    <circle cx="38" cy="38" r="32" fill="none" stroke-width="8" stroke-linecap="round"
                                        :stroke="g.color" :stroke-dasharray="ringCirc"
                                        :stroke-dashoffset="ringOffset(g.pct)" style="transition:stroke-dashoffset .5s ease" />
                                </svg>
                                <div>
                                    <p class="text-3xl font-semibold text-slate-900 tabular"><span x-text="g.pct.toFixed(g.key==='cpu'?1:0)"></span><span class="text-lg text-slate-400">%</span></p>
                                    <p class="text-xs text-slate-500 mt-0.5" x-text="g.detail"></p>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Load / Uptime / Network --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-white rounded-xl ring-1 ring-slate-200 shadow-sm p-5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100"><x-icon name="pulse" class="w-5 h-5" /></span>
                            <p class="text-sm font-medium text-slate-500">Load Average</p>
                        </div>
                        <p class="mt-3 text-2xl font-semibold text-slate-900 tabular">
                            <span x-text="latest.load1.toFixed(2)"></span>
                            <span class="text-sm font-normal text-slate-400"> / <span x-text="latest.load5.toFixed(2)"></span> / <span x-text="latest.load15.toFixed(2)"></span></span>
                        </p>
                        <p class="text-xs text-slate-400 mt-1">1m / 5m / 15m &middot; <span x-text="cores"></span> cores</p>
                    </div>
                    <div class="bg-white rounded-xl ring-1 ring-slate-200 shadow-sm p-5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100"><x-icon name="clock" class="w-5 h-5" /></span>
                            <p class="text-sm font-medium text-slate-500">Uptime</p>
                        </div>
                        <p class="mt-3 text-2xl font-semibold text-slate-900 tabular" x-text="fmtUptime(latest.uptime)"></p>
                        <p class="text-xs text-slate-400 mt-1">Booted <span x-text="meta.boot_time || '—'"></span></p>
                    </div>
                    <div class="bg-white rounded-xl ring-1 ring-slate-200 shadow-sm p-5">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100"><x-icon name="cloud" class="w-5 h-5" /></span>
                            <p class="text-sm font-medium text-slate-500">Network</p>
                        </div>
                        <p class="mt-3 text-lg font-semibold text-slate-900 tabular">
                            <span class="text-emerald-600" x-text="'↓ ' + fmtRate(latest.net_rx)"></span>
                            <span class="text-slate-300 mx-1">·</span>
                            <span class="text-brand-600" x-text="'↑ ' + fmtRate(latest.net_tx)"></span>
                        </p>
                        <p class="text-xs text-slate-400 mt-1">Receive / Transmit</p>
                    </div>
                </div>

                {{-- Sparklines --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <template x-for="s in sparks()" :key="s.key">
                        <div class="bg-white rounded-xl ring-1 ring-slate-200 shadow-sm p-5">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-sm font-medium text-slate-500" x-text="s.label"></p>
                                <p class="text-sm font-semibold tabular text-slate-900"><span x-text="s.last.toFixed(1)"></span>%</p>
                            </div>
                            <svg viewBox="0 0 300 60" preserveAspectRatio="none" class="w-full h-16">
                                <path :d="areaPath(s.values)" :fill="s.fill" opacity="0.12" />
                                <path :d="linePath(s.values)" fill="none" :stroke="s.stroke" stroke-width="2" vector-effect="non-scaling-stroke" />
                            </svg>
                            <p class="mt-1 text-xs text-slate-400">Last <span x-text="s.values.length"></span> samples</p>
                        </div>
                    </template>
                </div>

                {{-- Filesystems --}}
                <div class="bg-white rounded-xl ring-1 ring-slate-200 shadow-sm overflow-hidden">
                    <div class="px-5 sm:px-6 py-4 border-b border-slate-100"><h3 class="text-[15px] font-semibold text-slate-900">Filesystems</h3></div>
                    <div class="p-5 sm:p-6 space-y-3">
                        <template x-for="d in (latest.detail?.disks || [])" :key="d.mount">
                            <div>
                                <div class="flex items-center justify-between text-sm mb-1">
                                    <span class="font-medium text-slate-700"><span x-text="d.mount"></span> <span class="text-slate-400 text-xs" x-text="'(' + d.device + ' · ' + d.fstype + ')'"></span></span>
                                    <span class="tabular text-slate-500"><span x-text="fmtBytes(d.used)"></span> / <span x-text="fmtBytes(d.total)"></span></span>
                                </div>
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full rounded-full" :class="pctColor(diskPct(d))" :style="'width:' + diskPct(d) + '%'"></div>
                                </div>
                            </div>
                        </template>
                        <p x-show="!(latest.detail?.disks || []).length" class="text-sm text-slate-400">No filesystems reported.</p>
                    </div>
                </div>

                {{-- Per-core --}}
                <div class="bg-white rounded-xl ring-1 ring-slate-200 shadow-sm overflow-hidden" x-show="(latest.detail?.cores || []).length">
                    <div class="px-5 sm:px-6 py-4 border-b border-slate-100"><h3 class="text-[15px] font-semibold text-slate-900">CPU Cores</h3></div>
                    <div class="p-5 sm:p-6 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                        <template x-for="c in (latest.detail?.cores || [])" :key="c.core">
                            <div>
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="text-slate-500">Core <span x-text="c.core"></span></span>
                                    <span class="tabular text-slate-600" x-text="c.pct.toFixed(0) + '%'"></span>
                                </div>
                                <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full rounded-full" :class="pctColor(c.pct)" :style="'width:' + c.pct + '%'"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Meta --}}
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                    <span>OS: <span class="text-slate-700" x-text="(meta.os||'—') + ' / ' + (meta.arch||'—')"></span></span>
                    <span>Cores: <span class="text-slate-700" x-text="cores"></span></span>
                    <span>Agent: <span class="text-slate-700" x-text="meta.agent_version || '—'"></span></span>
                    <span>Last Seen: <span class="text-slate-700" x-text="meta.last_seen || '—'"></span></span>
                </div>
            </div>
        </template>
    </div>

    {{-- Delete confirm (no native confirm/alert) --}}
    <x-modal name="delete-host" title="Remove Host" icon="trash" tone="danger">
        This removes <strong>{{ $host->name }}</strong> and all of its recorded metrics. The agent on the host will keep running but can no longer report. This cannot be undone.
        <x-slot:footer>
            <x-button variant="secondary" x-on:click="$dispatch('close-modal', 'delete-host')">Cancel</x-button>
            <form method="POST" action="{{ route('hosts.destroy', $host) }}">
                @csrf @method('DELETE')
                <x-button type="submit" variant="danger" icon="trash">Remove Host</x-button>
            </form>
        </x-slot:footer>
    </x-modal>

    <script>
        function hostDashboard(url) {
            return {
                url,
                latest: null,
                history: { cpu: [], mem: [], disk: [] },
                meta: {},
                status: @js($host->effective_status),
                cores: @js($host->cpu_cores ?? '—'),
                get enrolled() { return this.status !== 'pending'; },
                ringCirc: 2 * Math.PI * 32,
                init() {
                    this.fetchNow();
                    setInterval(() => this.fetchNow(), 5000);
                },
                async fetchNow() {
                    try {
                        const r = await fetch(this.url, { headers: { 'Accept': 'application/json' } });
                        if (!r.ok) return;
                        const d = await r.json();
                        this.status = d.status;
                        this.meta = d;
                        if (d.cpu_cores) this.cores = d.cpu_cores;
                        this.history = d.history || this.history;
                        this.latest = d.latest;
                    } catch (e) { /* keep last good frame */ }
                },
                ringOffset(pct) { return this.ringCirc * (1 - Math.max(0, Math.min(100, pct)) / 100); },
                diskPct(d) { return d.total > 0 ? Math.round(d.used / d.total * 100) : 0; },
                pctColor(p) { return p >= 90 ? 'bg-rose-500' : (p >= 70 ? 'bg-amber-500' : 'bg-emerald-500'); },
                hex(p) { return p >= 90 ? '#f43f5e' : (p >= 70 ? '#f59e0b' : '#10b981'); },
                statusLabel() { return this.status.charAt(0).toUpperCase() + this.status.slice(1); },
                statusChip() { return { online: 'bg-emerald-50 text-emerald-700 ring-emerald-200', offline: 'bg-rose-50 text-rose-700 ring-rose-200', pending: 'bg-amber-50 text-amber-700 ring-amber-200' }[this.status] || 'bg-slate-100 text-slate-700 ring-slate-200'; },
                statusDot() { return { online: 'bg-emerald-500', offline: 'bg-rose-500', pending: 'bg-amber-500' }[this.status] || 'bg-slate-400'; },
                gauges() {
                    const l = this.latest; if (!l) return [];
                    return [
                        { key: 'cpu', label: 'CPU', pct: l.cpu_pct, color: this.hex(l.cpu_pct), sub: this.cores + ' cores', detail: 'Utilization' },
                        { key: 'mem', label: 'Memory', pct: l.mem_pct, color: this.hex(l.mem_pct), sub: this.fmtBytes(l.mem_total), detail: this.fmtBytes(l.mem_used) + ' used' },
                        { key: 'disk', label: 'Disk', pct: l.disk_pct, color: this.hex(l.disk_pct), sub: this.fmtBytes(l.disk_total), detail: this.fmtBytes(l.disk_used) + ' used' },
                    ];
                },
                sparks() {
                    return [
                        { key: 'cpu', label: 'CPU History', values: this.history.cpu || [], last: (this.latest?.cpu_pct) || 0, stroke: '#0ea5e9', fill: '#0ea5e9' },
                        { key: 'mem', label: 'Memory History', values: this.history.mem || [], last: (this.latest?.mem_pct) || 0, stroke: '#8b5cf6', fill: '#8b5cf6' },
                    ];
                },
                linePath(values) {
                    if (!values || values.length < 2) return '';
                    const w = 300, h = 60, n = values.length;
                    return values.map((v, i) => {
                        const x = (i / (n - 1)) * w;
                        const y = h - (Math.max(0, Math.min(100, v)) / 100) * h;
                        return (i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1);
                    }).join(' ');
                },
                areaPath(values) {
                    const line = this.linePath(values);
                    if (!line) return '';
                    return line + ' L300 60 L0 60 Z';
                },
                fmtBytes(b) {
                    b = Number(b) || 0;
                    if (b < 1024) return b + ' B';
                    const u = ['KB', 'MB', 'GB', 'TB', 'PB']; let i = -1;
                    do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
                    return b.toFixed(1) + ' ' + u[i];
                },
                fmtRate(b) { return this.fmtBytes(b) + '/s'; },
                fmtUptime(s) {
                    s = Number(s) || 0;
                    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
                    if (d > 0) return d + 'd ' + h + 'h';
                    if (h > 0) return h + 'h ' + m + 'm';
                    return m + 'm';
                },
                copy(text) {
                    navigator.clipboard && navigator.clipboard.writeText(text);
                },
            };
        }
    </script>
</x-layouts.app>
