/**
 * "Add to desk" (App\Http\Controllers\ToggleDeskItemController): put a page
 * or a page card on My desk from wherever the person is, or take it off; a
 * button or a "New …" action goes in or out of Quick access instead. window.hcDeskItems is the one way in:
 *  - toggle({ item | route | url, placed? }) asks the server, then tells the
 *    Desk to read itself again (on My desk directly; from a Desk tab through
 *    the Desk around it, resources/js/desk-tabs.js);
 *  - place(route) puts a page on the Desk from the sidebar's "+": on My desk
 *    through the Desk itself ($wire.addRoute), so it lands with its Undo;
 *  - placeItem(id) does the same for one item by id (the Ask bar).
 *
 * hcAddToDesk is the button in a page's header (x-desk.add-to-desk).
 */

import { hostOf } from './desk-grid';

async function send(payload) {
    const endpoint = document.querySelector('meta[name="desk-items-url"]')?.content;

    if (! endpoint) {
        throw new Error('Add to desk is not available here.');
    }

    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(payload),
    });

    if (! response.ok) {
        throw new Error(String(response.status));
    }

    const result = await response.json();

    window.dispatchEvent(new CustomEvent('dk-desk-changed', { detail: result }));

    try {
        if (window.top !== window.self && window.parent.location.origin === window.location.origin) {
            window.parent.postMessage({ hcTab: 'desk-changed' }, window.location.origin);
        }
    } catch {
        // Not inside the Desk: nothing to tell.
    }

    return result;
}

async function place(route) {
    const grid = document.querySelector('[data-desk-grid]');
    const host = grid ? hostOf(grid) : null;

    return host ? host.addRoute(route) : send({ route, placed: true });
}

/** Put one item (panel, page card or button) on the Desk: on My desk through the Desk itself, elsewhere through the server. */
async function placeItem(item) {
    const grid = document.querySelector('[data-desk-grid]');
    const host = grid ? hostOf(grid) : null;

    if (! host) {
        return send({ item, placed: true });
    }

    const added = await host.addItem(item);

    // The Desk drew itself again with the item (addItem); nothing else needs telling.
    if (added === false) {
        throw new Error('Not placed.');
    }

    return { item, placed: true };
}

window.hcDeskItems = { toggle: send, place, placeItem };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcAddToDesk', (config) => ({
        placed: config.placed,
        open: false,
        busy: false,
        failed: false,
        said: '',

        isOn(id) {
            return this.placed.includes(id);
        },

        async set(id, on = ! this.isOn(id)) {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.failed = false;

            try {
                const result = await send({ item: id, placed: on });

                this.placed = result.placed ? [...new Set([...this.placed, result.item])] : this.placed.filter((each) => each !== result.item);
                const said = result.quick ? [config.texts.quick_added, config.texts.quick_removed] : [config.texts.added, config.texts.removed];

                this.said = (result.placed ? said[0] : said[1]).replace('__I__', result.title);
            } catch {
                this.failed = true;
                this.said = config.texts.failed;
            } finally {
                this.busy = false;
            }
        },
    }));
});
