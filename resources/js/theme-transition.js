/**
 * Switches between Light, Dark and System appearance with a short cross-fade.
 *
 * The appearance menus (desktop top header and mobile drawer) call
 * window.hcSetAppearance(mode). Flux still owns the choice: $flux.appearance
 * is its store, it keeps the choice in localStorage ('flux.appearance') and it
 * toggles .dark on <html>. Flux does that from an Alpine effect, which runs in
 * a later microtask rather than when the store is written, so the class is
 * also set here, synchronously and by the same rule Flux uses. The page is
 * then already in its new state when document.startViewTransition()'s callback
 * returns, and Flux's effect finds the class in place and only stores the
 * choice.
 *
 * The cross-fade itself is the ::view-transition-old/new(root) block in
 * app.css. Browsers without view transitions and anyone who prefers reduced
 * motion switch instantly, as does a choice that keeps the current colours
 * (System while the OS already matches).
 */
const APPEARANCES = ['light', 'dark', 'system'];

const resolvesToDark = (appearance) =>
    appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

const applyAppearance = (appearance) => {
    window.Flux.appearance = appearance;
    document.documentElement.classList.toggle('dark', resolvesToDark(appearance));
};

const setAppearance = (appearance) => {
    if (!APPEARANCES.includes(appearance) || !window.Flux) {
        return;
    }

    const changesColours = resolvesToDark(appearance) !== document.documentElement.classList.contains('dark');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!changesColours || reducedMotion || typeof document.startViewTransition !== 'function') {
        applyAppearance(appearance);

        return;
    }

    document.startViewTransition(() => applyAppearance(appearance));
};

window.hcSetAppearance = setAppearance;
