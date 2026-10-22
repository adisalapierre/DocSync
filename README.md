# MasCamp DocSync

A starter module for the existing MasCamp WordPress plugin. It syncs published WordPress posts and pages into a single designated Google Drive folder, updates the same linked Google Doc on later syncs, and records sync status on the post.

## Files
- `mascamp-docsync.php` — module entry point
- `includes/class-mascamp-docsync.php` — admin settings, OAuth, API calls, scheduling, status/metabox
- `includes/class-mascamp-gdocs-converter.php` — basic HTML conversion

## Integrate into MasCamp plugin
1. Copy this directory into `wp-content/plugins/mascamp-plugin/app/integrations/google-docs-sync/`.
2. In the main MasCamp plugin bootstrap (or its existing app loader), require:
   ```php
   require_once plugin_dir_path(__FILE__) . 'app/integrations/google-docs-sync/mascamp-docsync.php';
   ```
   Adjust the path relative to the file containing the require. Do not activate this as a separate plugin if it is included by the main plugin.
3. If the main plugin has a central module registry/autoloader, register the entry point there instead of adding a second loader.

## Google Cloud setup
1. Create/select a project in Google Cloud Console.
2. Enable **Google Docs API** and **Google Drive API**.
3. Configure OAuth consent screen and add the Google account as a test user while the app is in Testing.
4. Create an OAuth client ID of type **Web application**.
5. Add this exact Authorized redirect URI (replace with your site's actual admin URL if WordPress uses a nonstandard admin URL):
   `https://YOUR-SITE.example/wp-admin/admin-post.php?action=mascamp_gdocs_callback`
6. In WordPress, open **Settings → MasCamp DocSync**. Enter client ID, client secret and the destination Drive folder ID, save, then connect the Google account.
7. The connected Google account needs editor access to the destination folder.

## Recommended secret storage
Instead of storing the client secret in WordPress options, define this in `wp-config.php`:
```php
define('MASCAMP_GDOCS_CLIENT_SECRET', 'your-client-secret');
```
Then leave the client-secret field blank. Keep `wp-config.php` out of source control.

## Current behavior and limitations
- One document is mapped to each WordPress post/page using `_mascamp_gdocs_document_id`; repeated sync updates the same document, avoiding duplicates during normal use.
- Auto-sync queues a WordPress scheduled event after a published post/page is saved; WordPress cron must be running reliably. For production, use a real server cron to call `wp-cron.php`.
- Manual sync button is in the post/page editor sidebar.
- Sync status and last-sync time are stored as post meta.
- Includes title, source link, featured image, inline images, plain text, headings, bold/italic/underline and links where recognized.
- Google Docs API embeds images from URLs Google can fetch publicly. Private media, hotlink-protected media, temporary signed URLs and some CDN rules may prevent image insertion.
- The first version's converter is intentionally lightweight. Complex tables, nested lists, columns, captions, page-builder content and exact inline formatting need additional handling and regression tests.
- This starter does not yet include a full retry dashboard, bulk backfill tool, multisite settings, token encryption, or automated tests. It is not production-certified.
- Google OAuth tokens are stored in the WordPress options table. Use restricted admin access, TLS, backups and ideally a custom encrypted token store before production.
- If the document was deleted from Drive, remove `_mascamp_gdocs_document_id` for that post and sync again to create a replacement.


## Standalone plugin installation
Install this as its own plugin in `wp-content/plugins/mascamp-docsync/`, then activate **MasCamp DocSync** in WordPress. It does not need to be included in `mascamp-plugin`.
