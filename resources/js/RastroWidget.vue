<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

/*
 * The support chat button + panel for the site. Drop it once in a layout:
 *
 *   <RastroWidget />                       (endpoint defaults to /support/widget)
 *   <RastroWidget :endpoint="url" />       (when the prefix was changed)
 *
 * Compiled with Vue inside (dist/rastro-widget.js), it also goes on Blade
 * and Filament sites (<x-rastro::widget />, RastroPlugin) and, with a
 * site's public key, straight on a site without Laravel (see embed.ts).
 *
 * It talks JSON to the package's widget routes and polls for answers. The
 * conversation id + token live in localStorage, so the chat survives page
 * changes and reloads. Self-contained CSS; every text comes from the Rastro
 * central, through this site's own endpoints.
 *
 * Before the form, the visitor walks the published help tree: each pick shows
 * more options or an answer. When it does not solve it, the form opens and
 * the path taken goes along, so the agent sees where the person got stuck.
 *
 * From the moment the page loads it also keeps a short trail of what the
 * person does — screens, clicks, forms sent, validation errors, failed
 * requests, JavaScript errors — in sessionStorage. It goes to the agent with
 * each message. Never what was typed: only field names and messages.
 */

interface ChatMessage {
    id: string;
    author: 'contact' | 'agent' | 'bot' | 'system';
    author_name: string | null;
    body: string;
    created_at: string;
}

interface State {
    id: string;
    status: 'open' | 'closed';
    /** The AI is answering (until it hands over or an agent replies). */
    ai?: { active: boolean; thinking: boolean };
    messages: ChatMessage[];
    /** "How was the service?", asked once it closed; `score` once answered. */
    rating?: { score: number | null; comment: string | null } | null;
}

interface HelpOption {
    id: string;
    label: string;
    prompt: string | null;
    answer_html: string | null;
    help_link: { url: string; kind: 'video' | 'page' } | null;
    children: HelpOption[];
}

interface Config {
    strings: Record<string, string>;
    help: HelpOption[];
    context: boolean;
    user: { name: string; email: string } | null;
    max_length: number;
    poll_ms: number;
    /** Chosen in the central's panel ("Chat do site"); absent on older centrals. */
    theme?: Theme | null;
}

interface Theme {
    accent: string | null;
    position: 'right' | 'left';
    mode: 'auto' | 'light' | 'dark';
}

const props = withDefaults(
    defineProps<{
        endpoint?: string;
        storageKey?: string;
        /**
         * A site without Laravel: its public key, sent to the central's
         * `support/embed` endpoints (another origin: no cookies).
         */
        siteKey?: string;
    }>(),
    {
        endpoint: '/support/widget',
        storageKey: 'rastro',
        siteKey: undefined,
    },
);

const config = ref<Config | null>(null);

/**
 * The central's look: classes for the corner and light/dark, and the color as
 * --wsw-theme-* variables. The site's own CSS still wins: it sets --wsw-*,
 * which only fall back to these (see CUSTOMIZING.md).
 */
const themeClasses = computed(() => {
    const theme = config.value?.theme;

    return {
        'wsw-left': theme?.position === 'left',
        'wsw-light': theme?.mode === 'light',
        'wsw-dark': theme?.mode === 'dark',
    };
});

const themeStyle = computed((): Record<string, string> => {
    const accent = config.value?.theme?.accent;

    if (!accent || !/^#[0-9a-f]{6}$/i.test(accent)) {
        return {};
    }

    return {
        '--wsw-theme-accent': accent,
        '--wsw-theme-accent-text': readableOn(accent),
    };
});

