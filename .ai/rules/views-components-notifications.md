---
paths:
  - '{app/Notifications/**,resources/views/pages/notifications/**,resources/views/components/notifications/**}'
---

# Views Components Notifications

## Inbox search uses notifications.search_text; snoozed rows never absorb merges
notifications.search_text holds the rendered sentence in every Locale (NotificationPresenter::searchText), written by NotificationGrouper on create AND merge; inbox search queries it, never the data JSON (keys, param names, binary collation). The grouper never merges into a snoozed row (the event would be hidden until it wakes). The bell only queries its rows after loadPanel (first open); its panel is lg:fixed at the viewport end so the z-50 sidebar cannot cover it. Presenter dates use Locale::carbonLocale() (ar_SY month names). NotificationPushed is ShouldBroadcastNow (raised only from queued work); reverb client_options keep connect_timeout 2 / timeout 5.
