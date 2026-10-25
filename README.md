# MasCamp DocSync

A standalone WordPress plugin that syncs chosen posts and pages to **Google Docs** and/or a **Git repository** (GitHub, GitLab, Bitbucket Cloud or Gitea). Google Docs is two-way: edits made in a doc can be pulled back into WordPress, and new posts can be written as Google Docs and imported as drafts.

Install it in `wp-content/plugins/mascamp-docsync/` and activate **MasCamp DocSync**. Everything is configured under **Settings → MasCamp DocSync**, which has these tabs: *Changes*, *Google Docs*, *Git*, *Import from Docs*, *Sync all* and *How it works*.

## Files
- `mascamp-docsync.php`: plugin entry point
- `includes/class-mascamp-docsync.php`: core (settings page, editor box, Posts/Pages column, row and bulk actions, Sync all, queueing)
- `includes/class-mascamp-docsync-monitor.php`: Sync Monitor (out-of-sync detection, admin notice, Changes tab)
- `includes/class-mascamp-docsync-log.php`: activity log table
- `includes/class-mascamp-docsync-destination.php`: base class shared by the destinations
- `includes/class-mascamp-gdocs-destination.php`: Google Docs (OAuth, Drive/Docs API)
- `includes/class-mascamp-gdocs-converter.php`: HTML → Google Docs formatting
- `includes/class-mascamp-docs-importer.php`: Google Doc → WordPress blocks (used by Pull and Import)
- `includes/class-mascamp-git-destination.php`: Git settings and the Markdown file builder
- `includes/class-mascamp-git-client.php`: GitHub, GitLab, Bitbucket and Gitea REST clients
- `includes/class-mascamp-markdown-converter.php`: HTML → Markdown

## Choosing what syncs
Nothing syncs unless you choose it. Google Docs and Git are chosen separately for each post.
- **One post:** in the post editor's *DocSync* box, click **Sync to Google Docs now** or **Sync to Git now**. You can also hover the post in **Posts/Pages** and use the row link.
- **Everything:** on the **Sync all** tab, choose **Sync all posts** or **Sync all pages** for a destination. Tick *Only ones not synced yet* to skip items already synced. Items are processed one at a time, with a progress bar and a Cancel button. Keeping the page open makes it finish faster; if you close it, WP-Cron carries on in the background. If a rate limit is hit, it pauses for a minute and then retries.
- **Several posts:** tick them in **Posts/Pages**, choose a sync from *Bulk actions*, and click **Apply**.
- **Keep a post updated:** tick **Keep synced** under Google Docs or Git in the post's *DocSync* box, or use the *keep synced* bulk action. Each destination has an **Automatic sync** setting that pauses or resumes this for the whole site.
- The **DocSync** column in Posts/Pages shows each item's status for each destination, with a link to the synced copy.

## Google Docs
### Setup
1. In Google Cloud Console, enable the **Google Docs API** and the **Google Drive API**.
2. Configure the OAuth consent screen. Publishing the app is recommended, because connections made in Testing mode expire after 7 days. Alternatively, add your Google account as a test user.
3. Create an OAuth client of type **Web application**, and add the redirect URI shown on the *Google Docs* tab.
4. Enter the Client ID, the Client Secret and the Drive folder (its ID or full URL), save, then click **Connect Google account**.

### WordPress → Doc (push)
- Each post is linked to one Google Doc. A push replaces that doc's contents with the post. If the post's title changes, the doc is renamed. If the doc is deleted or trashed, the next push creates a new one.
- **Doc edits are protected:** if the doc was edited in Google Docs since the last sync, a push stops with an error and offers two choices: **Pull the doc into WordPress**, or **Overwrite the doc with WordPress**. Automatic syncs and Sync all never overwrite an edited doc.
- **Images:** the default *Show images in the doc* embeds each image inside the doc; Google fetches it from its URL. *Link to images only* adds an "Image: …" link to the website URL instead. Neither option adds image files to your Drive folder. If an image can't be fetched (for example WebP, a protected URL or localhost), it becomes a link and a warning is recorded on the post.

### Doc → WordPress (pull)
- **Pull from Google Doc** is in the post editor's *DocSync* box, in the Posts/Pages row links, and on the *Import from Docs* tab. The editor box shows a notice when the doc has been edited since the last sync.
- A pull replaces the post's **title** (the doc's *Title*-styled first line), **content** and **images**. The previous version is saved in WordPress **Revisions**, so it can be restored. If the post was also edited in WordPress since the last sync, the confirmation dialog warns you before replacing it.
- A pull does not change categories, tags, SEO fields, slug, status or the featured image.
- The header that a push adds to the doc (title, "Source:" line, featured image) is not copied into the post.
- Content becomes standard WordPress blocks: paragraphs, headings, bulleted and numbered lists (including nested lists), images, tables, bold, italic, underline, strikethrough, links and line breaks. The block markup is checked against the WordPress block validator.
- **Images:** an image that originally came from your site is matched to its existing Media Library item, so it isn't duplicated. An image pasted straight into the doc is downloaded into the Media Library once; later pulls reuse it (matched by checksum).
- **Elementor pages are one-way** (WordPress → Doc). Pulling into them is blocked, so their layout is never replaced.
- A pull doesn't trigger an automatic push back to the doc. Git *Keep synced* still commits the pulled change, as it would for any edit.

