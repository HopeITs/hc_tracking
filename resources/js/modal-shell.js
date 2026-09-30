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
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('modalShell', (titleId, submitAction = null) => ({
        uploads: 0,
        stopWatchingSubmit: null,

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
        },

        destroy() {
            this.stopWatchingSubmit?.();
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

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            field.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
            field.focus({ preventScroll: true });
        },
    }));
});