/** White or near-black, whichever reads better on the color (WCAG luminance). */
function readableOn(hex: string): string {
    const [r, g, b] = [1, 3, 5].map((at) => {
        const channel = parseInt(hex.slice(at, at + 2), 16) / 255;

        return channel <= 0.03928
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.45 ? '#18181b' : '#ffffff';
}

const expanded = ref(false);
const state = ref<State | null>(null);
const token = ref<string | null>(null);
const error = ref<string | null>(null);
const sending = ref(false);
const confirmingEnd = ref(false);
const seen = ref(0);

const form = ref({ name: '', email: '', message: '' });

/*
 * Help tree: `helpPath` holds the ids picked so far; `asking` switches from
 * the options to the form.
 */
const helpPath = ref<string[]>([]);
const asking = ref(false);
const feedback = ref<'yes' | null>(null);

const helpTrail = computed<HelpOption[]>(() => {
    const trail: HelpOption[] = [];
    let level = config.value?.help ?? [];

    for (const id of helpPath.value) {
        const found = level.find((option) => option.id === id);

        if (!found) {
            break;
        }

        trail.push(found);
        level = found.children;
    }

    return trail;
});

const helpNode = computed(
    () => helpTrail.value[helpTrail.value.length - 1] ?? null,
);
const helpOptions = computed(() =>
    helpNode.value ? helpNode.value.children : (config.value?.help ?? []),
);
const showingHelp = computed(
    () => !asking.value && (config.value?.help.length ?? 0) > 0,
);

function pick(option: HelpOption) {
    helpPath.value = [...helpPath.value, option.id];
    feedback.value = null;

    // An option with nothing behind it goes straight to a person.
    if (
        !option.answer_html &&
        !option.help_link &&
        option.children.length === 0
    ) {
        asking.value = true;
    }
}

function back() {
    helpPath.value = helpPath.value.slice(0, -1);
    feedback.value = null;
}

function restartHelp() {
    helpPath.value = [];
    feedback.value = null;
    asking.value = false;
}

function answerFeedback(helpful: boolean) {
    const node = helpNode.value;

    if (node) {
        call<null>('POST', `/help/${node.id}/feedback`, { helpful }).catch(
            () => {
                // A lost vote is not worth bothering the visitor.
            },
        );
    }

    if (helpful) {
        feedback.value = 'yes';
    } else {
        asking.value = true;
    }
}
const draft = ref('');
const thread = ref<HTMLElement | null>(null);

const s = (key: string): string => config.value?.strings[key] ?? '';

/*
 * The trail: last events of this tab, kept across page loads in
 * sessionStorage. `sentAt` marks what already went to support.
 */
type TrailEvent = { type: string; at: string } & Record<string, unknown>;

const MAX_TRAIL = 40;
const trailKey = computed(() => `${props.storageKey}:trail`);

function readTrail(): { events: TrailEvent[]; sentAt: string | null } {
    try {
        const raw = window.sessionStorage.getItem(trailKey.value);

        return raw ? JSON.parse(raw) : { events: [], sentAt: null };
    } catch {
        return { events: [], sentAt: null };
    }
}

function writeTrail(value: { events: TrailEvent[]; sentAt: string | null }) {
    try {
        window.sessionStorage.setItem(trailKey.value, JSON.stringify(value));
    } catch {
        // Blocked storage: the trail just stays shorter.
    }
}

function record(event: Record<string, unknown> & { type: string }) {
    const trail = readTrail();
    trail.events = [
        ...trail.events,
        { ...event, at: new Date().toISOString() },
    ].slice(-MAX_TRAIL);
    writeTrail(trail);
}

/** Path + query keys only: values can hold e-mails, tokens… */
function safeUrl(raw: string): string {
    try {
        const url = new URL(raw, window.location.origin);
        const keys = [...new Set(url.searchParams.keys())];

        return url.pathname + (keys.length ? `?${keys.join('&')}` : '');
    } catch {
        return '';
    }
}

/** What goes with a message: the page as it is now + the trail not sent yet. */
function contextPayload(): Record<string, unknown> | undefined {
    if (!config.value?.context) {
        return undefined;
    }

    const trail = readTrail();

    return {
        environment: {
            url: safeUrl(window.location.href),
            title: document.title,
            referrer: document.referrer ? safeUrl(document.referrer) : '',
            language: navigator.language,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            screen: `${window.screen.width}x${window.screen.height}`,
            viewport: `${window.innerWidth}x${window.innerHeight}`,
        },
        events: trail.events.filter(
            (event) => !trail.sentAt || event.at > trail.sentAt,
        ),
    };
}

function markTrailSent() {
    const trail = readTrail();
    trail.sentAt =
        trail.events[trail.events.length - 1]?.at ?? new Date().toISOString();
    writeTrail(trail);
}

const listeners: Array<[EventTarget, string, EventListener]> = [];

/**
 * Livewire hooks cannot be removed, so they are added once per page and
 * write to whichever widget is mounted now.
 */
let livewireRecorder: typeof record | null = null;
let livewireHooked = false;

type LivewireHooks = {
    hook: (
        name: 'commit' | 'request',
        callback: (payload: {
            succeed: (callback: (response: { snapshot: string }) => void) => void;
            fail: (callback: (response: { status: number }) => void) => void;
        }) => void,
    ) => void;
};

function firstMessages(errors: unknown): Record<string, string> {
    const messages: Record<string, string> = {};

    for (const [field, message] of Object.entries(
        (errors ?? {}) as Record<string, unknown>,
    )) {
        messages[field] = String(
            Array.isArray(message) ? (message[0] ?? '') : message,
        ).slice(0, 200);
    }

    return messages;
}

function hookLivewire(livewire: LivewireHooks) {
    if (livewireHooked) {
        return;
    }

    livewireHooked = true;

    livewire.hook('commit', ({ succeed }) => {
        succeed(({ snapshot }) => {
            let errors: Record<string, string> = {};

            try {
                errors = firstMessages(JSON.parse(snapshot)?.memo?.errors);
            } catch {
                return;
            }

            if (Object.keys(errors).length > 0) {
                livewireRecorder?.({
                    type: 'validation',
                    url: safeUrl(window.location.href),
                    errors,
                });
            }
        });
    });

    livewire.hook('request', ({ fail }) => {
        fail(({ status }) => {
            livewireRecorder?.(
                status === 503 && !navigator.onLine
                    ? { type: 'network_error', url: safeUrl(window.location.href) }
                    : {
                          type: 'http_error',
                          status,
                          url: safeUrl(window.location.href),
                      },
            );
        });
    });
}

function watchLivewire() {
    livewireRecorder = record;
    const livewire = (window as unknown as { Livewire?: LivewireHooks })
        .Livewire;

    if (livewire?.hook) {
        hookLivewire(livewire);
    } else {
        document.addEventListener(
            'livewire:init',
            () =>
                hookLivewire(
                    (window as unknown as { Livewire: LivewireHooks }).Livewire,
                ),
            { once: true },
        );
    }
}

function listen(target: EventTarget, name: string, handler: EventListener) {
    target.addEventListener(name, handler, true);
    listeners.push([target, name, handler]);
}

function startTrail() {
    record({
        type: 'page',
        url: safeUrl(window.location.href),
        title: document.title,
    });

    // Inertia visits: screens, forms sent, validation errors, failures.
    listen(document, 'inertia:navigate', ((event: CustomEvent) => {
        const url = event.detail?.page?.url ?? window.location.href;
        setTimeout(
            () =>
                record({
                    type: 'page',
                    url: safeUrl(url),
                    title: document.title,
                }),
            0,
        );
    }) as EventListener);

    // Livewire (Filament) and Turbo screen changes without a full load.
    for (const name of ['livewire:navigated', 'turbo:load']) {
        listen(document, name, (() => {
            const url = safeUrl(window.location.href);
            const last = readTrail().events.at(-1);

            // Both also fire on the first load, already noted.
            if (last?.type !== 'page' || last.url !== url) {
                record({ type: 'page', url, title: document.title });
            }
        }) as EventListener);
    }

    listen(document, 'inertia:start', ((event: CustomEvent) => {
        const visit = event.detail?.visit;
        const method = String(visit?.method ?? 'get').toLowerCase();

        if (method !== 'get') {
            record({
                type: 'submit',
                method: method.toUpperCase(),
                url: safeUrl(String(visit?.url ?? '')),
            });
        }
    }) as EventListener);

    listen(document, 'inertia:error', ((event: CustomEvent) => {
        const errors: Record<string, string> = {};

        for (const [field, message] of Object.entries(
            event.detail?.errors ?? {},
        )) {
            errors[field] = Array.isArray(message)
                ? String(message[0] ?? '')
                : String(message);
        }

        record({
            type: 'validation',
            url: safeUrl(window.location.href),
            errors,
        });
    }) as EventListener);

    listen(document, 'inertia:httpException', ((event: CustomEvent) => {
        record({
            type: 'http_error',
            status: event.detail?.response?.status,
            url: safeUrl(window.location.href),
        });
    }) as EventListener);

    listen(document, 'inertia:networkError', (() => {
        record({ type: 'network_error', url: safeUrl(window.location.href) });
    }) as EventListener);

    // Livewire (Filament): a validation error comes back as a normal
    // response, with the messages in the component's snapshot.
    watchLivewire();

    // Clicks on buttons and links — their text, never field values.
    listen(document, 'click', ((event: MouseEvent) => {
        const target = (event.target as Element | null)?.closest(
            'button, a, [role="button"]',
        );

        if (!target || target.closest('.wsw')) {
            return;
        }

        const text = (
            target.getAttribute('aria-label') ||
            target.textContent ||
            ''
        )
            .trim()
            .replace(/\s+/g, ' ')
            .slice(0, 80);
        const href =
            target instanceof HTMLAnchorElement
                ? safeUrl(target.href)
                : undefined;

        if (text || href) {
            record({ type: 'click', text, ...(href ? { url: href } : {}) });
        }
    }) as EventListener);

    listen(window, 'error', ((event: ErrorEvent) => {
        record({
            type: 'js_error',
            message: String(event.message).slice(0, 200),
            file: safeUrl(event.filename ?? ''),
        });
    }) as EventListener);

    listen(window, 'unhandledrejection', ((event: PromiseRejectionEvent) => {
        const reason =
            event.reason instanceof Error
                ? event.reason.message
                : String(event.reason);
        record({ type: 'js_error', message: reason.slice(0, 200) });
    }) as EventListener);
}

const unread = computed(() => {
    const fromSupport =
        state.value?.messages.filter((message) => message.author !== 'contact')
            .length ?? 0;

    return Math.max(0, fromSupport - seen.value);
});

const waiting = computed(
    () =>
        state.value?.status === 'open' &&
        !state.value.ai?.active &&
        !state.value.messages.some((message) => message.author === 'agent'),
);

const handingOff = ref(false);

async function talkToPerson() {
    if (!state.value || handingOff.value) {
        return;
    }

    handingOff.value = true;

    try {
        state.value = await call<State>(
            'POST',
            `/conversations/${state.value.id}/handoff`,
            {},
        );
        await scrollDown();
    } catch {
        error.value = s('error');
    } finally {
        handingOff.value = false;
    }
}

function readStored(): { id: string; token: string } | null {
    try {
        const raw = window.localStorage.getItem(props.storageKey);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function store(value: { id: string; token: string } | null) {
    try {
        if (value) {
            window.localStorage.setItem(
                props.storageKey,
                JSON.stringify(value),
            );
        } else {
            window.localStorage.removeItem(props.storageKey);
        }
    } catch {
        // Private mode / blocked storage: the chat still works for this page.
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function call<T>(
    method: 'GET' | 'POST',
    path: string,
    body?: unknown,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (props.siteKey) {
        headers['X-Support-Site'] = props.siteKey;
    } else {
        headers['X-XSRF-TOKEN'] = xsrfToken();
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (token.value) {
        headers['X-Support-Token'] = token.value;
    }

    const init: RequestInit = {
        method,
        headers,
        credentials: props.siteKey ? 'omit' : 'same-origin',
    };

    if (body !== undefined) {
        init.body = JSON.stringify(body);
    }

    const response = await fetch(
        props.endpoint.replace(/\/$/, '') + path,
        init,
    );

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        const failure = new Error(
            payload.message ?? String(response.status),
        ) as Error & { status?: number };
        failure.status = response.status;

        throw failure;
    }

    if (response.status === 204) {
        return null as T;
    }

    return response.json() as Promise<T>;
}

async function refresh() {
    if (!state.value || !token.value) {
        return;
    }

    try {
        const fresh = await call<State>(
            'GET',
            `/conversations/${state.value.id}`,
        );
        const grew =
            fresh.messages.length !== state.value.messages.length ||
            fresh.ai?.thinking !== state.value.ai?.thinking;
        state.value = fresh;

        if (grew) {
            await scrollDown();
        }
    } catch (failure) {
        // Gone (pruned) or the token no longer matches: start over.
        if ((failure as { status?: number }).status === 404) {
            reset();
        }
    }
}

async function start() {
    if (sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    try {
        const opened = await call<State & { token: string }>(
            'POST',
            '/conversations',
            {
                ...form.value,
                help_path: helpPath.value,
                context: contextPayload(),
            },
        );
        markTrailSent();
        token.value = opened.token;
        state.value = {
            id: opened.id,
            status: opened.status,
            messages: opened.messages,
        };
        store({ id: opened.id, token: opened.token });
        form.value.message = '';
        restartHelp();
        await scrollDown();
    } catch (failure) {
        error.value = (failure as Error).message || s('error');
    } finally {
        sending.value = false;
    }
}

async function send() {
    const body = draft.value.trim();

    if (!state.value || body === '' || sending.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    try {
        state.value = await call<State>(
            'POST',
            `/conversations/${state.value.id}/messages`,
            { body, context: contextPayload() },
        );
        markTrailSent();
        draft.value = '';
        await scrollDown();
    } catch {
        error.value = s('error');
    } finally {
        sending.value = false;
    }
}

async function end() {
    if (!state.value) {
        return;
    }

    try {
        state.value = await call<State>(
            'POST',
            `/conversations/${state.value.id}/close`,
            {},
        );
    } catch {
        error.value = s('error');
    } finally {
        confirmingEnd.value = false;
    }
}

const ratingPick = ref<number | null>(null);
const ratingComment = ref('');
const rating = ref(false);

async function sendRating() {
    if (!state.value || !ratingPick.value || rating.value) {
        return;
    }

    rating.value = true;

    try {
        state.value = await call<State>(
            'POST',
            `/conversations/${state.value.id}/rating`,
            { rating: ratingPick.value, comment: ratingComment.value || null },
        );
    } catch {
        error.value = s('error');
    } finally {
        rating.value = false;
    }
}

function reset() {
    ratingPick.value = null;
    ratingComment.value = '';
    state.value = null;
    token.value = null;
    seen.value = 0;
    store(null);
    restartHelp();
}

function onComposerKey(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
}

async function scrollDown() {
    await nextTick();
    thread.value?.scrollTo({
        top: thread.value.scrollHeight,
        behavior: 'smooth',
    });
}

function markSeen() {
    seen.value =
        state.value?.messages.filter((message) => message.author !== 'contact')
            .length ?? 0;
}

watch(expanded, (isOpen) => {
    if (isOpen) {
        markSeen();
        scrollDown();
    }
});

watch(
    () => state.value?.messages.length,
    () => {
        if (expanded.value) {
            markSeen();
        }
    },
);

let timer: ReturnType<typeof setTimeout> | undefined;

function schedule() {
    clearTimeout(timer);

    const base = config.value?.poll_ms ?? 3000;
    // Slower while the panel is collapsed or the tab is hidden; still enough to
    // light the unread badge.
    const delay =
        expanded.value && document.visibilityState === 'visible'
            ? base
            : base * 5;

    timer = setTimeout(async () => {
        if (state.value?.status === 'open') {
            await refresh();
        }

        schedule();
    }, delay);
}

onMounted(async () => {
    try {
        config.value = await call<Config>('GET', '/config');
    } catch {
        return;
    }

    if (config.value.context) {
        startTrail();
    }

    if (config.value.user) {
        form.value.name = config.value.user.name;
        form.value.email = config.value.user.email;
    }

    const stored = readStored();

    if (stored) {
        token.value = stored.token;
        state.value = { id: stored.id, status: 'open', messages: [] };
        await refresh();
        markSeen();
    }

    schedule();
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    livewireRecorder = null;

    for (const [target, name, handler] of listeners) {
        target.removeEventListener(name, handler, true);
    }
});

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div
        v-if="config"
        class="wsw"
        :class="[{ expanded }, themeClasses]"
        :style="themeStyle"
    >
        <section
            v-if="expanded"
            class="wsw-panel"
            role="dialog"
            :aria-label="s('title')"
        >
            <header class="wsw-head">
                <div>
                    <strong>{{ s('title') }}</strong>
                    <span>{{ s('subtitle') }}</span>
                </div>
                <button
                    type="button"
                    class="wsw-icon"
                    :aria-label="s('close')"
                    @click="expanded = false"
                >
                    ✕
                </button>
            </header>

            <div v-if="!state && showingHelp" class="wsw-help">
                <p v-if="helpTrail.length" class="wsw-trail">
                    {{ s('help_trail') }}:
                    {{ helpTrail.map((option) => option.label).join(' › ') }}
                </p>

                <div class="wsw-bubble wsw-help-bubble">
                    <template v-if="!helpNode">
                        <strong>{{ s('help_title') }}</strong>
                        <p>{{ s('help_intro') }}</p>
                    </template>
                    <template v-else>
                        <strong v-if="helpNode.prompt || helpOptions.length">{{
                            helpNode.prompt || helpNode.label
                        }}</strong>
                        <!-- eslint-disable-next-line vue/no-v-html -- sanitized server-side (markdown, html stripped) -->
                        <div
                            v-if="helpNode.answer_html"
                            class="wsw-answer"
                            v-html="helpNode.answer_html"
                        />
                        <a
                            v-if="helpNode.help_link"
                            class="wsw-help-link"
                            :href="helpNode.help_link.url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <span aria-hidden="true">{{
                                helpNode.help_link.kind === 'video' ? '▶' : '↗'
                            }}</span>
                            {{
                                s(
                                    helpNode.help_link.kind === 'video'
                                        ? 'help_video'
                                        : 'help_page',
                                )
                            }}
                        </a>
                    </template>
                </div>

                <div v-if="helpOptions.length" class="wsw-options">
                    <button
                        v-for="option in helpOptions"
                        :key="option.id"
                        type="button"
                        class="wsw-option"
                        @click="pick(option)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div
                    v-if="
                        (helpNode?.answer_html || helpNode?.help_link) &&
                        helpOptions.length === 0
                    "
                    class="wsw-options"
                >
                    <template v-if="feedback === 'yes'">
                        <p class="wsw-system">{{ s('help_thanks') }}</p>
                        <button
                            type="button"
                            class="wsw-option"
                            @click="restartHelp"
                        >
                            {{ s('help_restart') }}
                        </button>
                    </template>
                    <template v-else>
                        <span class="wsw-question">{{
                            s('help_question')
                        }}</span>
                        <button
                            type="button"
                            class="wsw-option"
                            @click="answerFeedback(true)"
                        >
                            {{ s('help_yes') }}
                        </button>
                        <button
                            type="button"
                            class="wsw-option"
                            @click="answerFeedback(false)"
                        >
                            {{ s('help_no') }}
                        </button>
                    </template>
                </div>

                <div class="wsw-help-nav">
                    <button
                        v-if="helpPath.length"
                        type="button"
                        class="wsw-link"
                        @click="back"
                    >
                        ← {{ s('help_back') }}
                    </button>
                    <button
                        type="button"
                        class="wsw-link"
                        @click="asking = true"
                    >
                        {{ s('help_skip') }}
                    </button>
                </div>
            </div>

            <form v-else-if="!state" class="wsw-start" @submit.prevent="start">
                <p v-if="helpTrail.length" class="wsw-trail">
                    {{ s('help_trail') }}:
                    {{ helpTrail.map((option) => option.label).join(' › ') }}
                </p>
                <label>
                    {{ s('name') }}
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        maxlength="120"
                        autocomplete="name"
                    />
                </label>
                <label>
                    {{ s('email') }}
                    <input
                        v-model="form.email"
                        type="email"
                        required
                        maxlength="190"
                        autocomplete="email"
                    />
                    <small>{{ s('email_hint') }}</small>
                </label>
                <label>
                    {{ s('message') }}
                    <textarea
                        v-model="form.message"
                        required
                        rows="3"
                        :maxlength="config.max_length"
                    />
                </label>
                <p v-if="config.context" class="wsw-notice">
                    {{ s('context_notice') }}
                </p>
                <p v-if="error" class="wsw-error">{{ error }}</p>
                <button
                    type="submit"
                    class="wsw-btn primary"
                    :disabled="sending"
                >
                    {{ s('start') }}
                </button>
                <button
                    v-if="(config.help.length ?? 0) > 0"
                    type="button"
                    class="wsw-link"
                    @click="asking = false"
                >
                    ← {{ s('help_back') }}
                </button>
            </form>

            <template v-else>
                <div ref="thread" class="wsw-thread">
                    <div
                        v-for="message in state.messages"
                        :key="message.id"
                        class="wsw-msg"
                        :class="message.author"
                    >
                        <span
                            v-if="message.author === 'system'"
                            class="wsw-system"
                            >{{ message.body }}</span
                        >
                        <div v-else class="wsw-bubble">
                            <span class="wsw-author">{{
                                message.author === 'contact'
                                    ? s('you')
                                    : (message.author_name ?? s('agent'))
                            }}</span>
                            <span
                                v-if="message.author === 'bot'"
                                class="wsw-bot-tag"
                                >🤖 {{ s('bot_notice') }}</span
                            >
                            <p>{{ message.body }}</p>
                            <time>{{ time(message.created_at) }}</time>
                        </div>
                    </div>
                    <p v-if="state.ai?.thinking" class="wsw-system wsw-typing">
                        {{ s('typing') }}
                    </p>
                    <p v-if="waiting" class="wsw-system">{{ s('waiting') }}</p>
                </div>

                <footer v-if="state.status === 'open'" class="wsw-composer">
                    <p v-if="error" class="wsw-error">{{ error }}</p>
                    <div class="wsw-row">
                        <textarea
                            v-model="draft"
                            rows="1"
                            :maxlength="config.max_length"
                            :placeholder="s('placeholder')"
                            :aria-label="s('placeholder')"
                            @keydown="onComposerKey"
                        />
                        <button
                            type="button"
                            class="wsw-btn primary"
                            :disabled="sending || draft.trim() === ''"
                            @click="send"
                        >
                            {{ s('send') }}
                        </button>
                    </div>
                    <div v-if="confirmingEnd" class="wsw-confirm">
                        <span>{{ s('end_confirm') }}</span>
                        <button
                            type="button"
                            class="wsw-btn danger"
                            @click="end"
                        >
                            {{ s('end_yes') }}
                        </button>
                        <button
                            type="button"
                            class="wsw-btn"
                            @click="confirmingEnd = false"
                        >
                            {{ s('end_no') }}
                        </button>
                    </div>
                    <div v-else class="wsw-links">
                        <button
                            v-if="state.ai?.active"
                            type="button"
                            class="wsw-link"
                            :disabled="handingOff"
                            @click="talkToPerson"
                        >
                            👤 {{ s('talk_to_person') }}
                        </button>
                        <button
                            type="button"
                            class="wsw-link"
                            @click="confirmingEnd = true"
                        >
                            {{ s('end') }}
                        </button>
                    </div>
                </footer>

                <footer v-else class="wsw-composer">
                    <div v-if="state.rating" class="wsw-rating">
                        <template v-if="state.rating.score === null">
                            <strong>{{ s('rating_question') }}</strong>
                            <div class="wsw-stars">
                                <button
                                    v-for="score in 5"
                                    :key="score"
                                    type="button"
                                    :class="{ on: (ratingPick ?? 0) >= score }"
                                    :aria-label="
                                        s('rating_star').replace(
                                            ':score',
                                            String(score),
                                        )
                                    "
                                    @click="ratingPick = score"
                                >
                                    ★
                                </button>
                            </div>
                            <template v-if="ratingPick">
                                <textarea
                                    v-model="ratingComment"
                                    rows="2"
                                    maxlength="1000"
                                    :placeholder="s('rating_comment')"
                                />
                                <button
                                    type="button"
                                    class="wsw-btn primary"
                                    :disabled="rating"
                                    @click="sendRating"
                                >
                                    {{ s('rating_send') }}
                                </button>
                            </template>
                        </template>
                        <p v-else class="wsw-system">
                            {{ s('rating_thanks') }}
                        </p>
                    </div>
                    <p class="wsw-system">{{ s('closed') }}</p>
                    <button
                        type="button"
                        class="wsw-btn primary"
                        @click="reset"
                    >
                        {{ s('new') }}
                    </button>
                </footer>
            </template>
        </section>

        <button
            v-else
            type="button"
            class="wsw-launcher"
            @click="expanded = true"
        >
            <span aria-hidden="true">💬</span>
            {{ s('launcher') }}
            <span v-if="unread > 0" class="wsw-badge">{{ unread }}</span>
        </button>
    </div>
</template>

<style scoped>
/*
 * Colors, in this order: the site's own --wsw-* (on :root, .dark or any
 * parent; see CUSTOMIZING.md), then the central's theme (--wsw-theme-*, set
 * inline from the panel), then these defaults. The widget reads only the
 * internal --_wsw-* below, so a site never fights selector specificity.
 */
.wsw {
    --_wsw-bg: var(--wsw-bg, #ffffff);
    --_wsw-panel: var(--wsw-panel, #f4f4f5);
    --_wsw-border: var(--wsw-border, #e4e4e7);
    --_wsw-text: var(--wsw-text, #18181b);
    --_wsw-muted: var(--wsw-muted, #71717a);
    --_wsw-accent: var(--wsw-accent, var(--wsw-theme-accent, #2563eb));
    --_wsw-accent-text: var(
        --wsw-accent-text,
        var(--wsw-theme-accent-text, #ffffff)
    );
    --_wsw-mine: var(
        --wsw-mine,
        color-mix(in srgb, var(--_wsw-accent) 16%, var(--_wsw-bg))
    );
    --_wsw-danger: var(--wsw-danger, #dc2626);

    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 60;
    color: var(--_wsw-text);
    font-size: 14px;
}

/* Whatever the site's reset, fields fit the panel. */
.wsw *,
.wsw *::before,
.wsw *::after {
    box-sizing: border-box;
}

.wsw.wsw-left {
    right: auto;
    left: 16px;
}

/* Dark: when the site's html has `dark` (mode "auto"), or always ("dark"). */
:global(.dark .wsw:not(.wsw-light)),
.wsw.wsw-dark {
    --_wsw-bg: var(--wsw-bg, #0a0a0b);
    --_wsw-panel: var(--wsw-panel, #1f1f23);
    --_wsw-border: var(--wsw-border, #27272a);
    --_wsw-text: var(--wsw-text, #f4f4f5);
    --_wsw-muted: var(--wsw-muted, #a1a1aa);
    --_wsw-accent: var(--wsw-accent, var(--wsw-theme-accent, #3b82f6));
    --_wsw-mine: var(
        --wsw-mine,
        color-mix(in srgb, var(--_wsw-accent) 35%, var(--_wsw-bg))
    );
}

.wsw-launcher {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 18px;
    border: 0;
    border-radius: 999px;
    background: var(--_wsw-accent);
    color: var(--_wsw-accent-text);
    box-shadow: 0 8px 24px rgb(0 0 0 / 0.18);
    cursor: pointer;
    font: inherit;
    font-weight: 600;
}

.wsw-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--_wsw-danger);
    color: #fff;
    font-size: 12px;
    line-height: 22px;
    text-align: center;
}

.wsw-panel {
    display: flex;
    flex-direction: column;
    width: min(380px, calc(100vw - 32px));
    height: min(560px, calc(100vh - 32px));
    border: 1px solid var(--_wsw-border);
    border-radius: 16px;
    background: var(--_wsw-bg);
    box-shadow: 0 16px 48px rgb(0 0 0 / 0.22);
    overflow: hidden;
}

.wsw-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    padding: 14px 16px;
    background: var(--_wsw-accent);
    color: var(--_wsw-accent-text);
}

.wsw-head div {
    display: flex;
    flex-direction: column;
}

.wsw-head span {
    opacity: 0.85;
    font-size: 12px;
}

.wsw-icon {
    border: 0;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font-size: 16px;
}

.wsw-start {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px;
    overflow-y: auto;
}

.wsw-start label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 13px;
    font-weight: 500;
}

.wsw-start small {
    color: var(--_wsw-muted);
    font-weight: 400;
}

.wsw input,
.wsw textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--_wsw-border);
    border-radius: 8px;
    background: var(--_wsw-bg);
    color: var(--_wsw-text);
    font: inherit;
    resize: none;
}

.wsw-thread {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 8px;
    padding: 14px;
    overflow-y: auto;
    background: var(--_wsw-bg);
}

.wsw-msg {
    display: flex;
    justify-content: flex-start;
}

.wsw-msg.contact {
    justify-content: flex-end;
}

.wsw-msg.system {
    justify-content: center;
}

.wsw-bubble {
    max-width: 82%;
    padding: 8px 12px;
    border-radius: 12px;
    background: var(--_wsw-panel);
}

.wsw-msg.contact .wsw-bubble {
    background: var(--_wsw-mine);
}

.wsw-bubble p {
    margin: 2px 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.wsw-author {
    font-size: 12px;
    font-weight: 600;
}

.wsw-msg.bot .wsw-bubble {
    border: 1px dashed var(--_wsw-muted);
}

.wsw-bot-tag {
    display: block;
    color: var(--_wsw-muted);
    font-size: 11px;
}

.wsw-typing {
    font-style: italic;
}

.wsw-links {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px;
}

.wsw-bubble time {
    display: block;
    color: var(--_wsw-muted);
    font-size: 11px;
    text-align: right;
}

.wsw-system {
    margin: 0;
    color: var(--_wsw-muted);
    font-size: 12px;
    text-align: center;
}

.wsw-composer {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px;
    border-top: 1px solid var(--_wsw-border);
}

.wsw-row {
    display: flex;
    gap: 8px;
}

.wsw-confirm {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    font-size: 12px;
}

.wsw-btn {
    padding: 8px 12px;
    border: 1px solid var(--_wsw-border);
    border-radius: 8px;
    background: var(--_wsw-bg);
    color: var(--_wsw-text);
    cursor: pointer;
    font: inherit;
}

.wsw-btn:disabled {
    opacity: 0.5;
    cursor: default;
}

.wsw-btn.primary {
    border-color: var(--_wsw-accent);
    background: var(--_wsw-accent);
    color: var(--_wsw-accent-text);
}

.wsw-btn.danger {
    border-color: var(--_wsw-danger);
    background: var(--_wsw-danger);
    color: #fff;
}

.wsw-link {
    align-self: center;
    border: 0;
    background: transparent;
    color: var(--_wsw-muted);
    cursor: pointer;
    font: inherit;
    font-size: 12px;
    text-decoration: underline;
}

.wsw-help {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 10px;
    padding: 14px;
    overflow-y: auto;
}

.wsw-help-bubble {
    max-width: 100%;
}

.wsw-help-bubble p {
    white-space: normal;
}

.wsw-answer :deep(p),
.wsw-answer :deep(ol),
.wsw-answer :deep(ul) {
    margin: 6px 0 0;
}

.wsw-answer :deep(ol),
.wsw-answer :deep(ul) {
    padding-left: 18px;
}

.wsw-answer :deep(a) {
    color: var(--_wsw-accent);
}

.wsw-options {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.wsw-rating {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    width: 100%;
    text-align: center;
}

.wsw-stars {
    display: flex;
    gap: 4px;
}

.wsw-stars button {
    padding: 0 2px;
    border: 0;
    background: transparent;
    color: var(--_wsw-border);
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
}

.wsw-stars button.on {
    color: #f59e0b;
}

.wsw-rating textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--_wsw-border);
    border-radius: 10px;
    background: var(--_wsw-bg);
    color: var(--_wsw-text);
    font: inherit;
    resize: vertical;
}

.wsw-help-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--_wsw-accent);
    color: var(--_wsw-accent-text);
    font-weight: 600;
    text-decoration: none;
}

.wsw-help-link:hover {
    opacity: 0.9;
}

.wsw-option {
    padding: 9px 12px;
    border: 1px solid var(--_wsw-accent);
    border-radius: 10px;
    background: var(--_wsw-bg);
    color: var(--_wsw-accent);
    cursor: pointer;
    font: inherit;
    text-align: left;
}

.wsw-option:hover {
    background: var(--_wsw-mine);
}

.wsw-question {
    color: var(--_wsw-muted);
    font-size: 12px;
}

.wsw-trail {
    margin: 0;
    color: var(--_wsw-muted);
    font-size: 11px;
}

.wsw-help-nav {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    margin-top: auto;
}

.wsw-notice {
    margin: 0;
    color: var(--_wsw-muted);
    font-size: 11px;
    line-height: 1.4;
}

.wsw-error {
    margin: 0;
    color: var(--_wsw-danger);
    font-size: 12px;
}
</style>