### New posts from Google Docs (import)
1. On the *Google Docs* tab, set an **Import folder**. It can be the same folder as the push folder.
2. Write a new doc in that folder. A *Title*-styled first line becomes the post title; otherwise the doc's name is used.
3. On the **Import from Docs** tab, click **Import as draft post** or **as page**. The post is created as a **draft** so you can review it before publishing.
4. The post stays linked to its doc. To bring in later edits, use **Pull latest** on that tab, or **Pull from Google Doc** on the post.

## Git
### Setup
On the *Git* tab, fill in:
- **Provider Type:** GitHub, GitLab, Bitbucket or Gitea.
- **Repository URL:** the browser address or the clone URL, e.g. `https://github.com/owner/repo`. Gitea needs the full URL of your server. Only Bitbucket Cloud is supported.
- **Branch:** usually `main`.
- **Folder in repository:** default `wordpress-content`.
- **Access Token:** a token is always needed to push, whether the repository is public or private.
  - **GitHub:** a fine-grained token limited to this repo, with **Contents: Read and write**.
  - **GitLab:** a project or personal access token with the **api** scope.
  - **Bitbucket:** a repository access token with **Repositories: Write**. Leave *Username* blank. If you use an Atlassian API token instead, enter your account email as *Username*.
  - **Gitea:** a token with **repository: Read and Write**.

Then click **Test connection**. To keep the token out of the database, define it in `wp-config.php`:
```php
define('MASCAMP_DOCSYNC_GIT_TOKEN', 'your-token');
```

### What gets committed
- Posts are saved as `wordpress-content/posts/<slug>.md` and pages as `wordpress-content/pages/<slug>.md`. A post keeps its first file path even if its slug changes later, so its history stays in one file.
- Each file starts with YAML front matter (title, id, type, slug, date, modified, author, permalink, categories, tags and featured_image), followed by the content converted to Markdown.
- Each changed item is one commit, with a message such as `Update post: Band Launch 2027`. If nothing changed, no commit is made and the status shows *Up to date*.
- **Images are referenced by their website URL only.** Image files are never copied into the repository.

## Duplicated posts
Each post's link to its Git file and Google Doc is stamped with that post's ID. Duplicator plugins copy every custom field, so a duplicate arrives carrying the original's link. DocSync detects this (the stamp names another post) and clears the copied sync data, so the duplicate gets its own file and its own doc on its first sync and never overwrites the original's. *Keep synced* choices are kept. Posts linked before this check existed are handled too: the lowest-ID post with a given link is treated as the original. With Yoast Duplicate Post, DocSync's fields are also excluded from copying.

## Sync Monitor and activity log
**Settings → MasCamp DocSync → Changes** (the *View changes* button in the admin notice opens it).

**Out of sync:** lists every post or page whose WordPress version and synced copies no longer match. For each one it shows where the difference is and who changed it, with the right fix button:

| Situation | Shown as | Fix button |
|---|---|---|
| Edited in WordPress since the last sync | WordPress is newer | Sync to Google Docs / Sync to Git |
| Edited directly in the Google Doc | Google Doc is newer (with the editor's name) | Pull into WordPress |
| File changed directly in the repository | Git file is newer (with the commit author and message) | Overwrite with WordPress |
| Changed on both sides | Changed on both sides | Keep doc version / Keep WordPress version |
| Doc deleted or trashed, file deleted or moved | … missing | Recreate from WordPress |
| Last sync failed | Sync failed (with the error) | Retry sync |

- **Sync all WordPress changes** pushes every item that changed *only* in WordPress. Anything edited remotely is never overwritten by this button.
- **How changes are detected:** WordPress edits are detected immediately. Google Drive and Git are checked hourly, or straight away with **Scan now**. A check uses one Drive folder listing and one repository tree listing. Only docs whose modified time changed get a second, precise revision check, so renames and sharing changes aren't reported as edits.
- **Bitbucket** can't list file versions cheaply, so only WordPress-side Git changes are shown for Bitbucket repositories.
- **Admin notice:** while anything is out of sync, every admin page shows "MasCamp DocSync has detected N changes…" with a **View changes** button. Dismissing it hides it until something else changes.

**Activity log:** every event is recorded with a heading, an event type and a time, for example:

```
Push
Event Type: Post to Git started
Time: October 25, 2026 1:22:03 pm
```

Headings are **Push**, **Pull**, **Update**, **Sync Update** (scans), **Change detected**, **Import**, **Sync all** and **Error**. You can filter by **activity type** and by **location** (Google Docs or Git), and tick **Only posts with sync issues** to see only the posts that are currently out of sync. Each post's **activity history** link shows only that post's events. Events are kept for 90 days in their own database table (`wp_mascamp_docsync_log`).

## Notes and limitations
- Elementor pages are synced from their rendered content. Page-builder layout (columns, spacing, backgrounds) is not kept: content comes through stacked in page order.
- Only published posts and pages sync, and each sync copies the last *saved* version.
- Automatic, bulk and background syncs use WP-Cron, which needs site traffic or a real server cron calling `wp-cron.php`.
- Google OAuth tokens and the Git token, unless it's defined in wp-config.php, are stored in the WordPress options table.
- Git is one-way: changes made in the repository never come back into WordPress, and they are overwritten on the next sync. Google Docs is two-way, but only when you pull: nothing is pulled into WordPress automatically.
