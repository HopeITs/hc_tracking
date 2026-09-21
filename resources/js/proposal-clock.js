/**
 * The day scrubber on a proposal's chronology
 * (resources/views/pages/proposals/⚡show.blade.php).
 *
 * The chronology itself is server-rendered — real anchors carrying real dates
 * and figures — and the readout beside it already states today's position in
 * the markup. This component only adds what the browser can do on top: drag or
 * arrow along the grant's scale and have every figure answer for that day
 * instead, so "had the money landed before that work was due?" is read off the
 * page rather than worked out from it.
 *
 * Day offsets arrive unclamped on purpose: a project that began before the
 * scale is still running on its first day, and the readout has to say so.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('proposalClock', (payload) => {
        /**
         * The server sends only the lanes the page actually drew, so every
         * optional one defaults to empty here. Reading `money[0]` off a payload
         * without it threw before the component ever mounted, which took the
         * whole scrubber down with it.
         */
        const clock = {
            days: 0,
            today: 0,
            start: null,
            locale: 'en-GB',
            money: [],
            projects: [],
            ...payload,
        };

        return {
            clock,
            currency: clock.money[0]?.currency ?? null,
            scrubbing: false,
            cursor: clock.today,
            rtl: false,

            init() {
                this.rtl = getComputedStyle(this.$el).direction === 'rtl';
            },

            get line() {
                return clock.money.find((line) => line.currency === this.currency) ?? clock.money[0] ?? null;
            },

            get percent() {
                return clock.days > 0 ? (this.day / clock.days) * 100 : 0;
            },

            /** The day every figure answers for: the cursor while scrubbing, else today. */
            get day() {
                return this.scrubbing ? this.cursor : clock.today;
            },

            /**
             * The grant as it stood on that day.
             *
             * Figures are formatted here rather than server-side because the day
             * is chosen in the browser; the locale and its digit system come
             * from the server, so a scrubbed number matches every other tabular
             * number on the page.
             */
            get readout() {
                const day = this.day;
                const line = this.line;
                const number = new Intl.NumberFormat(clock.locale, { maximumFractionDigits: 0 });

                const received = (line?.transfers ?? [])
                    .filter((transfer) => transfer.received && transfer.at !== null && transfer.at <= day)
                    .reduce((total, transfer) => total + transfer.amount, 0);

                const running = clock.projects.filter(
                    (project) => (project.from === null || project.from <= day) && (project.to === null || project.to >= day),
                ).length;

                const date = new Date(`${clock.start}T00:00:00`);
                date.setDate(date.getDate() + day);

                return {
                    date: date.toLocaleDateString(clock.locale, { year: 'numeric', month: 'short', day: 'numeric' }),
                    received: number.format(received),
                    owed: number.format(Math.max(0, (line?.committed ?? 0) - received)),
                    running: `${running}/${clock.projects.length}`,
                };
            },

            /** Pointer x within the spine, as a day on the scale; mirrored in RTL. */
            dayAt(event) {
                const box = this.$refs.spine.getBoundingClientRect();

                if (box.width === 0) {
                    return this.cursor;
                }

                const ratio = (event.clientX - box.left) / box.width;

                return Math.round(Math.min(1, Math.max(0, this.rtl ? 1 - ratio : ratio)) * clock.days);
            },

            scrubStart(event) {
                this.$refs.spine.setPointerCapture?.(event.pointerId);
                this.scrubbing = true;
                this.cursor = this.dayAt(event);
            },

            scrubMove(event) {
                if (this.scrubbing) {
                    this.cursor = this.dayAt(event);
                }
            },

            scrubEnd(event) {
                this.$refs.spine.releasePointerCapture?.(event.pointerId);
                this.scrubbing = false;
            },

            /**
             * Arrow keys walk the scale a day at a time, a week with Shift, and
             * Escape hands the readout back to today. In RTL the scale is
             * mirrored, so the arrow that points forward in time is too.
             */
            scrubKey(event) {
                if (event.key === 'Escape') {
                    this.scrubbing = false;
                    this.cursor = clock.today;

                    return;
                }

                const step = event.shiftKey ? 7 : 1;
                const forward = this.rtl ? 'ArrowLeft' : 'ArrowRight';
                const back = this.rtl ? 'ArrowRight' : 'ArrowLeft';

                const moves = {
                    [forward]: () => this.day + step,
                    [back]: () => this.day - step,
                    ArrowUp: () => this.day + step,
                    ArrowDown: () => this.day - step,
                    Home: () => 0,
                    End: () => clock.days,
                };

                if (moves[event.key] === undefined) {
                    return;
                }

                event.preventDefault();
                this.cursor = Math.min(clock.days, Math.max(0, moves[event.key]()));
                this.scrubbing = true;
            },
        };
    });
});
