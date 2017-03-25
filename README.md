# XenForo2-AlertImprovements

A collection of improvements to the XenForo2 Alerts system.

Note; Do not use MySQL statement-based replication with this add-on.

## Features
- Automatically marks alerts as read from content on a given page when viewed for:
 - Threads
 - Conversations
 - Reports
- Mark as unread link for individual alerts

## Performance impact
- 1 extra query per thread/conversation/report page request when the user has more than zero active alerts.
- 1 extra query if any alerts are marked as read.
