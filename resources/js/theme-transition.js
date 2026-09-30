/**
 * Flips between Light and Dark appearance with a short cross-fade.
 *
 * The theme buttons (desktop top header and mobile drawer) call
 * window.hcToggleAppearance(), which switches to the opposite of what the
 * page currently shows. Someone who has never pressed the button stays on
 * Flux's default, 'system', and follows their device until the first press.
 *
 * Flux still owns the choice: $flux.appearance is its store, it keeps the
 * choice in localStorage ('flux.appearance') and it toggles .dark on <html>.
 * Flux does that from an Alpine effect, which runs in a later microtask rather
 * than when the store is written, so the class is also set here,
 * synchronously. The page is then already in its new state when
 * document.startViewTransition()'s callback returns, and Flux's effect finds
 * the class in place and only stores the choice.
 *
 * The cross-fade itself is the ::view-transition-old/new(root) block in
 * app.css. Browsers without view transitions and anyone who prefers reduced
 * motion switch instantly.
 */
const applyAppearance = (appearance) => {
    window.Flux.appearance = appearance;
    document.documentElement.classList.toggle('dark', appearance === 'dark');
};

const toggleAppearance = () => {
    if (!window.Flux) {
        return;
    }

    const appearance = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reducedMotion || typeof document.startViewTransition !== 'function') {
        applyAppearance(appearance);

        return;
    }

    document.startViewTransition(() => applyAppearance(appearance));
};

window.hcToggleAppearance = toggleAppearance;
