---
paths:
  - 'app/Notifications/Recipients/**'
---

# Recipients

## One recipient map per module, registered in config
Each notification module has one ModuleRecipients subclass, registered in config/notifications.php `modules`. Module knowledge (direct people, watcher roles, key ★ events, priority, permission exemptions) lives only there, never in RecipientResolver. Test fixtures (users included) are created inside Activity::withoutRecording, since creating a User records user.created and notifies that user.
