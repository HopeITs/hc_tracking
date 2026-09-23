/**
 * HC Tracking service worker.
 *
 * It makes the site installable as the "HC Tracking" app and owns its desktop
 * notifications: shown with the app's name and logo, "Open" and "Mark as
 * read" buttons, and clicks that bring the app window forward on the record.
 *
 * It deliberately caches nothing and has no fetch handler: every page is
 * always fetched live, so nobody ever sees stale data.
 */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

/** Every open HC Tracking window, most recently focused first. */
async function appWindows() {
    return self.clients.matchAll({ type: 'window', includeUncontrolled: true });
}

/** Tell every open window something happened, e.g. so its inbox re-syncs. */
async function broadcast(message) {
    (await appWindows()).forEach((client) => client.postMessage(message));
}

async function markRead(data) {
    if (!data.readUrl) {
        return;
    }

    await fetch(data.readUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': data.csrf ?? '', Accept: 'application/json' },
    });

    await broadcast({ type: 'hc-notification-read' });
}

/** Focus an existing window and send it to the record, or open a new one. */
async function openRecord(url) {
    const [client] = await appWindows();

    if (client) {
        await client.focus();
        client.postMessage({ type: 'hc-notification-open', url });

        return;
    }

    await self.clients.openWindow(url);
}

self.addEventListener('notificationclick', (event) => {
    const data = event.notification.data ?? {};

    event.notification.close();

    event.waitUntil(event.action === 'read' ? markRead(data) : openRecord(data.url ?? '/notifications'));
});
