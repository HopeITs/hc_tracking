/**
 * The shared modal shell (x-ui.modal).
 *
 * Flux keeps the <dialog>'s own attributes to itself, so the shell labels the
 * dialog with its title here. Every close control closes the native dialog,
 * which Flux and wire:close both observe, so one close action runs however
 * the user leaves. After a save comes back with errors it brings the first
 * invalid field into view and focuses it, and it counts file uploads in
 * flight so the submit actions stay disabled (`busy`) until every upload has
 * landed. Those listeners are attached here rather than written into the
 * markup, so a page that offers no file field carries no upload wiring.
 *
 * Step wizard: a form whose body holds three or more top-level
 * x-ui.form-section blocks shows one section per step. CSS hides the other
 * sections from the body's data-step (forms.css), so Livewire re-renders
 * never fight the wizard; Next checks the current step's native constraints
 * first, Enter moves to the next step instead of submitting early, and a
 * save that comes back with errors opens the step that holds the first one.
 */

const WizardMinimumSections = 3;
document.addEventListener('alpine:init', () => {
    window.Alpine.data('modalShell', (titleId, submitAction = null) => ({
        uploads: 0,
        stopWatchingSubmit: null,
        wizard: false,
        step: 1,
        total: 0,
        labels: [],
        invalid: [],
        observer: null,

        get busy() {
            return this.uploads > 0;
        },

        init() {
            this.dialog()?.setAttribute('aria-labelledby', titleId);

            this.$root.addEventListener('livewire-upload-start', () => this.uploadStarted());
            ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel'].forEach((eventName) => {
                this.$root.addEventListener(eventName, () => this.uploadEnded());
            });

            if (submitAction !== null) {
                this.stopWatchingSubmit = this.$wire.intercept(submitAction, ({ onFinish }) => {
                    onFinish(() => this.revealFirstError());
                });
            }

            // The body is behind the page's @if flag, so sections arrive with a later render: re-read them on every change.
            this.readSections();
            this.observer = new MutationObserver(() => this.readSections());
            this.observer.observe(this.$root, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-invalid'] });

            // A closed dialog starts at the first step next time it opens.
            this.dialog()?.addEventListener('close', () => {
                this.step = 1;
            });
        },

        destroy() {
            this.stopWatchingSubmit?.();
            this.observer?.disconnect();
        },

        sections() {
            const body = this.$root.querySelector('.modal-shell-body');

            if (! body) {
                return [];
            }

            return [...body.querySelectorAll('section.form-section')]
                .filter((section) => ! section.parentElement.closest('section.form-section'));
        },

        readSections() {
            const sections = this.sections();
            const wizard = sections.length >= WizardMinimumSections
                && sections.every((section) => section.parentElement === sections[0].parentElement);
            const labels = sections.map((section) => section.querySelector('.form-section-heading')?.textContent.trim() ?? '');
            const invalid = sections.map((section) => section.hasAttribute('data-invalid'));

            if (wizard !== this.wizard) {
                this.wizard = wizard;
            }

            if (sections.length !== this.total) {
                this.total = sections.length;
                this.step = Math.min(this.step, Math.max(1, this.total));
            }

            if (labels.join('|') !== this.labels.join('|')) {
                this.labels = labels;
            }

            if (invalid.join() !== this.invalid.join()) {
                this.invalid = invalid;
            }
        },

        currentSection() {
            return this.sections()[this.step - 1] ?? null;
        },

        go(step) {
            if (step > this.step && ! this.stepIsValid()) {
                return;
            }

            this.step = Math.min(Math.max(1, step), this.total);
            this.$nextTick(() => {
                this.$root.querySelector('.modal-shell-body')?.scrollTo({ top: 0 });
                this.currentSection()?.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus({ preventScroll: true });
            });
        },

        next() {
            this.go(this.step + 1);
        },

        back() {
            this.go(this.step - 1);
        },

        // Native constraints only (required, type, min/max); the server still validates the whole form on save.
        stepIsValid() {
            const fields = this.currentSection()?.querySelectorAll('input, select, textarea') ?? [];

            for (const field of fields) {
                if (field.disabled || field.closest('[hidden], [x-cloak]') || field.type === 'hidden') {
                    continue;
                }

                if (! field.checkValidity()) {
                    field.reportValidity();

                    return false;
                }
            }

            return true;
        },

        onEnter(event) {
            if (! this.wizard || this.step >= this.total || event.target.tagName === 'TEXTAREA' || event.target.tagName === 'BUTTON') {
                return;
            }

            event.preventDefault();
            this.next();
        },

        dialog() {
            return this.$root.closest('dialog');
        },

        close() {
            this.dialog()?.close();
        },

        uploadStarted() {
            this.uploads++;
        },

        uploadEnded() {
            this.uploads = Math.max(0, this.uploads - 1);
        },

        revealFirstError() {
            const field = this.$root.querySelector('[aria-invalid="true"]');

            if (! field) {
                return;
            }

            if (this.wizard) {
                const index = this.sections().findIndex((section) => section.contains(field));

                if (index !== -1 && index + 1 !== this.step) {
                    this.step = index + 1;
                    this.$nextTick(() => this.revealFirstError());

                    return;
                }
            }

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            field.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
            field.focus({ preventScroll: true });
        },
    }));
});
