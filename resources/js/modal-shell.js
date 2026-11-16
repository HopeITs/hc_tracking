/**
 * The shared modal shell (x-ui.modal).
 *
 * Flux keeps the <dialog>'s own attributes to itself, so the shell labels the
 * dialog with its title here. Every close control closes the native dialog,
 * which Flux and wire:close both observe, so one close action runs however
 * the user leaves. After a save comes back with errors it brings the first
 * invalid field into view and focuses it, and the footer counts the fields
 * that need attention (a button that jumps back to the first one). It counts
 * file uploads in flight so the submit actions stay disabled (`busy`) until
 * every upload has landed. Those listeners are attached here rather than
 * written into the markup, so a page that offers no file field carries no
 * upload wiring.
 *
 * A dialog never steps (user decision 2026-11-16): every section is on
 * screen and Save stays in the footer. A dialog with five or more sections
 * (`indexed`, decided by x-ui.modal) also gets the section index: entries
 * read from the sections' headings, the current one follows the scroll, a
 * click jumps there, a section ticks once its required fields are filled and
 * shows its error count after a failed save. Enter does not save an indexed
 * form from a plain text field (a long create form saved half-filled by
 * accident); Ctrl/Cmd+Enter does.
 */

// Single-line fields where Enter would otherwise submit the whole form.
const PlainInputTypes = ['text', 'number', 'date', 'email', 'tel', 'url', 'search', 'datetime-local', 'month', 'time'];


