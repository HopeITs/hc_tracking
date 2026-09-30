/**
 * The real-time half of the inbox.
 *
 * One private-channel subscription per page load (the bell renders twice —
 * mobile and desktop header — so subscribing from the component would double
 * every toast). A push refreshes every inbox view through a Livewire event,
 * raises an in-page toast (important: 6s, critical: sticky) and, when the
 * window is hidden and the user opted in, a desktop popup. While the socket
 * is not connected the inbox is polled every 60s; returning to the tab always
 * re-syncs.
 *
 * Live updates never outlive the session. After the session lifetime passes
 * with no input in this tab, the socket and polling pause until the user is
 * back. On ".session.ended" (a sign-out somewhere for this user) the socket
 * reconnects, which re-authorizes the channel: a tab whose session is gone is
 * refused (401/403/419) and stays disconnected.
 *
 * Desktop popups go through the service worker (public/sw.js) so they carry
 * the app's name and banner artwork, and a click opens the record. The same file
 * makes the site installable as the "HC Tracking" app; the install prompt is
 * exposed on window.hcApp, and the install dialog (appInstallDialog) uses it.
 */
const POLL_INTERVAL_MS = 60_000;
const TOAST_DURATION_MS = 6_000;
const MAX_TOASTS = 4;
const APP_ICON = '/app-icons/icon-192.png';
const APP_BADGE = '/app-icons/badge-96.png';

const AUTH_REFUSED_STATUSES = [401, 403, 419];

let subscribed = false;
let pollTimer = null;

/** null while live; 'idle' (resumes on input) or 'signed-out' (stays off). */
let pausedReason = null;
let lastActivityAt = Date.now();

/** Translated strings rendered by <x-notifications.toasts>. */
function labels() {
    try {
        return JSON.parse(document.getElementById('hc-notification-labels')?.textContent ?? '{}');
    } catch {
        return {};
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function refreshInbox() {
    window.Livewire?.dispatch('notification-received');
}

function navigateTo(url) {
    if (window.Livewire?.navigate) {
        window.Livewire.navigate(url);
    } else {
        window.location.assign(url);
    }
}

async function markRead(readUrl) {
    if (!readUrl) {
        return;
    }

    await fetch(readUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
    });

    refreshInbox();
}

/* ------------------------------------------------------------------ */
/* Service worker and installable app                                  */
/* ------------------------------------------------------------------ */

const serviceWorker = 'serviceWorker' in navigator
    ? navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => null)
    : Promise.resolve(null);

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data?.type === 'hc-notification-open') {
            navigateTo(event.data.url);
        }
    });
}

let installPrompt = null;

const standalone = () => window.matchMedia('(display-mode: standalone)').matches;

function announceInstallState() {
    window.dispatchEvent(new CustomEvent('hc-app-install-change', { detail: window.hcApp.state() }));
}

window.hcApp = {
    /** installed | available | unavailable */
    state() {
        if (standalone()) {
            return 'installed';
        }

        return installPrompt ? 'available' : 'unavailable';
    },

    async install() {
        if (!installPrompt) {
            return 'unavailable';
        }

        installPrompt.prompt();
        const { outcome } = await installPrompt.userChoice;
        installPrompt = null;
        announceInstallState();

        return outcome;
    },
};

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    announceInstallState();
});

window.addEventListener('appinstalled', () => {
    installPrompt = null;
    announceInstallState();
});

/* ------------------------------------------------------------------ */
/* Unread count on the installed app's taskbar icon                     */
/* ------------------------------------------------------------------ */

function setAppBadge(total) {
    if (!('setAppBadge' in navigator)) {
        return;
    }

    (total > 0 ? navigator.setAppBadge(total) : navigator.clearAppBadge()).catch(() => {});
}

/** The bell announces fresh counts after every refresh. */
window.addEventListener('notification-counts', (event) => setAppBadge(event.detail?.counts?.total ?? 0));

/** On each page load, start from the count the bell rendered. */
document.addEventListener('livewire:navigated', () => {
    const counter = document.querySelector('[data-unread-count]');

    setAppBadge(counter ? Number.parseInt(counter.textContent, 10) || 0 : 0);
});

