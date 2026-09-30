/**
 * Guided page tour: a spotlight that walks people through the shell (sidebar,
 * inbox, preferences, account) and whatever page-header controls the current
 * page actually has.
 *
 * Steps arrive from x-tour.overlay with translated copy and a list of CSS
 * selectors per step. The first selector that matches something visible wins,
 * so one step can point at the desktop control on a laptop and the mobile
 * one on a phone; a step with no visible target is dropped instead of
 * pointing at nothing. Any element can start the tour by dispatching the
 * window event `hc-tour-start`. The layout re-renders on wire:navigate, so
 * each page gets its own tour and a running one closes when you leave.
 */

const SEEN_KEY = 'hc-tour-seen';
const GAP = 14;
const PAD = 8;

const remember = () => {
    try {
        localStorage.setItem(SEEN_KEY, '1');
    } catch {
        // Private mode: the pulse on the trigger simply stays.
    }

    window.dispatchEvent(new CustomEvent('hc-tour-seen'));
};

const hasSeenTour = () => {
    try {
        return localStorage.getItem(SEEN_KEY) === '1';
    } catch {
        return false;
    }
};

const isVisible = (element) => {
    const rect = element.getBoundingClientRect();

    return (
        rect.width > 0 &&
        rect.height > 0 &&
        rect.right > 0 &&
        rect.bottom > 0 &&
        rect.left < window.innerWidth &&
        rect.top < window.innerHeight + 4000
    );
};

const resolveTargets = (selectors, union = false) => {
    for (const selector of selectors) {
        const found = [...document.querySelectorAll(selector)].filter(isVisible);

        if (found.length > 0) {
            return union ? found : [found[0]];
        }
    }

    return [];
};

