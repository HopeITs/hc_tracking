/**
 * The page scrolls inside [data-page-scroll] (layouts/app/sidebar), the area under the
 * top bar, so its scrollbar never runs beside the bar. Pages outside that shell
 * (sign-in, errors) still scroll the window.
 */
export function pageScroller() {
    return document.querySelector('[data-page-scroll]') ?? document.scrollingElement;
}
