/**
 * Drives the logo preloader (<x-preloader>) through its data-state values:
 * boot → (waiting) → shown → leaving → hidden. See the "Logo preloader"
 * block in app.css for what each state looks like.
 *
 * First load: the element arrives in `boot`. CSS alone fades it in after
 * SHOW_DELAY_MS (quick mode) or plays the write-on intro (intro mode, chosen
 * by the inline script in the component). This waits for the page and fonts,
 * and for the intro to finish, then fades it out.
 *
 * Page switches: the element is @persist-ed, so the same node survives every
 * wire:navigate swap. It only appears when a switch takes longer than
 * SHOW_DELAY_MS, and once visible it stays at least MIN_VISIBLE_MS, so fast
 * switches never flicker.
 */
const SHOW_DELAY_MS = 200;
const MIN_VISIBLE_MS = 500;
const LEAVE_MS = 400;
const FAILSAFE_MS = 10000;
const INTRO_ANIMATIONS = ['hc-preloader-rise', 'hc-preloader-write'];

let showTimer = null;
let failsafeTimer = null;
let leaveTimer = null;
let visibleSince = null;
let navigating = false;

const findPreloader = () => document.querySelector('div[data-preloader]');

const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

const setState = (element, state) => {
    element.dataset.state = state;
    element.toggleAttribute('inert', state === 'hidden' || state === 'leaving');

    const label = element.querySelector('[data-preloader-label]');

    if (label) {
        label.textContent = state === 'hidden' ? '' : label.dataset.text;
    }
};

const clearTimers = () => {
    clearTimeout(showTimer);
    clearTimeout(failsafeTimer);
    clearTimeout(leaveTimer);
    showTimer = null;
    failsafeTimer = null;
    leaveTimer = null;
};

const hideNow = (element) => {
    clearTimers();
    visibleSince = null;
    setState(element, 'hidden');
};

/**
 * Fade out, but never before the loader has been on screen MIN_VISIBLE_MS.
 * A loader that never became visible is hidden without any animation.
 */
const leave = (element) => {
    clearTimers();

    if (visibleSince === null) {
        hideNow(element);

        return;
    }

    const remaining = Math.max(0, visibleSince + MIN_VISIBLE_MS - performance.now());

    setTimeout(() => {
        if (navigating) {
            return;
        }

        setState(element, 'shown');
        void element.offsetWidth;
        setState(element, 'leaving');
        visibleSince = null;

        leaveTimer = setTimeout(() => {
            if (element.dataset.state === 'leaving') {
                hideNow(element);
            }
        }, LEAVE_MS);
    }, remaining);
};

/**
 * When the boot loader became visible, on the performance.now() clock, or
 * null when it has not appeared yet. Intro mode is visible from the start.
 * Quick mode appears when its CSS fade-in starts, SHOW_DELAY_MS after the
 * animation began.
 */
const bootVisibleSince = (element) => {
    if (document.documentElement.dataset.preloader === 'intro') {
        return 0;
    }

    const appear = element
        .getAnimations()
        .find((animation) => animation.animationName === 'hc-preloader-appear');

    if (!appear || appear.startTime === null) {
        return null;
    }

    const since = appear.startTime + SHOW_DELAY_MS;

    return performance.now() >= since ? since : null;
};

const finishBoot = async (element) => {
    const domReady = document.readyState === 'loading'
        ? new Promise((resolve) => document.addEventListener('DOMContentLoaded', resolve, { once: true }))
        : Promise.resolve();

    let isReady = false;
    const ready = Promise.all([domReady, document.fonts?.ready ?? Promise.resolve()])
        .then(() => {
            isReady = true;
        });

    const intro = element
        .getAnimations({ subtree: true })
        .filter((animation) => INTRO_ANIMATIONS.includes(animation.animationName))
        .map((animation) => animation.finished);

    await Promise.allSettled(intro);

    if (element.dataset.state !== 'boot') {
        return;
    }

    if (intro.length > 0 && !isReady) {
        setState(element, 'waiting');
    }

    await Promise.race([ready, wait(FAILSAFE_MS)]);

    if (navigating || !['boot', 'waiting'].includes(element.dataset.state)) {
        return;
    }

    visibleSince = bootVisibleSince(element);
    leave(element);
};

document.addEventListener('livewire:navigate', (event) => {
    const element = findPreloader();

    if (!element) {
        return;
    }

    clearTimers();
    navigating = true;

    // Checked after the delay: another listener (e.g. the notification rules'
    // unsaved-changes confirm) may cancel the navigation after this one ran.
    showTimer = setTimeout(() => {
        if (event.defaultPrevented) {
            navigating = false;

            return;
        }

        if (element.dataset.state !== 'waiting') {
            visibleSince = performance.now();
            setState(element, 'waiting');
        }
    }, SHOW_DELAY_MS);

    failsafeTimer = setTimeout(() => {
        navigating = false;
        hideNow(element);
    }, FAILSAFE_MS);
});

document.addEventListener('livewire:navigated', () => {
    // Livewire also fires this on the initial page load; finishBoot owns that.
    if (!navigating) {
        return;
    }

    navigating = false;

    const element = findPreloader();

    if (element) {
        leave(element);
    }
});

window.addEventListener('pageshow', (event) => {
    const element = findPreloader();

    if (event.persisted && element) {
        navigating = false;
        hideNow(element);
    }
});

const bootElement = findPreloader();

if (bootElement?.dataset.state === 'boot') {
    finishBoot(bootElement);
}
