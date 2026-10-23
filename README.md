# MasCamp DocSync

A standalone WordPress plugin that syncs chosen, published posts and pages to **Google Docs** and/or a **Git repository** (GitHub, GitLab, Bitbucket Cloud or Gitea). The sync is one-way: WordPress is the source of truth.

Install it in `wp-content/plugins/mascamp-docsync/` and activate **MasCamp DocSync**. Everything is configured under **Settings → MasCamp DocSync**, which has these tabs: *Google Docs*, *Git*, *Sync all* and *How it works*.

## Files
- `mascamp-docsync.php`: plugin entry point
- `includes/class-mascamp-docsync.php`: core (settings page, editor box, Posts/Pages column, row and bulk actions, Sync all, queueing)
- `includes/class-mascamp-docsync-destination.php`: base class shared by the destinations
- `includes/class-mascamp-gdocs-destination.php`: Google Docs (OAuth, Drive/Docs API)
- `includes/class-mascamp-gdocs-converter.php`: HTML → Google Docs formatting
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

### Behaviour
- Each post is linked to one Google Doc. Syncing again replaces that doc's contents: edits made in the doc are overwritten, but they stay in the doc's Version history. If the post's title changes, the doc is renamed. If the doc is deleted or trashed, the next sync creates a new one.
- **Images:** the default *Show images in the doc* embeds each image inside the doc; Google fetches it from its URL. *Link to images only* adds an "Image: …" link to the website URL instead. Neither option adds image files to your Drive folder. If an image can't be fetched (for example WebP, a protected URL or localhost), it becomes a link and a warning is recorded on the post.

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

## Notes and limitations
- Elementor pages are synced from their rendered content. Page-builder layout (columns, spacing, backgrounds) is not kept: content comes through stacked in page order.
- Only published posts and pages sync, and each sync copies the last *saved* version.
- Automatic, bulk and background syncs use WP-Cron, which needs site traffic or a real server cron calling `wp-cron.php`.
- Google OAuth tokens and the Git token, unless it's defined in wp-config.php, are stored in the WordPress options table.
- Sync is one-way. Changes made in Google Docs or in the repository never come back into WordPress, and they are overwritten on the next sync.