/* ------------------------------------------------------------------ */
/* Desktop popups                                                       */
/* ------------------------------------------------------------------ */

/** Every desktop popup shares one tag, so a new one replaces the last. */
const POPUP_TAG = 'hc-tracking';

/** About what one line under the banner holds before Windows wraps it. */
const POPUP_LINE_LENGTH = 44;

/**
 * Shorten a sentence to one popup line, cutting at a word boundary.
 */
function oneLine(sentence, room) {
    if (sentence.length <= room) {
        return sentence;
    }

    const cut = sentence.slice(0, room - 1);
    const space = cut.lastIndexOf(' ');

    return (space > room / 2 ? cut.slice(0, space) : cut).replace(/[\s,.;:·]+$/, '') + '…';
}

/**
 * Show one notification as a desktop popup. With its banner (module ×
 * urgency artwork on top, which already says "Critical · Risks") the
 * sentence itself is the title and there is no left icon, so nothing is
 * said twice; without one, the module tile is the icon. There are no
 * buttons (they cost a full row): a click opens it, and the sentence is cut
 * to one line. Popups never stack: a new one replaces the one on screen and
 * counts it ("+2 more"); a pile opens the inbox.
 *
 * Every open tab (and the installed app window) receives the same push, so
 * the popup is shown under a cross-tab lock and remembers the ids it already
 * holds: the second tab finds its notification on screen and does nothing,
 * instead of counting it again as "+1 more".
 *
 * Returns false when the browser cannot show popups. Also used by the
 * settings page's "Test popup" button, so the test takes the real path.
 */
async function showDesktopPopup(payload) {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return false;
    }

    const show = () => renderDesktopPopup(payload);

    return navigator.locks?.request ? navigator.locks.request('hc-desktop-popup', show) : show();
}

/** Remember at most this many notification ids on the popup on screen. */
const POPUP_REMEMBERED_IDS = 50;

async function renderDesktopPopup(payload) {
    const text = labels();
    const critical = payload.priority === 'critical';
    const urgency = critical ? text.critical : text.important;
    const registration = await serviceWorker;
    const [showing] = registration?.getNotifications ? await registration.getNotifications({ tag: POPUP_TAG }) : [];
    const shownIds = showing?.data?.ids ?? [];

    if (shownIds.includes(payload.id)) {
        return true;
    }

    const pile = (showing?.data?.pile ?? 0) + 1;
    const anyCritical = critical || Boolean(showing?.data?.critical);
    const inbox = new URL('/notifications', window.location.origin).href;
    const more = pile > 1 && text.more ? text.more.replace(':count', pile - 1) : '';

    const suffix = more ? ` · ${more}` : '';
    const title = payload.image
        ? oneLine(payload.text, POPUP_LINE_LENGTH - suffix.length) + suffix
        : [urgency, payload.module_label].filter(Boolean).join(' · ') + (more ? `  ${more}` : '');

    const options = {
        body: payload.image ? '' : payload.text,
        ...(payload.image ? { image: payload.image } : { icon: payload.icon || APP_ICON }),
        badge: APP_BADGE,
        tag: POPUP_TAG,
        renotify: true,
        // Staying on screen makes Windows add a "Close" button row; popups
        // slide into the notification centre instead, leaving only the ✕.
        requireInteraction: false,
        lang: document.documentElement.lang,
        dir: document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr',
        timestamp: Date.parse(payload.created_at) || Date.now(),
        data: {
            url: pile > 1 ? inbox : payload.url,
            ids: [...shownIds, payload.id].slice(-POPUP_REMEMBERED_IDS),
            pile,
            critical: anyCritical,
        },
    };

    if (registration?.showNotification) {
        // No buttons: the button row is the tallest part under the banner.
        // Clicking anywhere on the popup opens the record (or the inbox).
        await registration.showNotification(title, options);

        return true;
    }

    const popup = new Notification(title, options);
    popup.onclick = () => {
        window.focus();
        navigateTo(payload.url);
        popup.close();
    };

    return true;
}

window.hcShowDesktopPopup = showDesktopPopup;

