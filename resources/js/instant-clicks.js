/**
 * Instant tabs and segmented buttons (user decision 2026-11-10, "every click
 * like thunder"). A Livewire tab (role="tab") or one-of-many pressed button
 * (aria-pressed in a group where only one is pressed) shows as chosen the
 * moment it is clicked; the server's re-render then confirms the choice, or
 * puts the old one back if it was refused. Styles that read aria-selected or
 * aria-pressed follow at once.
 */
const isWired = (element) => [...element.attributes].some((attribute) => attribute.name.startsWith('wire:click'));

document.addEventListener(
    'click',
    (event) => {
        if (event.button !== 0 || !(event.target instanceof Element)) {
            return;
        }

        const tab = event.target.closest('[role="tab"]');

        if (tab && isWired(tab) && !tab.disabled) {
            const list = tab.closest('[role="tablist"]') ?? tab.parentElement;

            list.querySelectorAll('[role="tab"]').forEach((other) => other.setAttribute('aria-selected', String(other === tab)));

            return;
        }

        const pressed = event.target.closest('button[aria-pressed]');

        if (!pressed || !isWired(pressed) || pressed.disabled || pressed.getAttribute('aria-pressed') === 'true') {
            return;
        }

        const group = [...pressed.parentElement.children].filter((sibling) => sibling.matches('button[aria-pressed]'));

        // Only a one-of-many group: independent toggles (several pressed at once) are left to the server.
        if (group.filter((sibling) => sibling.getAttribute('aria-pressed') === 'true').length === 1) {
            group.forEach((sibling) => sibling.setAttribute('aria-pressed', String(sibling === pressed)));
        }
    },
    true,
);
