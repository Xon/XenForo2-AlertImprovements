# XenForo2-AlertImprovements

A collection of improvements to the XenForo2 Alerts system.

Note; Do not use MySQL statement-based replication with this add-on.

## Features
- Automatically marks alerts as read from content on a given page when viewed for:
 - Threads
 - Conversations
 - Reports
- Mark as unread link for individual alerts
- Global Optional, Alert summerization by selected content type or user
- User Option to prevent marking as read when accessing /accounts/alerts page.
- User Option to prevent summerization when accessing /accounts/alerts page.
- User Option to adjust summerization threshold


## Supported content types for alert summerization 

- Posts Likes
- Post Ratings (From Post Ratings)
- Conversation Message Likes (From Conversation Improvements)
- Report Comment Likes (From Report Improvements)

## Performance impact

- Adds an extra column to xf_alert.
 - ``` alter table xf_user_alert summerize_id add int(10) unsigned DEFAULT NULL ```
- 1 extra query per thread/conversation/report page request when the user has more than zero active alerts.
  - 1 extra query if any alerts are marked as read.

## Alert Summerization Performance impact

- On accessing alerts above the summerize threshold, fetches all unread alerts and attempts to group them in PHP. 
- On successfully generating summary alerts, 2 queries are done. 1 insert to add the summary alert, 1 updating summerized alerts.