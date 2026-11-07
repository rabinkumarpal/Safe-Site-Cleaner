=== Safe Site Cleaner ===
Requires at least: 5.9
Requires PHP: 7.4
Stable tag: 1.7.0
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

== Check a page ==

The Speed tab shows a page's mobile and desktop score from Google PageSpeed Insights, what each plugin
adds to the page, and every CSS and JavaScript file with its size and how it loads. Checking changes
nothing. Sending a page address to Google is how the score is measured, so the site must be public
to get one. A free Google API key is optional and avoids the shared quota.

== Videos and embeds ==

Speed rules can show a click-to-load preview instead of YouTube, Vimeo and Google Maps embeds,
lazy-load embedded frames, stop click-to-play videos downloading early, and show the poster
image instead of background videos on phones. Add the class ssc-keep to leave one alone.

== Where a rule applies ==

Each rule can be limited to page types, address patterns such as /services/*, phones or desktop,
and logged-out or logged-in visitors. Phone or desktop rules need a page cache that keeps
separate copies for phones.

== Speed rules ==

The Speed tab also holds rules you switch on and off: preload fonts, stop the main image lazy-loading,
defer stylesheets, delay scripts, and drop assets for unused SureForms Pro features. Rules are saved
per site and can be copied to another site as JSON. A page check suggests fixes. Each one is tested on
up to 3 real pages through signed preview links that visitors never see, then goes live as a trial
and undoes itself if a page gets worse. These checks cannot send a form, so please try your forms.

== Other speed plugins ==

LiteSpeed Cache, WP Rocket, Autoptimize and Perfmatters are detected. Fixes they already do are not
suggested, and overlapping rules show a warning. Files they build are listed under their names.
LiteSpeed Cache, WP Rocket and WP Super Cache are cleared automatically after a change. Clear any
other cache yourself.

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
