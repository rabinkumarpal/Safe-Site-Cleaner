# Safe Site Cleaner

A WordPress plugin that finds unused plugins, media, leftovers and database bloat, then checks your real pages before and after cleaning. Files go to quarantine instead of being deleted, and if a page gets worse, the cleanup rolls itself back.

> **Status: beta.** See [Testing status](#testing-status) for what has and hasn't been tested. Please don't run it on a live site without testing it on a staging copy first.

## Disclaimer

**Use this plugin at your own risk.**

- It changes your files and your database. It reduces the risk of a cleanup, but it cannot remove that risk.
- **Take your own full, off-site backup first.** The plugin's backup is stored on the same server, so it won't help if that server fails.
- **Try it on a staging copy before using it on a live site.**
- Its checks can't see everything. See [What it can't check](#what-it-cant-check).
- You decide what gets cleaned, so please review each item, especially those marked "review".
- This software is provided "as is", without warranty of any kind, under the [GPL-2.0-or-later](LICENSE) license. The authors are not liable for data loss, downtime, lost revenue or any other damage from using it.
- It is not affiliated with WordPress, Automattic, or any plugin or host named in its rules.

## How it protects your site

| Step | What happens |
|---|---|
| Scan | Reads every database table, active code and real pages. It changes nothing itself. |
| Judge | An item counts as unused only if no table, code or rendered page points to it. |
| Choose | Nothing is preselected. You pick what to clean. |
| Back up | Backs up the database, then test-restores the backup into temporary tables before relying on it. |
| Clean | Moves files to quarantine, outside the web folder when the host allows. |
| Verify | Re-checks every page and file against the earlier results. |
| Roll back | If anything got worse, it puts all files and the database back automatically. |
| Delete | Permanent deletion is a separate step that needs a typed phrase. |

### What it never touches

- Active plugins, the active theme and its parent, and the newest default theme as a fallback
- All-in-One WP Migration backups, `.wpress` files, and migration or backup plugin zips
- `wp-config.php` and WordPress core
- Documents such as PDFs, and known form upload folders
- Files changed in the last 7 days
- Tables with no known owner, or whose names suggest legal or business records (consent, payments, orders, customers)

### Renamed files

If a rebrand changed image names in the database but not on disk, the images look unused while pages show them as broken. The plugin spots these pairs, keeps the files, and offers to repair them by copying each file to the expected name.

## Requirements

- WordPress 5.9 or newer, single site only (multisite is not supported)
- PHP 7.4 or newer, using the `mysqli` database connection
- A server that can load its own pages (cleaning is disabled if it can't)
- A database user that can create and drop tables
- Free disk space of at least twice the database size

## Installation

1. Download this repository as a ZIP and rename the top folder inside it to `safe-site-cleaner`.
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload the ZIP.
3. Activate the plugin.

Or copy the folder to `wp-content/plugins/safe-site-cleaner` and activate it.

## How to use it

### 1. Scan

Go to **Tools > Safe Site Cleaner**, choose how many revisions to keep per post, and click **Scan site**. Large sites can take several minutes. If the page closes, come back and the job continues.

### 2. Review

Results are grouped, with the reason shown for each item:

- **Low risk:** nothing points to it.
- **Review:** it looks unused, but needs your judgement. For example, an inactive plugin may hold tracking codes your live site needs.
- **Kept on purpose:** it looked unused, but something points to it or it's off limits.
- **Already broken:** files that were failing before any cleaning.

### 3. Clean

Select items, or use **Select all low-risk**, then click **Clean selected** and **Back up and clean**. Keep the page open until it finishes. The result is one of:

- **Cleaned and verified:** everything matches or improves on the earlier check.
- **Rolled back automatically:** a problem was found and listed, and your site was put back.
- **Stopped:** a safety check failed before anything changed.

### 4. Check your site yourself

Test what the plugin can't: forms, emails, checkout, logged-in pages and your page builder.

### 5. Restore or delete

Under **Past cleanups**, click **Restore or delete**:

- **Put files back only** leaves the database as it is.
- **Restore files and database** needs you to type `RESTORE`. It replaces the database with the backup, so **anything added since the cleanup is lost**, including orders and form entries.
- Restoring the database also restores login sessions, so if you signed in after the backup, you'll need to sign in again.
- **Delete permanently** needs you to type `DELETE`, or `DELETE NOW` within 24 hours of the cleanup. This frees the disk space and **cannot be undone**. The database backup is kept unless you choose to delete it too.

## Check a page

The **Speed** tab shows how fast a page is and what is slowing it down. Checking changes nothing on your site, and it adds no code to your front end.

- **Speed score:** mobile and desktop scores, lab measures and suggestions from Google PageSpeed Insights, plus real-visitor numbers when Google has them. Google has to be able to reach the site, so local sites and sites behind a login get no score.
- **Page weight:** files, blocking files, CSS, JavaScript, third-party files, HTML size and uncached server response.
- **What each plugin adds:** the size of the files each plugin, theme or other site adds to the page.
- **File list:** each CSS and JavaScript file, who it belongs to, how it loads and how big it is.

File sizes are measured on disk before compression, so they are higher than what visitors download. A `~` means the server reported the size instead, and third-party files are usually measured that way.

Without an API key, tests share Google's public quota and can be refused. A [free key](https://developers.google.com/speed/docs/insights/v5/get-started) gives you your own: paste it in the **Settings** tab, where you can also test it. If the plugin wrongly decides Google can't reach your site, use the `ssc_speed_psi_reachable` filter.

## Speed rules

The **Speed** tab also holds rules that change how pages load for visitors. Each rule has its own switch, and turning one off undoes it right away. Rules are saved per site, so copy them to another site as JSON.

| Type | What it does | Fields |
|---|---|---|
| `preload_font` | Fetches fonts early | `files`: `.woff` or `.woff2` paths inside uploads |
| `eager_first_image` | Stops a page builder lazy-loading the image WordPress marks as highest priority | None |
| `defer_style` | Loads stylesheets without holding up the first paint | `handles`, optional `except_pages` (page IDs) |
| `delay_script` | Loads scripts on the visitor's first scroll, pointer, touch, key or focus | `handles` |
| `recipe` | Drops assets a plugin loads for features the site doesn't use | `recipe`: `sureforms_unused` |

```json
[
  { "type": "delay_script", "label": "Delay captcha", "handles": ["hcaptcha"], "enabled": true }
]
```

Rules stay off unless the JSON says `"enabled": true`, and importing a rule for the same files or handles replaces the old one. They don't run in the admin, on the login page, in the Customizer or in common page builders. To turn them off anywhere else, use the `ssc_speed_skip` filter.

**The automatic page checks and rollback don't cover speed rules yet.** A rule can break a page and still pass those checks, so after turning one on, please test your pages and forms yourself. Delaying a script that other scripts depend on will break them.

## WP-CLI

```bash
wp ssc scan --keep=3
```

```bash
wp ssc report --format=json
```

```bash
wp ssc apply --low-risk --yes
```

```bash
wp ssc apply --ids=abc123,def456 --yes
```

```bash
wp ssc runs
```

```bash
wp ssc resume
```

```bash
wp ssc restore <run-id> --yes
```

```bash
wp ssc purge <run-id> --phrase="DELETE NOW"
```

`--low-risk` skips your review of individual items, so read `wp ssc report` first. Command-line runs can't check logged-in admin screens.

## What it can't check

- Form submissions, email sending, checkout, and pages that need a login. Only the admin Plugins screen is checked, and only from the browser.
- Anything beyond 3,000 posts and 50 terms per taxonomy. These are still covered by the reference scan, but their pages aren't compared.
- References from outside your site, such as emails you've sent or other sites linking to your images.
- Files a plugin or theme builds names for at runtime, unless they appear on a checked page.
- Tables with foreign keys or triggers are not restored by rollback. The plugin never changes or drops them itself, but another plugin reacting to a change could.

The backup also runs in steps, so it isn't a single point-in-time snapshot. Clean during quiet hours on a busy site, and use maintenance mode if orders or submissions could come in.

Plugins that log visits may record the page checks as visits.

## Testing status

So far it has been tested on one disposable copy of a real site (about 270 pages and 20,000 files, with Bricks and ACF), on macOS with PHP's built-in server and PHP 8.5, through both WP-CLI and the admin screen.

**Tested and working:**

- Scan decisions on planted cases: renamed files, gallery images, files used only in custom tables, recent files, documents, and consent and unknown tables
- Automatic rollback when a public page breaks, and when the admin Plugins screen breaks
- Clean with repairs, followed by a full restore from the command line
- Recovery after the process was stopped during the backup, the backup test, the file move, the page re-check and a rollback
- Typed confirmation phrases, path guards, and blocking of anonymous, subscriber, author and editor API requests

**Not tested yet:**

- Restoring files and the database by clicking through the admin screen (the same code was tested from the command line)
- PHP 7.4 to 8.1, which are declared as supported but have not been run. PHP 8.2 to 8.4 passed a syntax check only.
- Apache, nginx, shared hosting, and real server time limits
- WooCommerce, tables with foreign keys or triggers, large sites, and a backup folder inside the web root

## Good to know

- **Cleaning doesn't free disk space straight away.** Space is freed only when you delete the quarantine.
- **Storage location:** backups go next to your web folder when possible. If the host doesn't allow that, they go in a randomly named folder inside `wp-content` with access rules, and you'll see a warning, because some servers ignore those rules. Delete them when you're done.
- **Uninstalling** removes the plugin's setting, but leaves quarantined files and backups on disk so you can't lose them by accident. Remove the `ssc-data-*` folder yourself when you no longer need it.
- **Removed plugins** are moved without running their uninstall routines, so their data stays in the database.

## Reporting problems

Please open an issue with your WordPress and PHP versions, the result shown, and the entries under **Details**. Remove any private URLs or data first. For security problems, please don't post details publicly; use GitHub's private vulnerability reporting if it's enabled, or contact the maintainer.

## License

[GPL-2.0-or-later](LICENSE)
