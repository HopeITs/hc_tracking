/**
 * Animates a figure counting up from zero on first reveal.
 *
 * Reduced-motion visitors and anyone whose JS runs after the paint still see
 * the final value immediately — the count is a flourish, never the only way
 * the number reaches the page (the element's own text content is the target).
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('countUp', (target, options = {}) => ({
        display: 0,

        init() {
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (reducedMotion || typeof target !== 'number' || Number.isNaN(target)) {
                this.display = target;

                return;
            }

            const duration = options.duration ?? 900;
            const start = performance.now();
            const from = 0;

            const tick = (now) => {
                const elapsed = Math.min(1, (now - start) / duration);
                // Expo-out: fast start, long settle — matches the page's own reveal curve.
                const eased = elapsed === 1 ? 1 : 1 - Math.pow(2, -10 * elapsed);

                this.display = Math.round(from + (target - from) * eased);

                if (elapsed < 1) {
                    requestAnimationFrame(tick);
                }
            };

            requestAnimationFrame(tick);
        },
    }));
});
