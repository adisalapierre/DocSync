# MasCamp DocSync

A standalone WordPress plugin. It syncs chosen, published WordPress posts and pages into a single designated Google Drive folder, updates the same linked Google Doc on later syncs, and records sync status on the post.

## Files
- `mascamp-docsync.php` — plugin entry point
- `includes/class-mascamp-gdocs-sync.php` — admin settings, OAuth, API calls, scheduling, status/metabox
- `includes/class-mascamp-gdocs-converter.php` — basic HTML conversion

## Google Cloud setup
1. Create/select a project in Google Cloud Console.
2. Enable **Google Docs API** and **Google Drive API**.
3. Configure the OAuth consent screen. Either publish the app (recommended: Testing-mode connections expire after 7 days) or add the Google account as a test user.
4. Create an OAuth client ID of type **Web application**.
5. Add the Authorized redirect URI shown at the top of **Settings → MasCamp DocSync**, for example:
   `https://YOUR-SITE.example/wp-admin/admin-post.php?action=mascamp_gdocs_callback`
6. In WordPress, open **Settings → MasCamp DocSync**. Enter client ID, client secret and the destination Drive folder (ID or full URL), save, then connect the Google account.
7. The connected Google account needs editor access to the destination folder.

## Recommended secret storage
Instead of storing the client secret in WordPress options, define this in `wp-config.php`:
```php
define('MASCAMP_GDOCS_CLIENT_SECRET', 'your-client-secret');
```
Then leave the client-secret field blank. Keep `wp-config.php` out of source control.

## Choosing what syncs
Nothing syncs unless you choose it.
- **One post, once:** open the post or page and click **Sync to Google Docs now** in the *Google Docs Sync* box, or hover it in **Posts/Pages** and click **Sync to Google Docs**. The sync runs immediately and shows a link to the doc.
- **Several posts:** tick them in **Posts/Pages**, choose **Sync to Google Docs** from *Bulk actions*, and click **Apply**. They're queued through WP-Cron, a few seconds apart.
- **Keep a post updated:** tick **Keep synced** in its *Google Docs Sync* box, or use the **Google Docs: keep synced** bulk action. After that, every time it's updated, the linked doc is refreshed automatically. Use **Google Docs: stop keeping synced** to turn this off.
- The **Automatic sync** setting pauses or resumes all *Keep synced* updates site-wide.
- The **Google Doc** column in Posts/Pages shows each item's status, a link to its doc, and whether it's set to keep synced.

## Behaviour
- Each post or page is linked to one Google Doc (`_mascamp_gdocs_document_id`). Syncing again replaces that doc's contents, so no duplicates are created. If the title changes, the doc is renamed.
- If the linked doc is deleted or trashed in Drive, the next sync creates a new doc automatically.
- Only published posts and pages sync. The sync copies the last *saved* version.
- The doc contains the title, a source link, the featured image, then the content: paragraphs, headings, bold, italic, underline, links, bulleted and numbered lists, images with captions, and simple tables with tab-separated cells.
- Images: Google fetches each image from its URL. Public JPG, PNG and GIF images work. If Google can't fetch an image (WebP, a protected or localhost URL, hotlink protection), it's replaced by a link, the sync still completes, and a warning is shown on the post.
- Automatic and bulk syncs run through WP-Cron, which needs site traffic or a real server cron calling `wp-cron.php`.

## Limitations
- One-way only (WordPress → Google Docs). Edits made in the Google Doc are overwritten on the next sync.
- Page-builder layouts (columns, sliders, tabs) come through as plain stacked content. Nested lists are flattened, and complex tables become tab-separated lines.
- Google OAuth tokens are stored in the WordPress options table.
## Standalone plugin installation
Install this as its own plugin in `wp-content/plugins/mascamp-docsync/`, then activate **MasCamp DocSync** in WordPress. It does not need to be included in `mascamp-plugin`.
