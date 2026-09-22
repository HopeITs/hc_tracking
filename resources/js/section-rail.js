/**
 * Keeps a sticky jump rail in step with the section the reader is looking at.
 *
 * The rail is server-rendered anchor links, so it navigates with JavaScript
 * off; this component only decides which link is marked current. Sections are
 * matched by the id on each anchor's href, and the topmost section still
 * intersecting the band below the sticky rail wins — so scrolling past a short
 * section does not leave the rail pointing at the one above it.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('sectionRail', (ids = []) => ({
        current: ids[0] ?? null,
        observer: null,

        init() {
            const sections = ids
                .map((id) => document.getElementById(id))
                .filter((section) => section !== null);

            if (sections.length === 0 || typeof IntersectionObserver === 'undefined') {
                return;
            }

            const visible = new Set();

            this.observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            visible.add(entry.target.id);
                        } else {
                            visible.delete(entry.target.id);
                        }
                    });

                    const first = ids.find((id) => visible.has(id));

                    if (first !== undefined) {
                        this.current = first;
                    }
                },
                // Ignore the band the rail itself covers, and the tail of the
                // viewport, so "current" means "being read", not "in view".
                { rootMargin: '-96px 0px -55% 0px', threshold: 0 }
            );

            sections.forEach((section) => this.observer.observe(section));
        },

        destroy() {
            this.observer?.disconnect();
        },
    }));
});
