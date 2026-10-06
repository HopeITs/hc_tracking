/**
 * Tells the task list which layout this screen needs.
 *
 * The list used to render every task twice, a table row and a mobile card,
 * and let a container query hide one: about 40% of the server's render time
 * went on markup the viewer never saw. The component now keeps `listLayout`
 * ('wide' or 'narrow', remembered in the session), and this reports the
 * width of the list so the server renders only the layout that fits. Until
 * the first report (`null`) it still renders both, so nothing is ever blank.
 *
 * 768px is the @3xl container width _list-group.blade.php switches at.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('listLayoutSync', () => ({
        observer: null,
        timer: null,

        init() {
            this.observer = new ResizeObserver(() => {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.report(), 150);
            });
            this.observer.observe(this.$root);
            this.report();
        },

        report() {
            const width = this.$root.clientWidth;

            if (width === 0) {
                return;
            }

            const layout = width >= 768 ? 'wide' : 'narrow';

            if (this.$wire.listLayout !== layout) {
                this.$wire.setListLayout(layout);
            }
        },

        destroy() {
            clearTimeout(this.timer);
            this.observer?.disconnect();
        },
    }));
});
