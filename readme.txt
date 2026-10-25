=== MM Critical Alerts ===
Contributors: meridianmedia
Tags: error, fatal, critical, alerts, logging
Requires at least: 5.8
Requires PHP: 7.2
Tested up to: 6.6
Stable tag: 1.1.0
License: GPLv2 or later

Sends an email alert when a fatal PHP error breaks a site, ignores background noise, and logs every fatal in wp-admin (Tools > Critical alerts).

== How it works ==
- Registers a shutdown handler and checks for a fatal (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR).
- Every fatal is logged with the request kind, likely culprit plugin/theme and the decision made.
- Request kinds: frontend, login, admin, ajax, rest (email immediately) and cron, router (MailPoet/Action Scheduler), xmlrpc, cli (background: email only if repeated).
- "Quiet plugins" (default: mailpoet, mailpoet-premium) are only emailed when they break a front-end or login page.
- Fatals during plugin/theme/core updates are logged, not emailed.
- Repeats of the same error are throttled. Memory/timeout errors are grouped even though the file/line differ each time.
- Mail goes via wp_mail; if that is unavailable (fatal during plugin load) or fails, falls back to PHP mail().

== Recommended install (important) ==
1. Install the plugin normally in wp-content/plugins/ and activate it.
2. Copy mu-plugin-loader/mm-critical-alerts-loader.php to wp-content/mu-plugins/.
   This arms alerts before any other plugin loads. Without it, a fatal in a plugin that loads earlier is not caught.
3. Tools > Critical alerts: use "Send full-path test alert" and confirm it arrives.

== Limits ==
- Cannot detect: database connection failures, server/PHP-FPM down, white screens with no PHP fatal, slow sites. Use an external uptime monitor with a keyword check for those.
- If the loader is installed the plugin keeps running even when deactivated in the plugins screen. Delete the loader file to fully remove it.

== Changelog ==
= 1.1.0 =
- Request-kind detection, quiet plugins, repeat rule for background requests, update-time suppression
- Stable error signatures (memory errors no longer defeat throttling)
- wp_mail fallback when fatal occurs before pluggable.php loads; PHP mail() fallback
- Log shows context, culprit, decision and mail status; warns about stuck/failed alerts
- Full-path test alert; MU loader; log pruning