function maybeShowDesktopPopup(payload) {
    const optedIn = document.body.dataset.notificationPopups === 'on';

    if (optedIn && document.hidden && payload.priority !== 'info') {
        showDesktopPopup(payload);
    }
}

/* ------------------------------------------------------------------ */
/* Real-time subscription                                               */
/* ------------------------------------------------------------------ */

function startPolling() {
    if (pollTimer === null && pausedReason === null) {
        pollTimer = window.setInterval(refreshInbox, POLL_INTERVAL_MS);
    }
}

function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function pusherClient() {
    return window.Echo?.connector?.pusher ?? null;
}

/** Stop the socket and polling: nothing live reaches this tab until resumed. */
function pauseLiveUpdates(reason) {
    pausedReason = reason;
    stopPolling();
    pusherClient()?.disconnect();
}

/**
 * Back from an idle pause. Reconnecting the same Pusher client re-authorizes
 * the channel, so an expired session ends up paused as signed-out instead.
 */
function resumeLiveUpdates() {
    if (pausedReason !== 'idle') {
        return;
    }

    pausedReason = null;

    const pusher = pusherClient();

    if (pusher) {
        pusher.connect();
    } else {
        startPolling();
        refreshInbox();
    }
}

/** A sign-out happened for this user somewhere: re-check this tab's session. */
function recheckSession() {
    const pusher = pusherClient();

    if (pausedReason !== null || !pusher) {
        return;
    }

    pusher.disconnect();
    pusher.connect();
}

function sessionLifetimeMs() {
    const minutes = Number(document.body.dataset.sessionLifetime);

    return Number.isFinite(minutes) && minutes > 0 ? minutes * 60_000 : null;
}

function noteActivity() {
    lastActivityAt = Date.now();
    resumeLiveUpdates();
}

function subscribe() {
    const userId = document.body.dataset.userId;

    if (subscribed || !userId) {
        return;
    }

    subscribed = true;

    ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((type) => {
        window.addEventListener(type, noteActivity, { capture: true, passive: true });
    });

    const lifetimeMs = sessionLifetimeMs();

    if (lifetimeMs !== null) {
        window.setInterval(() => {
            if (pausedReason === null && Date.now() - lastActivityAt >= lifetimeMs) {
                pauseLiveUpdates('idle');
            }
        }, 60_000);
    }

    if (!window.Echo) {
        startPolling();

        return;
    }

    window.Echo.private(`App.Models.User.${userId}`)
        .listen('.notification.pushed', (payload) => {
            refreshInbox();
            window.dispatchEvent(new CustomEvent('hc-notification', { detail: payload }));
            maybeShowDesktopPopup(payload);
        })
        .listen('.session.ended', recheckSession)
        .error((error) => {
            if (AUTH_REFUSED_STATUSES.includes(error?.status)) {
                pauseLiveUpdates('signed-out');
            } else {
                startPolling();
            }
        });

    const connection = pusherClient()?.connection;

    connection?.bind('state_change', ({ current }) => {
        if (pausedReason !== null) {
            return;
        }

        if (current === 'connected') {
            stopPolling();
            refreshInbox();
        } else {
            startPolling();
        }
    });

    if (connection?.state !== 'connected') {
        startPolling();
    }
}

document.addEventListener('livewire:navigated', subscribe);

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        refreshInbox();
        noteActivity();
    }
});

/* ------------------------------------------------------------------ */
/* Alpine components                                                    */
/* ------------------------------------------------------------------ */

