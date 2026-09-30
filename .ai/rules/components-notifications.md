---
paths:
  - '{public/sw.js,public/manifest.webmanifest,resources/js/notifications.js,resources/views/components/notifications/toasts.blade.php}'
---

# Components Notifications

## HC Tracking installable app: service worker never caches; popups and toasts go through notifications.js
The site is an installable app ("HC Tracking", title bar in brand greys: #dcddde (secondary-soft) light / #1c1d20 (Graphite surface-1, = header) dark via two media-scoped theme-color metas in partials/head, and resources/js/theme-color.js re-points them at the Flux .dark class so a Light/Dark/System switch recolours the bar too, icons in public/icons generated from logo-tracking-icon.svg). public/sw.js only handles popup clicks: popups carry no buttons, and a click focuses an app window and posts hc-notification-open (or opens one). Popups are shown under a navigator.locks lock and remember the notification ids they hold (data.ids), so several open tabs never double-count one notification as "+1 more". It must never get a fetch handler or cache pages — staff must always see live data. notifications.js registers it, shows desktop popups via registration.showNotification (logo, Open/Mark as read, critical = requireInteraction), exposes window.hcApp (install prompt) and window.hcShowDesktopPopup (used by the settings page's Test popup), and drives the notificationToasts Alpine stack rendered by <x-notifications.toasts> (persisted in the app layout). Notification toasts no longer use Flux::toast.
