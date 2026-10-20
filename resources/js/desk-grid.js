/**
 * Dragging Desk panels by their grip (components/desk/panel). The dragged
 * panel dims (.dk-dragging), the panel under the pointer is outlined
 * (.dk-drop), and dropping asks the Desk page (pages::desk.index) to move the
 * panel to that panel's place, which saves the new layout.
 *
 * Delegated from document so panels Livewire re-renders keep working.
 */

let dragged = null;

function panelOf(target) {
    return target instanceof Element ? target.closest('[data-desk-grid] > .dk-panel') : null;
}

function clearDrop() {
    document.querySelectorAll('[data-desk-grid] .dk-drop').forEach((panel) => panel.classList.remove('dk-drop'));
}

document.addEventListener('dragstart', (event) => {
    const grip = event.target instanceof Element ? event.target.closest('[data-drag]') : null;

    if (! grip) {
        return;
    }

    dragged = panelOf(grip);

    if (! dragged) {
        return;
    }

    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', grip.dataset.drag);
    event.dataTransfer.setDragImage(dragged, 24, 24);
    dragged.classList.add('dk-dragging');
});

document.addEventListener('dragover', (event) => {
    const panel = dragged ? panelOf(event.target) : null;

    if (! panel) {
        return;
    }

    event.preventDefault();

    if (panel !== dragged && ! panel.classList.contains('dk-drop')) {
        clearDrop();
        panel.classList.add('dk-drop');
    }
});

document.addEventListener('drop', (event) => {
    const panel = dragged ? panelOf(event.target) : null;

    if (! panel || panel === dragged) {
        return;
    }

    event.preventDefault();

    const grid = panel.closest('[data-desk-grid]');
    const desk = grid?.closest('[wire\\:id]');

    if (desk) {
        window.Livewire.find(desk.getAttribute('wire:id'))?.movePanel(dragged.dataset.panel, Number(panel.dataset.idx));
    }
});

document.addEventListener('dragend', () => {
    dragged?.classList.remove('dk-dragging');
    dragged = null;
    clearDrop();
});
