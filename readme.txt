=== Ekwa Web Stories Export & Import ===
Contributors: ekwa
Tags: web stories, web-stories, export, import, migration, amp
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export Google Web Stories together with all of their media assets and import them — fully re-linked — into another WordPress site.

== Description ==

This plugin lets you move stories created with the official Google **Web Stories**
plugin (https://wordpress.org/plugins/web-stories/) from one WordPress site to
another, **including every asset** used by each story:

* Images (and their generated sizes)
* Videos and their poster frames
* The story's featured image / poster
* The publisher logo

It is built for **site redesigns and migrations**, where you need the old site's
stories — and all the media inside them — to land cleanly on a brand-new site.

= How it works =

**Export** produces a single self-contained ZIP "bundle" containing:

* `manifest.json` — the stories (AMP markup + editor JSON + meta) and an index of every asset.
* `assets/` — the actual media files (full-size originals included).

**Import** reads that bundle on the destination site and:

1. Adds every asset to the Media Library (regenerating thumbnails/sizes).
2. Builds an old-URL → new-URL and old-ID → new-ID map.
3. Rewrites the AMP markup, the editor JSON, the poster, publisher logo and
   featured image of each story so they point at the freshly-imported copies.
4. Creates the stories on the new site.

Because all links are rewritten, the imported stories display and remain fully
editable in the Web Stories editor on the destination site.

== Installation ==

1. Copy the `ekwa-webstories-export-import` folder into `wp-content/plugins/`.
2. Activate **Ekwa Web Stories Export & Import** from Plugins.
3. Find **Stories Export/Import** in the left-hand admin menu.

Requirements: the PHP `ZipArchive` extension (standard on most hosts). The Google
Web Stories plugin should be active on the destination site so the imported
stories are editable.

== Usage ==

**On the OLD site (export):**

1. Go to *Stories Export/Import → Export*.
2. Tick the stories you want (or use *Export ALL stories*).
3. Set *Stories per ZIP (batch size)* — e.g. 2 or 3 (use 1 if you have very
   large videos). The library is exported as several small ZIPs instead of one
   huge one, which avoids timeouts / 500 errors on big libraries.
4. Click *Export selected (in batches)*. Each batch builds, then a download link
   appears for it. Click *Download all batches* (or each link) and save them.

**On the NEW site (import):**

1. Go to *Stories Export/Import → Import*.
2. Either select ALL your batch ZIPs at once, OR import them one at a time
   (repeat this step for each ZIP). For very large bundles, drop a ZIP on the
   server via FTP/SFTP and paste its absolute path into the *Server path* field.
3. Click *Import bundle*. Assets already imported from the same source site
   (e.g. the publisher logo shared by every batch) are reused, never duplicated
   — this holds whether you import all at once or one ZIP at a time. A summary
   with edit/view links is shown when finished.

== Why batches? ==

Exporting a large library as a single ZIP can exceed PHP's execution time or
memory limit (often seen as a 500 error), and the resulting file can be too big
to upload on the destination. Building several small ZIPs sidesteps both: each
ZIP is created in its own quick request, and stays small enough to import. You
choose how many stories go in each ZIP.

== Notes & limitations ==

* Selecting many ZIPs to import at once is limited by your server's
  `max_file_uploads` (commonly 20) and total `post_max_size`. Import in groups,
  or use the *Server path* option, if you have a very large number of batches.
* Within a single import run, shared assets are de-duplicated. Importing the same
  bundle again later creates fresh copies (best used onto a clean redesign site).
* Story dates, slugs, statuses, captions and alt text are preserved.
* The publisher logo is imported as an asset and re-linked per story; you may
  still want to set the site-wide default logo under Web Stories → Settings.

== Updates ==

This plugin updates itself from its GitHub repository using the bundled Plugin
Update Checker library (the same mechanism as the ekwa-video-block plugin), so
update notices appear on the normal Plugins screen.

Update source: https://github.com/agskanchana/ekwa-webstories-export-import/

To publish a new version (maintainers):

1. Bump the `Version:` header in `ekwa-webstories-export-import.php`.
2. Commit and push to the repository.
3. Create a GitHub Release / tag whose name matches the new version (e.g. `1.2.1`).
   The plugin checks for the newest release/tag and offers it as an update.

The repo is public, so no token is needed. To avoid GitHub API rate limits (or
if the repo is made private), define a token in wp-config.php:

`define( 'EKWA_WSEI_GITHUB_TOKEN', 'ghp_yourtoken' );`

== Changelog ==

= 1.2.3 =
* Much lower memory use during import: assets are now copied into the uploads
  folder with a streaming filesystem copy instead of being read fully into
  memory, and memory is freed between assets. This avoids the "critical error"
  (PHP out-of-memory) seen when importing video/image-heavy bundles on
  memory-constrained servers. Also raises the memory limit best-effort.

= 1.2.2 =
* Import batches one at a time if you prefer (repeat the import for each ZIP).
  Shared-asset de-duplication now persists across separate imports — each
  imported attachment is stamped with its source, so re-importing a batch reuses
  the existing media instead of creating duplicates.

= 1.2.1 =
* Fixed corrupted batch ZIPs when exporting many batches. Builds are now
  integrity-checked (ZipArchive close() result + a re-open consistency check)
  so a truncated archive is reported as a failed batch instead of a false
  success, with a disk-space pre-check.
* Each batch now downloads automatically right after it is built (one at a time)
  instead of firing all downloads at once, which was truncating the later ZIPs.
* Built ZIPs are kept on the server for ~2 hours and are no longer deleted on
  first download, so an interrupted download can simply be clicked again.

= 1.2.0 =
* Added self-hosted updates from GitHub via the bundled Plugin Update Checker
  (v5) library, with optional GitHub token support.

= 1.1.0 =
* Batched export: choose a batch size and the library is exported as several
  small ZIPs (built one at a time via AJAX), avoiding timeouts / 500 errors on
  large libraries.
* Multi-file import: select all batch ZIPs at once; shared assets are
  de-duplicated so they are imported only once.

= 1.0.0 =
* Initial release: select-and-export to ZIP, import with full asset + link remapping.
