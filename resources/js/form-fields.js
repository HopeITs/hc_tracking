/**
 * Form dropdowns (x-ui.form-select, x-ui.form-multiselect).
 *
 * The menu is Flux's own ui-dropdown + ui-menu, so it looks and positions
 * exactly like the header filter picker: it opens in the top layer above an
 * open dialog, flips near the viewport edge and moves focus with ↑/↓. This
 * adds what a form field needs on top of it:
 *
 * - `value` is x-modelable, so `wire:model` binds it (deferred by default,
 *   `.live` for dependent fields) and server-side changes flow back in.
 * - Labels are read from the rendered menu items, so a list the server
 *   re-renders (a dependent field) never leaves a stale label behind.
 * - Flux's type-ahead listens on the whole menu and steals Latin letters
 *   typed into the search box; those keystrokes stop at the box instead, and
 *   typing anywhere in an open searchable menu lands in it.
 * - ↓ from the search box enters the list, and Enter there picks the first
 *   match instead of submitting the surrounding form. Filtered-out items are
 *   disabled as well as hidden so Flux's arrow keys skip them.
 * - Inside a modal <dialog> an unhandled Esc closes the dialog too, so Esc
 *   with the menu open closes only the menu and returns focus to the trigger.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('formPicker', ({ value = '', multiple = false, placeholder = '', countTemplate = '' } = {}) => ({
        value,
        multiple,
        placeholder,
        countTemplate,
        search: '',

        normalize(item) {
            return item === null || item === undefined ? '' : String(item);
        },

        selectedValues() {
            if (! this.multiple) {
                return [this.normalize(this.value)];
            }

            return Array.isArray(this.value) ? this.value.map((item) => this.normalize(item)) : [];
        },

        isSelected(optionValue) {
            return this.selectedValues().includes(optionValue);
        },

        labelFor(optionValue) {
            const option = Array.from(this.$root.querySelectorAll('[data-option-value]'))
                .find((element) => element.dataset.optionValue === optionValue);

            return option ? option.dataset.optionLabel : null;
        },

        get triggerLabel() {
            const labels = this.selectedValues()
                .map((optionValue) => this.labelFor(optionValue))
                .filter((label) => label !== null);

            if (labels.length === 0) {
                return null;
            }

            if (! this.multiple || labels.length <= 2) {
                return labels.join(', ');
            }

            return this.countTemplate.replace('__COUNT__', labels.length.toLocaleString());
        },

        matches(element) {
            const query = this.search.trim().toLowerCase();

            return query === '' || element.textContent.toLowerCase().includes(query);
        },

        choose(optionValue) {
            if (this.multiple) {
                const current = this.selectedValues();

                this.value = current.includes(optionValue)
                    ? current.filter((item) => item !== optionValue)
                    : [...current, optionValue];

                return;
            }

            this.value = optionValue;
            this.closeMenu();
        },

        prepareMenu() {
            this.search = '';
            this.$refs.menu.style.minWidth = `${this.$refs.trigger.offsetWidth}px`;
        },

        menuIsOpen() {
            return this.$refs.menu.matches(':popover-open');
        },

        closeMenu() {
            if (this.menuIsOpen()) {
                this.$refs.menu.hidePopover();
            }

            this.$refs.trigger.focus();
        },

        focusSearch() {
            this.$refs.search?.focus();
        },

        firstOption() {
            return Array.from(this.$refs.menu.querySelectorAll('[data-option-value]'))
                .find((option) => ! option.disabled);
        },

        onKeydown(event) {
            if (! this.menuIsOpen()) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.closeMenu();

                return;
            }

            const search = this.$refs.search;

            if (! search) {
                return;
            }

            if (event.target === search) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    event.stopPropagation();
                    this.firstOption()?.focus();
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    event.stopPropagation();
                    this.firstOption()?.click();
                } else if (event.key.length === 1) {
                    event.stopPropagation();
                }

                return;
            }

            if (/^\S$/u.test(event.key) && ! event.ctrlKey && ! event.metaKey && ! event.altKey) {
                event.stopPropagation();
                search.focus();
            }
        },
    }));

    /**
     * One-click segments (x-ui.form-segmented): a radiogroup whose value binds
     * through x-modelable. Arrow keys move and choose; left and right swap in
     * right-to-left pages so "next" always points forward in reading order.
     */
    window.Alpine.data('formSegmented', (value = '', values = []) => ({
        value,
        values,

        current() {
            return this.value === null || this.value === undefined ? '' : String(this.value);
        },

        isChecked(optionValue) {
            return this.current() === optionValue;
        },

        tabStop() {
            return this.values.includes(this.current()) ? this.current() : (this.values[0] ?? '');
        },

        step(event, delta) {
            const mirrored = getComputedStyle(this.$root).direction === 'rtl' && ['ArrowLeft', 'ArrowRight'].includes(event.key);
            const direction = mirrored ? -delta : delta;
            const index = Math.max(0, this.values.indexOf(this.tabStop()));
            const next = this.values[(index + direction + this.values.length) % this.values.length];

            this.value = next;
            this.$nextTick(() => this.$root.querySelector(`[data-option-value="${CSS.escape(next)}"]`)?.focus());
        },
    }));

    /**
     * The file field (x-ui.form-file): the chosen name, live progress from
     * Livewire's upload events, drop support and remove.
     */
    window.Alpine.data('formFile', (name = null, model = '', sizeTemplate = '') => ({
        name,
        uploading: false,
        progress: 0,
        loaded: 0,
        total: 0,
        dragging: false,

        picked(event) {
            this.name = event.target.files?.[0]?.name ?? null;
        },

        dropped(event) {
            this.dragging = false;

            const files = event.dataTransfer?.files;

            if (! files || files.length === 0) {
                return;
            }

            this.$refs.input.files = files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        started() {
            this.uploading = true;
            this.progress = 0;
        },

        progressed(detail) {
            this.progress = detail.progress ?? 0;
            this.loaded = detail.loaded ?? 0;
            this.total = detail.total ?? 0;
        },

        get sizeLabel() {
            if (! this.total) {
                return `${this.progress}%`;
            }

            const megabytes = (bytes) => (bytes / 1048576).toFixed(1);

            return sizeTemplate.replace('__LOADED__', megabytes(this.loaded)).replace('__TOTAL__', megabytes(this.total));
        },

        cleared() {
            this.uploading = false;
            this.name = null;
            this.$refs.input.value = '';
        },

        remove() {
            if (this.uploading) {
                this.$wire.$cancelUpload(model);
            } else {
                this.$wire.$set(model, null);
            }

            this.cleared();
        },
    }));
});
