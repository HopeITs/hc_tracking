---
paths:
  - 'resources/js/**'
---

# Js

## notifications.js owns the only Echo subscription
resources/js/notifications.js subscribes once per page load to private App.Models.User.{id} (.notification.pushed), shows toasts/OS popups, polls every 60s only while the socket is down, and re-syncs on tab focus. Components never subscribe themselves: they listen to the Livewire event `notification-received`. echo.js only starts Echo when VITE_REVERB_APP_KEY is set, so a build without Reverb keys degrades to polling instead of breaking app.js.

## A page in a Desk tab never subscribes
A page opened in a Desk tab (an iframe on My desk, App\Support\Desk\DeskTab) is rendered without `data-user-id` on `<body>`, so notifications.js does not start a second Echo subscription there: the Desk around it already holds the one subscription, and its toasts cover the tab. Do not add the attribute back for tab pages.
