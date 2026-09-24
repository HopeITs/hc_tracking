/**
 * HC Tracking service worker.
 *
 * It makes the site installable as the "HC Tracking" app and owns its desktop
 * notifications (shown by resources/js/notifications.js with the app's name
 * and banner artwork). Popups carry no buttons: a click brings an app window
 * forward on the record, or on the inbox when several were piled up.
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

    event.waitUntil(openRecord(data.url ?? '/notifications'));
});
