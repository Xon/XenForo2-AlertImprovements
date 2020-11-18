# XenForo2-AlertImprovements

A collection of improvements to the XenForo2 Alerts system.

Note; Do not use MySQL statement-based replication with this add-on.

## Features
- Automatically marks alerts as read from content on a given page when viewed for:
  - Threads
  - Conversations
  - Reports
- Mark as unread link for individual alerts
- Global Optional, Alert summarization by selected content type or user
- User Option to prevent marking as read when accessing /accounts/alerts page.
- User Option to prevent summarization when accessing /accounts/alerts page.
- User Option to adjust summarization threshold


## Supported content types for alert summarization 

- Posts Reactions
- Conversation Message Reactions
- Report Comment Reactions

## Performance impact

- Adds an extra column to xf_alert.
  - ``` alter table xf_user_alert summerize_id add int(10) unsigned DEFAULT NULL ```
- 1 extra query per thread/conversation/report page request when the user has more than zero active alerts.
  - 1 additional extra query if any alerts are marked as read.

## Alert Summarization Performance impact

- On accessing alerts above the summerize threshold, fetches all unread alerts and attempts to group them in PHP. 
- On successfully generating summary alerts, 2 queries are done. 
  - insert to add the summary alert
  - updating summerized alerts.