/**
 * Every person dropdown (assignees, responsible, owner filters) lists the same
 * 270+ employees. Rather than the server rendering them into each dropdown on
 * every Livewire response, they are fetched once as JSON and shared: dropdowns
 * built with the `employees` prop draw their options from this store.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('employeeOptions', {
        items: [],
        requests: {},

        load(url) {
            this.requests[url] ??= fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : Promise.reject(response)))
                .then((items) => {
                    this.items = items;
                })
                .catch(() => {
                    delete this.requests[url];
                });

            return this.requests[url];
        },

        labelFor(value) {
            return this.items.find((item) => item.value === value)?.label ?? null;
        },
    });
});

/**
 * Draws the employee list into a menu container in one pass. Giving each of the
 * 270+ items its own Alpine bindings (x-for) cost ~150 ms to open a dropdown and
 * ~30 ms per keystroke while searching; plain DOM writes cost a few ms. The
 * container is `wire:ignore`d, so Livewire's morph leaves what is drawn here alone.
 */
window.hcEmployeeList = {
    escape(text) {
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    /** Draw the items once per list: `data-count` remembers what is already there. */
    render(container, items) {
        if (container.dataset.count === String(items.length)) {
            return;
        }

        const check = `<svg class="${this.escape(container.dataset.checkClass)}" style="display: none" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>`;
        const role = container.dataset.role ? ` role="${this.escape(container.dataset.role)}"` : '';

        container.innerHTML = items
            .map((item) => {
                const detail = item.detail ? `<span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">${this.escape(item.detail)}</span>` : '';
                const inactive = item.inactive ? ' data-inactive' : '';

                return `<button type="button"${role} class="${this.escape(container.dataset.itemClass)}" data-flux-menu-item data-option-value="${this.escape(item.value)}" data-option-label="${this.escape(item.label)}"${inactive}><span class="min-w-0 flex-1"><span class="block truncate${item.inactive ? ' opacity-60' : ''}">${this.escape(item.label)}</span>${detail}</span>${check}</button>`;
            })
            .join('');
        container.dataset.count = String(items.length);
    },

    /** Show the check mark on the selected items only. */
    mark(container, isSelected) {
        for (const button of container.children) {
            const selected = isSelected(button.dataset.optionValue);

            button.lastElementChild.style.display = selected ? '' : 'none';
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
        }
    },

    /** Hide (and disable, so arrow keys skip them) the items the search does not match. */
    filter(container, query) {
        const needle = query.trim().toLowerCase();

        for (const button of container.children) {
            const hit = needle === '' || button.dataset.optionLabel.toLowerCase().includes(needle);

            button.style.display = hit ? '' : 'none';
            button.disabled = ! hit;
        }
    },
};

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
    /**
     * The header filter dropdown (x-ui.header-filter-select). Person filters
     * start loading the employee list with the page and draw it on first open.
     */
    window.Alpine.data('filterMenu', () => ({
        optionSearch: '',

        init() {
            if (this.$root.dataset.employeesUrl) {
                window.Alpine.store('employeeOptions').load(this.$root.dataset.employeesUrl);
                this.$watch('optionSearch', () => this.paintEmployees());
            }
        },

        async openEmployees() {
            if (! this.$root.dataset.employeesUrl) {
                return;
            }

            await window.Alpine.store('employeeOptions').load(this.$root.dataset.employeesUrl);
            this.paintEmployees();
        },

        paintEmployees() {
            const list = this.$refs.employeeList;
            const current = String(this.$wire.$get(this.$root.dataset.model) ?? '');

            window.hcEmployeeList.render(list, window.Alpine.store('employeeOptions').items);
            window.hcEmployeeList.mark(list, (value) => value === current);
            window.hcEmployeeList.filter(list, this.optionSearch);
        },

        pickEmployee(event) {
            const button = event.target.closest('[data-option-value]');

            if (button !== null && ! button.disabled) {
                this.$wire.$set(this.$root.dataset.model, button.dataset.optionValue);
            }
        },
    }));

    window.Alpine.data('formPicker', ({ value = '', multiple = false, placeholder = '', countTemplate = '', employeesUrl = null, knownLabels = {} } = {}) => ({
        value,
        multiple,
        placeholder,
        countTemplate,
        employeesUrl,
        knownLabels,
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
            if (this.employeesUrl !== null) {
                const employeeLabel = window.Alpine.store('employeeOptions').labelFor(optionValue) ?? this.knownLabels[optionValue] ?? null;

                if (employeeLabel !== null) {
                    return employeeLabel;
                }
            }

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

        init() {
            if (this.employeesUrl !== null) {
                this.$watch('search', () => this.paintEmployees());
                this.$watch('value', () => this.paintEmployees());
            }
        },

        paintEmployees() {
            const list = this.$refs.employeeList;
            const items = window.Alpine.store('employeeOptions').items;

            if (! list || items.length === 0) {
                return;
            }

            window.hcEmployeeList.render(list, items);
            window.hcEmployeeList.mark(list, (optionValue) => this.isSelected(optionValue));
            window.hcEmployeeList.filter(list, this.search);
        },

        pickEmployee(event) {
            const button = event.target.closest('[data-option-value]');

            if (button !== null && ! button.disabled) {
                this.choose(button.dataset.optionValue);
            }
        },

        prepareMenu() {
            if (this.employeesUrl !== null) {
                window.Alpine.store('employeeOptions').load(this.employeesUrl).then(() => this.paintEmployees());
            }

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

/**
 * A checklist of employees bound to an array property (the calendar's
 * "filter by responsible" popover). It draws the same shared list as every
 * other person dropdown, in one pass, and toggles ids in the Livewire array.
 * The popover's own x-data owns the search text and whether it has been opened;
 * `x-effect="paint(optionSearch, touched)"` passes both in.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('employeeCheckList', () => ({
        selected() {
            return (this.$wire.$get(this.$root.dataset.model) ?? []).map(String);
        },

        async paint(search, touched) {
            if (! touched) {
                return;
            }

            const employees = window.Alpine.store('employeeOptions');

            await employees.load(this.$root.dataset.employeesUrl);

            const selected = this.selected();

            window.hcEmployeeList.render(this.$root, employees.items);
            window.hcEmployeeList.mark(this.$root, (value) => selected.includes(value));
            window.hcEmployeeList.filter(this.$root, search);
        },

        pick(event) {
            const button = event.target.closest('[data-option-value]');

            if (button === null || button.disabled) {
                return;
            }

            const value = button.dataset.optionValue;
            const current = this.selected();
            const next = current.includes(value) ? current.filter((id) => id !== value) : [...current, value];

            this.$wire.$set(this.$root.dataset.model, next);
            window.hcEmployeeList.mark(this.$root, (id) => next.includes(id));
        },
    }));
});
