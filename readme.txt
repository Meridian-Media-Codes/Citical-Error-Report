=== MM Critical Alerts ===
Contributors: meridianmedia
Tags: error, fatal, critical, alerts, logging
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later

Sends an immediate email alert when a fatal/critical PHP error occurs, and logs the error in wp-admin (Tools > Critical alerts).

== How it works ==
- Registers a shutdown handler.
- When a fatal error is detected (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR):
  - Logs it to a custom DB table
  - Emails the configured address (with throttling per unique error)

== Settings ==
Tools > Critical alerts
- Recipient email
- Subject prefix
- Throttle minutes
- Hosting error logs URL (paste your 20i error log link)
- Include request details
- Include user ID
- Only alert on front-end
- Ignore CLI
- Ignore WP-Cron
