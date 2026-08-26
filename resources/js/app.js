import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

window.agentChatEvidence = (index) => ({
    sourceOpen: false,
    sources: [],
    selected: null,
    copiedMessageId: null,

    scrollFeed() {
        this.$nextTick(() => {
            if (this.$refs.feed) this.$refs.feed.scrollTop = this.$refs.feed.scrollHeight;
        });
    },

    openSources(messageId, evidenceId = null) {
        this.sources = index[String(messageId)] || index[messageId] || [];
        this.selected = evidenceId
            ? this.sources.find((citation) => citation.evidence_id === evidenceId) || this.sources[0]
            : this.sources[0];
        this.sourceOpen = this.sources.length > 0;
    },

    handleEvidenceClick(event) {
        const anchor = event.target.closest('a[href^="#evidence-"]');
        if (!anchor) return;

        const message = anchor.closest('[data-message-id]');
        if (!message) return;

        event.preventDefault();
        this.openSources(message.dataset.messageId, anchor.hash.replace('#evidence-', ''));
    },

    closeSources() {
        this.sourceOpen = false;
        this.sources = [];
        this.selected = null;
    },

    async copyAnswer(text, messageId) {
        await navigator.clipboard.writeText(text);
        this.copiedMessageId = messageId;
        window.setTimeout(() => {
            if (this.copiedMessageId === messageId) this.copiedMessageId = null;
        }, 1600);
    },
});

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
