import Echo from 'laravel-echo';

import Pusher from 'pusher-js';

/**
 * Real-time connection: hosted Pusher when its key is built in (production,
 * nothing to run on the server), otherwise a local Reverb server. Without
 * either key Echo is simply not started: the notification script then falls
 * back to polling, and nothing else on the page is affected.
 *
 * Channel authorization asks for JSON so a signed-out session gets a plain
 * 401/419 (which notifications.js treats as "stay disconnected") instead of
 * a redirect to the login page.
 */
const channelAuthorization = () => ({ headers: { Accept: 'application/json' } });
if (import.meta.env.VITE_PUSHER_APP_KEY) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
        forceTLS: true,
        auth: channelAuthorization(),
    });
} else if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        auth: channelAuthorization(),
    });
}
