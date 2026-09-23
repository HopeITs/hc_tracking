/**
 * The real-time half of the inbox.
 *
 * One private-channel subscription per page load (the bell renders twice —
 * mobile and desktop header — so subscribing from the component would double
 * every toast). A push refreshes every inbox view through a Livewire event,
 * shows a toast (important: 6s, critical: sticky) and, when the tab is hidden
 * and the user opted in, an OS popup. While the socket is not connected the
 * inbox is polled every 60s; returning to the tab always re-syncs.
 */
const POLL_INTERVAL_MS = 60_000;

let subscribed = false;
let pollTimer = null;

function refreshInbox() {
    window.Livewire?.dispatch('notification-received');
}

function showToast(payload) {
    if (payload.priority === 'info') {
        return;
    }

    const critical = payload.priority === 'critical';

    window.Flux?.toast({
        heading: payload.module_label,
        text: payload.text,
        variant: critical ? 'danger' : 'warning',
        duration: critical ? 0 : 6000,
    });
}

function showPopup(payload) {
    const optedIn = document.body.dataset.notificationPopups === 'on';

    if (!optedIn || !document.hidden || payload.priority === 'info') {
        return;
    }

    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    const popup = new Notification(payload.module_label, { body: payload.text, tag: payload.id });

    popup.onclick = () => {
        window.focus();
        window.location.assign(payload.url);
        popup.close();
    };
}

function startPolling() {
    if (pollTimer === null) {
        pollTimer = window.setInterval(refreshInbox, POLL_INTERVAL_MS);
    }
}

function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function subscribe() {
    const userId = document.body.dataset.userId;

    if (subscribed || !userId) {
        return;
    }

    subscribed = true;

    if (!window.Echo) {
        startPolling();

        return;
    }

    window.Echo.private(`App.Models.User.${userId}`).listen('.notification.pushed', (payload) => {
        refreshInbox();
        window.dispatchEvent(new CustomEvent('hc-notification', { detail: payload }));
        showToast(payload);
        showPopup(payload);
    });

    const connection = window.Echo.connector?.pusher?.connection;

    connection?.bind('state_change', ({ current }) => {
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
    }
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('notificationBell', () => ({
        open: false,
        pulse: false,

        init() {
            window.addEventListener('hc-notification', () => {
                this.pulse = true;
                window.setTimeout(() => (this.pulse = false), 1200);
            });
        },
    }));
});
