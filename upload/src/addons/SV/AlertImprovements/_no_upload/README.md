# XenForo2-AlertImprovements

A collection of improvements to the XenForo2 Alerts system.

Note; Do not use MySQL statement-based replication with this add-on.

## Features
- Per-alert 'mark read' links on each alert
- Multi-select ability to mark alerts as read or unread.
- Avoid unexpected marking alerts as read by browser prefetch, this may result in alerts not being marked as read as expected.
- Global option, enable/disable alert summarization
- User Option to prevent marking as read when accessing the alerts pop-up
- User Option to prevent summarization when accessing /accounts/alerts page.
- User Option to adjust summarization threshold
- Only mark alerts that are viewed on alert page/alert pop-up, not all alerts
  - If an alert was explicitly marked as unread, skip marking that alert as read.

## Supported content types for alert summarization 

- Posts, conversation, profile post, profile post comments reactions

## Performance impact

- Alert pop-up can fetch a number of unread reactions to attempt to summarize them