/** Smallest rectangle that covers every element, so a step can spotlight a whole group. */
const boundingRect = (elements) => {
    const rects = elements.map((element) => element.getBoundingClientRect());
    const top = Math.min(...rects.map((rect) => rect.top));
    const left = Math.min(...rects.map((rect) => rect.left));
    const bottom = Math.max(...rects.map((rect) => rect.bottom));
    const right = Math.max(...rects.map((rect) => rect.right));

    return { top, left, bottom, right, width: right - left, height: bottom - top };
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcTour', (definitions = [], autostartUrl = null) => ({
        open: false,
        index: 0,
        steps: [],
        spot: { top: 0, left: 0, width: 0, height: 0, visible: false },
        card: { top: 0, left: 0, sheet: false },
        opener: null,
        frame: null,

        init() {
            if (autostartUrl === null) {
                return;
            }

            // Wait for the preloader and first paint so the spotlight lands on settled layout.
            setTimeout(() => {
                this.start();

                if (this.open) {
                    // Recorded on open, not on finish: leaving mid-tour must not bring it back at the next sign-in.
                    fetch(autostartUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                    }).catch(() => {});
                }
            }, 1200);
        },

        get step() {
            return this.steps[this.index] ?? null;
        },

        get isLast() {
            return this.index === this.steps.length - 1;
        },

        get isPhone() {
            return window.innerWidth < 640;
        },

        get body() {
            const step = this.step;

            if (step === null) {
                return '';
            }

            return this.isPhone && step.bodyMobile ? step.bodyMobile : step.body;
        },

        number(position) {
            return String(position + 1).padStart(2, '0');
        },

        start(event) {
            this.opener = event?.detail?.opener ?? document.activeElement;

            this.steps = definitions
                .map((definition) => ({
                    ...definition,
                    elements: resolveTargets(definition.targets, definition.union === true),
                }))
                .filter((definition) => definition.targets.length === 0 || definition.elements.length > 0);

            if (this.steps.length === 0) {
                return;
            }

            this.index = 0;
            this.open = true;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => this.place());
        },

        finish(completed = true) {
            if (!this.open) {
                return;
            }

            this.open = false;
            document.documentElement.classList.remove('overflow-hidden');

            if (completed) {
                remember();
            }

            this.$nextTick(() => this.opener?.focus?.({ preventScroll: true }));
        },

        next() {
            if (this.isLast) {
                this.finish();

                return;
            }

            this.go(this.index + 1);
        },

        back() {
            if (this.index > 0) {
                this.go(this.index - 1);
            }
        },

        go(index) {
            this.index = index;
            this.$nextTick(() => this.place());
        },

        keydown(event) {
            if (!this.open) {
                return;
            }

            const rtl = document.documentElement.dir === 'rtl';

            if (event.key === 'Escape') {
                event.preventDefault();
                this.finish(false);
            } else if (event.key === (rtl ? 'ArrowLeft' : 'ArrowRight')) {
                event.preventDefault();
                this.next();
            } else if (event.key === (rtl ? 'ArrowRight' : 'ArrowLeft')) {
                event.preventDefault();
                this.back();
            } else if (event.key === 'Tab') {
                this.trapFocus(event);
            }
        },

        trapFocus(event) {
            const focusable = [...this.$refs.card.querySelectorAll('button:not([disabled])')].filter(
                (element) => element.offsetParent !== null
            );

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        queuePlace() {
            if (!this.open || this.frame !== null) {
                return;
            }

            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                this.layout();
            });
        },

        place() {
            const step = this.step;

            if (step === null) {
                return;
            }

            // The element may have re-rendered since the tour started.
            if (step.targets.length > 0) {
                const fresh = resolveTargets(step.targets, step.union === true);

                step.elements = fresh.length > 0 ? fresh : step.elements;
                this.reveal(boundingRect(step.elements));
            }

            this.layout();
            this.$nextTick(() => this.$refs.primary?.focus({ preventScroll: true }));
        },

        reveal(rect) {
            const headerClearance = 84;
            const footerClearance = this.isPhone ? 260 : GAP + 24;

            if (rect.top < headerClearance || rect.bottom > window.innerHeight - footerClearance) {
                // Inside a sticky/fixed ancestor there is nothing to scroll.
                const scrollBy = rect.top - headerClearance - Math.max(0, (window.innerHeight - headerClearance - footerClearance - rect.height) / 3);

                window.scrollBy({
                    top: scrollBy,
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                });
            }
        },

        layout() {
            const step = this.step;
            const card = this.$refs.card;

            if (step === null || !card) {
                return;
            }

            const width = window.innerWidth;
            const height = window.innerHeight;
            const cardRect = card.getBoundingClientRect();
            const rtl = document.documentElement.dir === 'rtl';

            if (step.targets.length === 0 || !step.elements.every((element) => element.isConnected)) {
                this.spot = { ...this.spot, visible: false };
                this.card = {
                    sheet: this.isPhone,
                    top: Math.max(16, (height - cardRect.height) / 2),
                    left: Math.max(16, (width - cardRect.width) / 2),
                };

                return;
            }

            const rect = boundingRect(step.elements);
            const spot = {
                top: rect.top - PAD,
                left: rect.left - PAD,
                width: rect.width + PAD * 2,
                height: rect.height + PAD * 2,
                visible: true,
            };

            this.spot = spot;

            if (this.isPhone) {
                this.card = { sheet: true, top: 0, left: 0 };

                return;
            }

            const clampX = (value) => Math.min(Math.max(16, value), width - cardRect.width - 16);
            const clampY = (value) => Math.min(Math.max(16, value), height - cardRect.height - 16);
            const centredX = clampX(spot.left + spot.width / 2 - cardRect.width / 2);
            const centredY = clampY(spot.top + spot.height / 2 - cardRect.height / 2);

            const room = {
                bottom: height - (spot.top + spot.height) - GAP,
                top: spot.top - GAP,
                end: rtl ? spot.left - GAP : width - (spot.left + spot.width) - GAP,
                start: rtl ? width - (spot.left + spot.width) - GAP : spot.left - GAP,
            };

            const tall = spot.height > height * 0.5;
            const order = tall ? ['end', 'start', 'bottom', 'top'] : ['bottom', 'top', 'end', 'start'];
            const fits = (side) => (side === 'bottom' || side === 'top' ? room[side] >= cardRect.height : room[side] >= cardRect.width);
            const side = order.find(fits) ?? 'bottom';
            const endLeft = rtl ? spot.left - GAP - cardRect.width : spot.left + spot.width + GAP;
            const startLeft = rtl ? spot.left + spot.width + GAP : spot.left - GAP - cardRect.width;

            this.card = {
                sheet: false,
                top:
                    side === 'bottom'
                        ? clampY(spot.top + spot.height + GAP)
                        : side === 'top'
                          ? clampY(spot.top - GAP - cardRect.height)
                          : centredY,
                left: side === 'end' ? clampX(endLeft) : side === 'start' ? clampX(startLeft) : centredX,
            };
        },

        cardStyle() {
            if (this.card.sheet) {
                return '';
            }

            return `top:${Math.round(this.card.top)}px;left:${Math.round(this.card.left)}px`;
        },

        spotStyle() {
            const { top, left, width, height, visible } = this.spot;

            return `top:${Math.round(top)}px;left:${Math.round(left)}px;width:${Math.round(width)}px;height:${Math.round(height)}px;opacity:${visible ? 1 : 0}`;
        },
    }));

    window.Alpine.data('hcTourTrigger', () => ({
        fresh: !hasSeenTour(),

        begin() {
            window.dispatchEvent(new CustomEvent('hc-tour-start', { detail: { opener: this.$el } }));
        },
    }));
});

document.addEventListener('livewire:navigate', () => {
    document.documentElement.classList.remove('overflow-hidden');
});
