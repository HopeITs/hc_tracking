---
paths:
  - 'resources/js/**'
---

# Js

## notifications.js owns the only Echo subscription
resources/js/notifications.js subscribes once per page load to private App.Models.User.{id} (.notification.pushed), shows toasts/OS popups, polls every 60s only while the socket is down, and re-syncs on tab focus. Components never subscribe themselves: they listen to the Livewire event `notification-received`. echo.js only starts Echo when VITE_REVERB_APP_KEY is set, so a build without Reverb keys degrades to polling instead of breaking app.js.
