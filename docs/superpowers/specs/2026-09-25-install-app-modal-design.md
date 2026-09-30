# Install HC Tracking — header icon and install modal

Date: 2026-09-25 · Branch: `youssef-interfaces-edits` · Changes stay uncommitted for review.

## Goal

The header's "Install HC Tracking" button becomes an icon with a tooltip, and installing goes through an HC Tracking modal instead of jumping straight to the browser's own dialog.

## Constraint

The browser's "Install app" dialog is drawn by Chrome/Edge and cannot be restyled or replaced by a page. The modal therefore comes first; its Install button calls `beforeinstallprompt.prompt()`, which still shows the browser's small native confirm. The address-bar install icon keeps opening the browser dialog directly.

## Decisions (made with the user, mockup screen "install-modal-directions", option A)

1. **Header button:** icon only (`arrow-down-tray`), same 36px ghost square as the bell beside it (44px below `lg`), Flux tooltip "Install HC Tracking desktop app" below it, the same text as its accessible name. Shown only while the browser holds an install prompt (unchanged). A click opens the modal.
2. **Modal (direction A, dot-thread):** `x-ui.modal size="sm" name="install-app"`, the shell every dialog uses.
   - Header: the app icon, "Install HC Tracking", "The desktop app for this computer", close X.
   - Body: three reasons on the dot thread (first dot filled primary, the rest hollow): its own window · notifications you will notice · always live.
   - Footer: ⓘ "Your browser will ask you to confirm next" (browser, not Chrome: Edge shows the same confirm), then "Not now" and a primary "Install" with the download icon.
3. **Behaviour:** Install is disabled while the browser's confirm is open; the modal closes after either answer. Not now, X, Esc and a backdrop click only close it; nothing is remembered. If the prompt goes away while the modal is open (installed from the address bar), the modal closes itself.
4. **Notification settings page:** its "Install HC Tracking" button opens the same modal.

## Units

| Unit | Role |
| --- | --- |
| `components/notifications/install-button.blade.php` | Header icon + tooltip; opens the modal with `$flux.modal('install-app').show()`. |
| `components/notifications/install-modal.blade.php` (new) | The modal; rendered once in `layouts/app/sidebar.blade.php` for signed-in users. |
| `appInstallDialog` Alpine component in `resources/js/notifications.js` | Install (hands over to `window.hcApp.install()`), busy state, closes after the answer or when the prompt disappears. |
| `.install-thread` block in `resources/css/app.css` | The dot thread; tokens only, dark mode, logical properties for RTL. |
| `lang/{en,ar}/notifications.php` → `app.*` | Tooltip, description, Not now, confirm note, three reasons. |

## Testing

`tests/Feature/Notifications/DesktopAppTest.php`:
- both headers render an icon-only install button (no visible text) whose accessible name and tooltip are the tooltip string, still right before the bell;
- signed-in pages render the install modal exactly once with the three reasons and the confirm note, and a click on either header icon opens it by name;
- the settings page's install button opens the same modal.

`tests/Feature/LocalizationTest.php` covers the new keys in both languages. Real-browser check in light/dark, EN/AR.
