import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

window.agentRunRealtime = (runId) => ({
    channel: null,
    destroyed: false,
    dirty: false,
    inFlight: false,
    lastEvent: {},
    timer: null,

    init() {
        const connect = () => {
            if (this.destroyed) return;
            if (!window.Echo) {
                this.timer = window.setTimeout(connect, 100);
                return;
            }

            this.channel = window.Echo.private(`agent-runs.${runId}`);
            this.channel.listen('AgentRunUpdated', (event) => this.schedule(event));
        };

        connect();
    },

    schedule(event) {
        this.lastEvent = event;
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.refresh(), 250);
    },

    refresh() {
        if (this.inFlight) {
            this.dirty = true;
            return;
        }

        this.inFlight = true;
        this.$wire.refreshRun(this.lastEvent).finally(() => {
            this.inFlight = false;
            if (this.dirty) {
                this.dirty = false;
                this.schedule(this.lastEvent);
            }
        });
    },

    destroy() {
        this.destroyed = true;
        window.clearTimeout(this.timer);
        if (window.Echo) window.Echo.leave(`agent-runs.${runId}`);
    },
});

if (meta('reverb-enabled') === '1' && meta('reverb-app-key')) {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: meta('reverb-app-key'),
        wsHost: window.location.hostname,
        wsPort: Number(meta('reverb-public-port') || 8080),
        wssPort: Number(meta('reverb-public-port') || 8080),
        forceTLS: window.location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
    });
}
