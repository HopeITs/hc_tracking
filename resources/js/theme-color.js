/**
 * Keeps the installed app's title bar the same colour as the top header.
 *
 * The head renders one theme-color meta per scheme, scoped by
 * prefers-color-scheme, so the bar is right before any script runs. Flux can
 * force light or dark regardless of the OS setting, though, so this follows
 * the .dark class on <html> instead: the matching meta is switched on for every
 * media and the other one off. Livewire navigation can swap the head, so the
 * choice is re-applied after each navigation.
 */
const applyThemeColor = () => {
    const isDark = document.documentElement.classList.contains('dark');

    document.querySelectorAll('meta[data-theme-color]').forEach((meta) => {
        const isActive = (meta.dataset.themeColor === 'dark') === isDark;

        meta.setAttribute('media', isActive ? 'all' : 'not all');
    });
};

new MutationObserver(applyThemeColor).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
});

document.addEventListener('livewire:navigated', applyThemeColor);

applyThemeColor();