document.addEventListener('alpine:init', () => {
    /*
     * An optional x-ui.form-section starts folded behind its "Add …" button. It
     * opens by itself when it already holds text (an edit) or comes back with an
     * error. Values arrive through wire:model after this init, so look a tick later.
     */
    window.Alpine.data('formSectionFold', () => ({
        open: false,

        init() {
            this.open = this.$el.hasAttribute('data-invalid');
            this.$nextTick(() => {
                if (this.hasContent()) {
                    this.open = true;
                }
            });
        },

        hasContent() {
            return [...this.$el.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), textarea')]
                .some((control) => String(control.value ?? '').trim() !== '');
        },

        unfold() {
            this.open = true;
            this.$nextTick(() => this.$el.querySelector('.form-section-fields :is(input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled]))')?.focus());
        },
    }));

    window.Alpine.data('modalShell', (titleId, submitAction = null, errorLabels = null, indexed = false) => ({
        uploads: 0,
        discardAsked: false,
        stopWatchingSubmit: null,
        errorCount: 0,
        observer: null,
        entries: [],
        current: 0,
        filled: 0,
        required: 0,
        holdCurrentUntil: 0,

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

            // The body is behind the page's @if flag, so fields arrive with a later render: re-read on every change.
            this.readErrors();
            this.readSections();
            this.observer = new MutationObserver(() => {
                this.readErrors();
                this.readSections();
            });
            this.observer.observe(this.$root, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-invalid', 'data-invalid', 'data-invalid-count', 'data-error-total'] });

            ['input', 'change'].forEach((eventName) => this.$root.addEventListener(eventName, () => this.readSections()));
            // Pickers and segments answer on click and repaint a tick later.
            this.$root.addEventListener('click', () => setTimeout(() => this.readSections(), 0));

            if (indexed) {
                // Scroll does not bubble; a capturing listener on the frame hears the body's own scroll.
                this.$root.addEventListener('scroll', (event) => {
                    if (event.target === this.body()) {
                        this.pickCurrent();
                    }
                }, { capture: true, passive: true });
            }

            this.dialog()?.addEventListener('close', () => {
                this.discardAsked = false;
            });

            // Esc is the dialog's own cancel: hold it back once when there is unsaved work.
            this.dialog()?.addEventListener('cancel', (event) => {
                if (this.isDirty() && ! this.discardAsked) {
                    event.preventDefault();
                    this.discardAsked = true;
                }
            });
        },

        destroy() {
            this.stopWatchingSubmit?.();
            this.observer?.disconnect();
        },

        body() {
            return this.$root.querySelector('.modal-shell-body');
        },

        // Sections know their failed fields from the server (pickers carry no aria-invalid); plain forms count the fields.
        readErrors() {
            const body = this.body();
            const fieldCount = body?.querySelectorAll('[aria-invalid="true"]').length ?? 0;
            const sectionCount = [...(body?.querySelectorAll('section.form-section[data-invalid-count]') ?? [])]
                .reduce((sum, section) => sum + Number(section.dataset.invalidCount || 0), 0);
            const serverCount = body?.querySelector('[data-form-field]') ? Number(this.$root.querySelector('.modal-shell-footer')?.dataset.errorTotal || 0) : 0;
            const count = Math.max(fieldCount, sectionCount, serverCount);

            if (count !== this.errorCount) {
                this.errorCount = count;
            }
        },

        // One template per plural band (1, 2, 3-10, 11+), so Arabic reads right too.
        errorLabel() {
            const line = this.errorLine(this.errorCount);

            return errorLabels?.action ? `${line} — ${errorLabels.action}` : line;
        },

        sections() {
            const body = this.body();

            if (! body) {
                return [];
            }

            return [...body.querySelectorAll('section.form-section')]
                .filter((section) => ! section.parentElement.closest('section.form-section'));
        },

        /*
         * The card's live status: its chip text and data-done. Written straight to
         * the DOM (the chip is wire:ignore), and only when it changes, so the
         * MutationObserver that calls this settles after one pass.
         */
        paintSection(section, { errors, complete, left, required }) {
            const labels = errorLabels?.section ?? {};
            const chip = section.querySelector(':scope > .form-section-card [data-section-state]');
            let text = '';
            let tone = '';

            if (errors > 0) {
                text = String(labels.toFix ?? ':count').replace(':count', errors);
                tone = 'is-invalid';
            } else if (complete) {
                text = labels.done ?? '';
                tone = 'is-done';
            } else if (required > 0) {
                text = String(labels.left ?? ':count').replace(':count', left);
                tone = 'is-required';
            }

            if (chip && chip.textContent !== text) {
                chip.textContent = text;
            }

            if (chip && chip.dataset.tone !== tone) {
                chip.dataset.tone = tone;
            }

            if (complete && errors === 0) {
                section.hasAttribute('data-done') || section.setAttribute('data-done', '');
            } else if (section.hasAttribute('data-done')) {
                section.removeAttribute('data-done');
            }

            // A failed field never hides behind a fold.
            if (errors > 0) {
                this.unfoldSection(section, false);
            }
        },

        unfoldSection(section, focus = true) {
            const fold = section.hasAttribute('data-optional') ? window.Alpine.$data(section) : null;

            if (fold && ! fold.open) {
                focus ? fold.unfold() : (fold.open = true);
            }
        },

        /*
         * Whether a required field holds an answer, read the way each field shows it:
         * a picker (x-ui.form-select) drops its placeholder class once something is
         * chosen, segments and checkbox lists check an option, plain controls hold a value.
         */
        isRequiredFilled(field) {
            if (field.matches('input, select, textarea')) {
                return field.type === 'checkbox' || field.type === 'radio' ? field.checked : String(field.value ?? '').trim() !== '';
            }

            const trigger = field.querySelector('[data-trigger-label]');

            if (trigger) {
                return ! trigger.classList.contains('form-select-placeholder') && ! trigger.classList.contains('form-chip-placeholder');
            }

            if (field.querySelector('[role="radio"]')) {
                return field.querySelector('[role="radio"][aria-checked="true"]') !== null;
            }

            const choices = [...field.querySelectorAll('input[type="checkbox"], input[type="radio"]')];

            if (choices.length > 0) {
                return choices.some((choice) => choice.checked);
            }

            return [...field.querySelectorAll('input:not([type="hidden"]):not([type="search"]), select, textarea')]
                .some((control) => String(control.value ?? '').trim() !== '');
        },

        // Visible required fields: every x-ui.form-* field whose label carries the red star, plus bare required controls.
        requiredControls(section) {
            const fields = [...section.querySelectorAll('[data-form-field]')]
                .filter((field) => field.querySelector(':scope > label .form-required') && field.offsetParent !== null);
            const bare = [...section.querySelectorAll('input[required], select[required], textarea[required]')]
                .filter((control) => ! control.closest('[data-form-field]') && control.type !== 'hidden' && ! control.disabled && control.offsetParent !== null);

            return [...fields, ...bare];
        },

        readSections() {
            const sections = this.sections();

            if (sections.length === 0) {
                return;
            }

            let filled = 0;
            let required = 0;

            const entries = sections.map((section) => {
                const controls = [...new Set(this.requiredControls(section))];
                const done = controls.filter((control) => this.isRequiredFilled(control)).length;

                const errors = Number(section.dataset.invalidCount ?? 0);
                const complete = controls.length > 0 && done === controls.length;

                filled += done;
                required += controls.length;
                this.paintSection(section, { errors, complete, left: controls.length - done, required: controls.length });

                return {
                    label: (section.querySelector('.form-section-title') ?? section.querySelector('.form-section-heading'))?.textContent.trim() ?? '',
                    optional: section.hasAttribute('data-optional'),
                    errors,
                    done: complete,
                };
            });

            // Reassign only on a real change, so Alpine does not re-render the lists on every keystroke.
            if (JSON.stringify(entries) !== JSON.stringify(this.entries)) {
                this.entries = entries;
                this.current = Math.min(this.current, Math.max(0, entries.length - 1));
            }

            if (filled !== this.filled) {
                this.filled = filled;
            }

            if (required !== this.required) {
                this.required = required;
            }
        },

        // The current entry is the last section whose top has passed the upper third of the body; the last one wins at the bottom.
        pickCurrent() {
            const body = this.body();
            const sections = this.sections();

            // A click in the index owns the highlight while its scroll settles, even near the end of the form.
            if (! body || sections.length === 0 || Date.now() < this.holdCurrentUntil) {
                return;
            }

            const line = body.getBoundingClientRect().top + body.clientHeight / 3;
            let index = 0;

            sections.forEach((section, i) => {
                if (section.getBoundingClientRect().top <= line) {
                    index = i;
                }
            });

            if (body.scrollTop > 0 && body.scrollTop + body.clientHeight >= body.scrollHeight - 4) {
                index = sections.length - 1;
            }

            if (index !== this.current) {
                this.current = index;
                this.$nextTick(() => this.$root.querySelector('.modal-shell-chip.is-current')?.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
            }
        },

        jump(index) {
            const body = this.body();
            const section = this.sections()[index];

            if (! body || ! section) {
                return;
            }

            this.unfoldSection(section, false);

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const top = section.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop - 4;

            this.holdCurrentUntil = Date.now() + 900;
            body.scrollTo({ top, behavior: reduceMotion ? 'auto' : 'smooth' });
            this.current = index;
            section.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])')?.focus({ preventScroll: true });
        },

        onEnter(event) {
            if (event.ctrlKey || event.metaKey) {
                if (this.$root.tagName === 'FORM' && event.target.tagName !== 'BUTTON') {
                    event.preventDefault();
                    this.$root.requestSubmit();
                }

                return;
            }

            if (! indexed) {
                return;
            }

            const target = event.target;
            const isPlainInput = target.tagName === 'INPUT' && PlainInputTypes.includes(target.type);
            const isInsidePicker = target.closest('[role="combobox"], [role="listbox"], [data-flux-select], ui-select, ui-combobox') !== null
                || target.getAttribute('role') === 'combobox';

            if (isPlainInput && ! isInsidePicker) {
                event.preventDefault();
            }
        },

        errorLine(count) {
            const bands = errorLabels?.bands ?? {};
            const band = count === 1 ? 1 : count === 2 ? 2 : count <= 10 ? 3 : 11;

            return String(bands[band] ?? count).replace(':count', count);
        },

        // The progress ring's circumference is 2πr with r = 18 (x-ui.modal).
        ringOffset() {
            const circumference = 113.1;

            return circumference - circumference * (this.required ? this.filled / this.required : 0);
        },

        progressLine() {
            return String(errorLabels?.progress ?? ':filled / :total').replace(':filled', this.filled).replace(':total', this.required);
        },

        dialog() {
            return this.$root.closest('dialog');
        },

        // Opt-in guard (x-ui.modal discard-guard): the form's current Livewire values against the ones it opened with.
        isDirty() {
            let guard = null;

            try {
                guard = JSON.parse(this.$root.dataset.discardGuard ?? 'null');
            } catch {
                return false;
            }

            if (! guard) {
                return false;
            }

            return guard.fields.some((field) => String(this.$wire.$get(field) ?? '') !== String(guard.original?.[field] ?? ''));
        },

        // X, Cancel and Esc ask first when the form was changed; "Discard" closes for real.
        close() {
            if (this.isDirty() && ! this.discardAsked) {
                this.discardAsked = true;

                return;
            }

            this.discard();
        },

        keepEditing() {
            this.discardAsked = false;
        },

        discard() {
            this.discardAsked = false;
            this.dialog()?.close();
        },

        uploadStarted() {
            this.uploads++;
        },

        uploadEnded() {
            this.uploads = Math.max(0, this.uploads - 1);
        },

        // The first failed field, or the first failed section when its field is a picker without aria-invalid.
        revealFirstError() {
            const body = this.body();
            const field = body?.querySelector('[aria-invalid="true"]') ?? this.$root.querySelector('[aria-invalid="true"]');
            const section = body?.querySelector('section.form-section[data-invalid]');
            const firstIsSection = section && (! field || (section.compareDocumentPosition(field) & Node.DOCUMENT_POSITION_FOLLOWING) && ! section.contains(field));
            const target = firstIsSection ? section : field;

            if (! target) {
                return;
            }

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            target.scrollIntoView({ block: firstIsSection ? 'start' : 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
            (firstIsSection ? section.querySelector('button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])') : field)?.focus({ preventScroll: true });
        },
    }));
});
