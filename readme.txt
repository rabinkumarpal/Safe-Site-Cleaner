=== Safe Site Cleaner ===
Requires at least: 5.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Finds unused plugins, media, leftovers and database bloat, then proves the site still works.

== How it stays safe ==

1. Scanning changes nothing itself. It loads pages like a visitor, so plugins that log visits may
   record those loads.
2. An item counts as unused only if no database table, active code or rendered page points to it.
3. You choose what to clean. Nothing is preselected.
4. The database is backed up and test-restored before anything changes.
5. Files move to quarantine, outside the web folder when the host allows. Nothing is deleted.
6. Every page is checked again. If anything got worse, everything is rolled back automatically.
7. Permanent deletion needs a typed phrase. The database backup is kept unless you include it.

== Never touched ==

* Active plugins and the active theme, plus the newest default theme as a fallback
* All-in-One WP Migration backups and .wpress files
* wp-config.php, WordPress core
* Documents (PDFs and so on), and form upload folders
* Tables whose names suggest legal or business records, and tables with no known owner

== Limits ==

Please know what this does not do:

* It cannot test form submissions, email sending, checkout, or pages that need a login.
* Checks cover up to 3,000 posts and 50 terms per taxonomy. Others are still protected by the
  reference scan, but their rendered output is not compared.
* Restoring the database replaces everything added since the backup.
* Tables with foreign keys or triggers are not backed up into a swappable state. They are never
  changed or dropped by this plugin.
* The backup runs in steps, so very busy sites should clean during quiet hours.
* It is not a replacement for a full off-site backup. Take one first.
* Multisite is not supported.

== WP-CLI ==

wp ssc scan
wp ssc report
wp ssc apply --low-risk --yes
wp ssc runs
wp ssc restore <run> --yes
wp ssc purge <run> --phrase="DELETE NOW"
