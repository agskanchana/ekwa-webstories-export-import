=== Ekwa Web Stories Export & Import ===
Contributors: ekwa
Tags: web stories, web-stories, export, import, migration, amp
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.3.0
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
3. Click *Export selected stories* (or *Export ALL stories*). All the selected
   stories are bundled into a **single ZIP**, built on the server and then
   downloaded automatically. The ZIP is also kept on the server for ~2 hours, so
   if a download looks incomplete you can click its link to fetch it again.

**On the NEW site (import):**

1. Go to *Stories Export/Import → Import*.
2. Upload the ZIP. For a very large bundle that exceeds the upload limit, drop it
   on the server via FTP/SFTP and paste its absolute path into the *Server path*
   field instead.
3. Click *Import bundle*. Assets already imported from the same source site
   (e.g. a shared publisher logo) are reused, never duplicated. A summary with
   edit/view links is shown when finished.

== Single ZIP and memory ==

Export builds **one** self-contained ZIP. It stays memory-safe no matter how many
stories or how large the videos are, because media files are streamed into the
archive on disk (ZipArchive `addFile`) rather than loaded into PHP memory, and
remote/offloaded media is streamed to a temp file first. The download itself is
also streamed in chunks.

The one limit that scales with library size is **time**: building (and streaming)
a multi-gigabyte ZIP in a single request can exceed your web server's timeout,
seen as a 500 error. If that happens, export fewer stories at a time, or use the
per-row *Export this story* button. A very large ZIP may also exceed the upload
limit on the destination — in that case import it via the *Server path* field.

== Notes & limitations ==

* Import still accepts more than one ZIP at a time (e.g. bundles exported from
  several sites). Selecting many at once is limited by your server's
  `max_file_uploads` and total `post_max_size`; for large files use the
  *Server path* option instead.
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

= 1.3.0 =
* Export now produces a **single ZIP** containing all the selected stories,
  instead of splitting the library into several batch ZIPs. The batch-size
  control has been removed. This is memory-safe: media is streamed into the
  archive on disk (it is never loaded into PHP memory), so one big ZIP uses no
  more memory than a small one.
* The ZIP is built server-side and downloaded automatically, and is still kept
  on the server for ~2 hours so an interrupted download can be re-clicked. Very
  large libraries can still hit the server's time limit — export fewer stories,
  or use the per-row "Export this story" button, if you get a 500.

= 1.2.6 =
* Fixes the "critical error" (fatal out-of-memory) during import. Added a
  Low-memory mode (ON by default): import no longer regenerates thumbnails —
  the step that loads full images into memory and crashes small hosts. The
  full-size image is used for every size instead, so stories still display.
  Uncheck it only on servers with plenty of memory.
* ZIP downloads now defeat zlib output compression and omit a fixed
  Content-Length when the host forces compression, preventing corrupted/empty
  downloads.

= 1.2.5 =
* New: "Export this story" button on every row exports a single post as a ZIP
  via a plain download link (no JavaScript) — the smallest, most reliable
  request, ideal when larger exports return a 500.
* Export no longer compresses media (images/videos are already compressed):
  files are stored uncompressed, which is far faster and avoids the timeouts /
  500 errors that ZIP deflate caused on large media.
* Offloaded/remote media is now streamed to a temp file instead of being read
  into memory, and a single unreadable asset no longer aborts the whole export.
* Batch builds are wrapped so an error returns a clear message (and is logged
  as [ekwa-wsei]) instead of a 500.

= 1.2.4 =
* Import is now crash-resistant: a failing asset or story is isolated (logged as
  a warning) instead of taking down the whole import with a "critical error",
  and any catchable error is written to the PHP error log prefixed with
  [ekwa-wsei] for easy diagnosis. A top-level handler reports errors cleanly.

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