document.addEventListener('alpine:init', () => {
    /**
     * The Install HC Tracking dialog (x-notifications.install-modal). Install
     * hands over to the browser's own confirm and the dialog closes after its
     * answer, whichever it is. If the prompt goes away while the dialog is
     * open (installed from the address bar), there is nothing left to offer,
     * so it closes by itself.
     */
    window.Alpine.data('appInstallDialog', () => ({
        installing: false,

        async install() {
            if (this.installing) {
                return;
            }

            this.installing = true;

            try {
                await window.hcApp?.install();
            } finally {
                this.installing = false;
                this.$flux.modal('install-app').close();
            }
        },

        closeWhenGone(state) {
            if (state !== 'available' && !this.installing) {
                this.$flux.modal('install-app').close();
            }
        },
    }));

    /**
     * The bell's panel. Its rows are fetched the first time it opens
     * (loadPanel), focus moves into it on open and back to the bell when
     * Escape closes it.
     */
    window.Alpine.data('notificationBell', () => ({
        open: false,
        pulse: false,
        undo: null,
        undoTimer: null,

        init() {
            window.addEventListener('hc-notification', () => {
                this.pulse = false;
                this.$nextTick(() => {
                    this.pulse = true;
                    window.setTimeout(() => (this.pulse = false), 1200);
                });
            });
        },

        /**
         * A read or snooze made inside the open panel can be reversed from
         * its footer for a few seconds (the inbox page shows its own bar).
         */
        showUndo(detail) {
            if (!this.open || !detail?.ids?.length) {
                return;
            }

            window.clearTimeout(this.undoTimer);
            this.undo = { action: detail.action, ids: detail.ids, message: detail.message };
            this.undoTimer = window.setTimeout(() => (this.undo = null), 6000);
        },

        undoNow() {
            if (!this.undo) {
                return;
            }

            this.$wire.undoInbox(this.undo.action, this.undo.ids);
            window.clearTimeout(this.undoTimer);
            this.undo = null;
        },

        toggle() {
            if (this.open) {
                this.close(false);

                return;
            }

            this.open = true;

            if (!this.$wire.opened) {
                this.$wire.loadPanel();
            }

            this.$nextTick(() => this.$refs.panel?.focus({ preventScroll: true }));
        },

        close(returnFocus) {
            if (!this.open) {
                return;
            }

            this.open = false;

            if (returnFocus) {
                this.$refs.button?.focus();
            }
        },
    }));

    /**
     * The in-page toast stack. Important toasts leave after 6s (paused while
     * hovered or focused); critical ones stay until dismissed.
     */
    window.Alpine.data('notificationToasts', () => ({
        items: [],
        clock: Date.now(),

        init() {
            window.addEventListener('hc-notification', (event) => this.add(event.detail));

            // A critical toast can stay for a long time: keep its "time ago" true.
            window.setInterval(() => {
                if (this.items.length > 0) {
                    this.clock = Date.now();
                }
            }, 30_000);
        },

        /** "now" for the first minute, then "5 minutes ago" in the page language. */
        ago(item) {
            const minutes = Math.floor((this.clock - item.receivedAt) / 60_000);

            if (minutes < 1) {
                return labels().now ?? '';
            }

            const format = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' });

            return minutes < 60 ? format.format(-minutes, 'minute') : format.format(-Math.floor(minutes / 60), 'hour');
        },

        add(payload) {
            if (payload.priority === 'info' || this.items.some((item) => item.id === payload.id)) {
                return;
            }

            this.items.unshift({
                ...payload,
                sticky: payload.priority === 'critical',
                receivedAt: Date.now(),
                remaining: TOAST_DURATION_MS,
                paused: false,
                timer: null,
                startedAt: 0,
            });

            this.items.slice(MAX_TOASTS).forEach((item) => this.dismiss(item.id));

            const item = this.items[0];

            if (!item.sticky) {
                this.schedule(item);
            }
        },

        schedule(item) {
            item.startedAt = Date.now();
            item.timer = window.setTimeout(() => this.dismiss(item.id), item.remaining);
        },

        pause(item) {
            if (item.sticky || item.paused) {
                return;
            }

            window.clearTimeout(item.timer);
            item.remaining -= Date.now() - item.startedAt;
            item.paused = true;
        },

        resume(item) {
            if (item.sticky || !item.paused) {
                return;
            }

            item.paused = false;
            this.schedule(item);
        },

        dismiss(id) {
            const item = this.items.find((candidate) => candidate.id === id);

            if (item) {
                window.clearTimeout(item.timer);
            }

            this.items = this.items.filter((candidate) => candidate.id !== id);
        },

        open(item) {
            this.dismiss(item.id);
            navigateTo(item.url);
        },

        async read(item) {
            this.dismiss(item.id);
            await markRead(item.read_url);
        },
    }));
});
