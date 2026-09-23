---
paths:
  - 'resources/views/pages/notifications/**'
---

# Notifications

## Notification settings live at /notifications-settings, not under /settings
User decision (2026-09-23): the /settings area is not used. Notification settings are the standalone page pages/notifications/⚡settings.blade.php at GET /notifications-settings (route name notification-settings.edit, in routes/web.php), with its own x-ui.page-header, no settings layout or settings nav entry. It is reached from the bell panel's gear icon. Add future notification preferences (module mutes) to this page.
