# MasCamp DocSync

**Version 0.5.1** · WordPress plugin · PHP 7.4+

MasCamp DocSync keeps chosen WordPress posts and pages in sync with **Google Docs** and with a **Git repository** (GitHub, GitLab, Bitbucket Cloud or Gitea), and installs and updates **plugins and themes straight from Git**.

- **Google Docs is two-way.** Push a post to its own Google Doc, pull edits made in the doc back into WordPress, or write a brand-new post as a doc and import it as a draft.
- **Git is one-way.** Each post is committed as a Markdown file with its details at the top. The repository becomes a version-controlled archive of your content.
- **Plugin & Theme Sync deploys code from Git.** Connect a plugin or theme to a repository branch and choose how it updates: *Manual*, *Check & notify*, or *Automatic* on every push. It replaces Deployer for Git, and can import its connections.
- **A Sync Monitor watches all three places** (WordPress, Google Drive and Git). It shows what is out of sync, who changed it and when, with a one-click fix for each case. Every action is recorded in an activity log.

Nothing syncs unless you choose it.

---

## Contents
1. [Installation](#installation)
2. [Where everything is](#where-everything-is)
3. [Choosing what syncs](#choosing-what-syncs)
4. [Google Docs](#google-docs)
5. [Git](#git)
6. [Changes: the Sync Monitor](#changes-the-sync-monitor)
7. [Plugin & Theme Sync](#plugin--theme-sync)
8. [Activity log](#activity-log)
9. [Background jobs and progress](#background-jobs-and-progress)
10. [Duplicated posts](#duplicated-posts)
11. [Content handling](#content-handling)
12. [Security and data stored](#security-and-data-stored)
13. [Troubleshooting](#troubleshooting)
14. [Development workflow](#development-workflow)
15. [File structure](#file-structure)
16. [Changelog](#changelog)

---

## Installation

**From the zip (live sites):** go to **Plugins → Add New → Upload Plugin**, upload `mascamp-docsync.zip`, choose **Replace current with uploaded** if asked, then activate **MasCamp DocSync**.

- Use the `mascamp-docsync.zip` release file. GitHub's green **Code → Download ZIP** button names the folder `DocSync-main`, which WordPress installs as a *second* copy instead of replacing the first.
- Only one copy can run. If DocSync is already active from another folder (for example `DocSync`, installed by Deployer for Git), activating a second copy stops with a message naming both folders instead of a fatal error. Delete the copy you don't want; settings live in the database and are kept.

**From Git (development):** clone the repository straight into the plugins folder, so that the folder *is* the repository:
```
cd wp-content/plugins
git clone https://github.com/adisalapierre/DocSync.git mascamp-docsync
```
Don't upload the zip into a folder that is also a git clone. Update that folder with `git pull` instead.

After activating, set up Google Docs and/or Git on their tabs. Then open **Changes** and click **Scan now** once.

---

## Where everything is

| Place | What it's for |
|---|---|
| **Settings → MasCamp DocSync → Changes** | Sync Monitor: what's out of sync, bulk fixes, **Scan now**, activity log |
| **… → Google Docs** | OAuth connection, Drive folders, image mode, automatic sync |
| **… → Git** | Provider, repository URL, branch, folder, token, **Test connection** |
| **… → Import from Docs** | Turn docs in the import folder into draft posts or pages |
| **… → Plugin Sync / Theme Sync** | Connect plugins and themes to Git, deploy updates, Push-to-Deploy URLs, deploy settings |
| **… → Sync all** | Sync every published post or page to a destination |
| **… → How it works** | Short in-app guide |
| **Post editor → DocSync box** | Per-post status, **Sync now**, **Pull from Google Doc**, **Keep synced** |
| **Posts / Pages list** | **DocSync** status column, row links, bulk actions |
| **Every admin page** | "DocSync has detected N changes" notice; progress notice and an admin-bar counter while a job runs |

---

## Choosing what syncs

Google Docs and Git are chosen separately for each post.

- **One post:** use **Sync to Google Docs now** or **Sync to Git now** in the editor's DocSync box, or the row link in Posts/Pages.
- **Several posts:** tick them in Posts/Pages, choose *Sync to …* from **Bulk actions**, and click **Apply**.
- **Everything:** on the **Sync all** tab, choose **Sync all posts** or **Sync all pages**. Tick *Only ones not synced yet* to skip items that already have a copy.
- **Keep a post updated automatically:** tick **Keep synced** for Google Docs or Git in the post's DocSync box, or use the *keep synced* bulk action. Each destination's **Automatic sync** setting pauses or resumes this for the whole site.

Only **published** posts and pages can be pushed. A sync always copies the last *saved* version.

---

## Google Docs

### Setup
1. In **Google Cloud Console**, enable the **Google Docs API** and the **Google Drive API**.
2. Configure the **OAuth consent screen** (Google Auth Platform):
   - **Audience:** *External* for a personal Gmail account. Click **Publish app**: in *Testing* mode, connections expire after 7 days. Alternatively, add your account under **Test users**.
   - Don't upload a logo. A logo triggers Google's verification review, which isn't needed for your own use.
3. Under **Clients**, create a **Web application** client. Add the **Authorized redirect URI** shown at the top of the plugin's *Google Docs* tab, e.g. `https://example.com/wp-admin/admin-post.php?action=mascamp_gdocs_callback`.
4. On the *Google Docs* tab, enter the **Client ID**, the **Client Secret** and the **Google Drive folder** (its ID or full URL). Click **Save**, then **Connect Google account**. Google warns that the app isn't verified; choose **Advanced → Go to … (unsafe)**. That's expected for your own unverified app.

Settings on that tab:
- **Images:** *Show images in the doc* (default) or *Link to images only*. Neither option adds image files to your Drive folder.
- **Import folder** (optional): the Drive folder where you write new posts as docs. It can be the same as the sync folder.
- **Automatic sync:** pauses or resumes *Keep synced* for Google Docs.

### WordPress → Doc (push)
- Each post has **one** linked Google Doc. A push replaces the doc's contents with the post: title, a *Source:* link, the featured image, then the content. If the post's title changes, the doc is renamed. If the doc was deleted or trashed, the next push creates a new one.
- **Edits made in the doc are never overwritten silently.** If the doc was edited since the last sync, a push stops and offers **Pull the doc into WordPress** or **Overwrite the doc with WordPress**. Automatic syncs and bulk syncs never overwrite an edited doc.
- If Google can't fetch an image (WebP, a protected or localhost URL), that image becomes a link. A warning is recorded, and the rest of the post still syncs.

### Doc → WordPress (pull)
- **Pull from Google Doc** is available in the editor's DocSync box, in the Posts/Pages row links, on the *Import from Docs* tab, and as a bulk action on *Changes*. The editor box tells you when the doc has been edited.
- A pull replaces the post's **title**, **content** and **images**. The title comes from the doc's *Title*-styled first line. The previous version is saved in **WordPress Revisions**. The confirmation warns you if the post was also edited in WordPress since the last sync.
- A pull never changes categories, tags, SEO fields, slug, status or the featured image. The header a push adds (title, *Source:* line, featured image) isn't copied into the post.
- The content becomes standard WordPress blocks: paragraphs, headings, nested bulleted and numbered lists, images, tables, bold, italic, underline, strikethrough, links and line breaks. The block markup has been checked against WordPress's own block validator.
- **Images:** an image that came from your site is matched to its existing Media Library item. An image pasted into the doc is downloaded once; later pulls reuse it, matched by checksum.
- **Elementor pages are one-way** (WordPress → Doc), so their layout is never replaced.
- A pull doesn't push back to the doc. Git *Keep synced* still commits the pulled change, as it would any edit.

### New posts from Google Docs (import)
1. Set an **Import folder** on the *Google Docs* tab.
2. Write a doc in that folder. A *Title*-styled first line becomes the post title; otherwise the doc's name is used.
3. On **Import from Docs**, click **Import as draft post** or **as page**. Review the draft, then publish it.
4. The post stays linked to its doc. Use **Pull latest** to bring in later edits.

---

## Git

### Setup
On the *Git* tab:
- **Provider Type:** GitHub, GitLab, Bitbucket (Cloud only) or Gitea.
- **Repository URL:** the browser address or clone URL, e.g. `https://github.com/owner/repo`. Gitea needs the full server URL.
- **Branch:** usually `main`. Older repositories may use `master`.
- **Folder in repository:** default `wordpress-content`.
- **Access Token:** always required to push, whether the repository is public or private.
  - **GitHub:** fine-grained token, limited to this repository, with **Contents: Read and write**.
  - **GitLab:** project or personal token with the **api** scope.
  - **Bitbucket:** repository access token with **Repositories: Write**, with *Username* left blank. Or an Atlassian API token, with your account email as *Username*.
  - **Gitea:** token with **repository: Read and Write**.
- **Password-protected posts:** whether to include their content (off by default; see [Content handling](#content-handling)).
- **Automatic sync:** pauses or resumes *Keep synced* for Git.

Click **Test connection**. It checks the repository and branch and explains any problem, such as a wrong branch, a rejected token or an empty repository. To keep the token out of the database, put it in `wp-config.php` instead:
```php
define('MASCAMP_DOCSYNC_GIT_TOKEN', 'your-token');
```

### What gets committed
- **Paths:** posts go to `wordpress-content/posts/<slug>.md`, pages to `wordpress-content/pages/<slug>.md`. A post keeps its first path even if its slug changes later, so its history stays in one file.
- **File format:** YAML front matter (`title`, `id`, `type`, `slug`, `date`, `modified`, `author`, `permalink`, `parent`, `excerpt`, `categories`, `tags`, `featured_image`, `password_protected`), then the content as GitHub-flavoured Markdown.
- **One commit per changed item,** e.g. `Update page: Checkout privacy policy`. Unchanged items make no commit and show *Up to date*.
- **Images** are referenced by their website URL. Image files are never copied into the repository.

---

## Changes: the Sync Monitor

**Settings → MasCamp DocSync → Changes**, or **View changes** in the admin notice.

### What it shows

| Situation | Shown as | Fix |
|---|---|---|
| Edited in WordPress since the last sync | WordPress is newer | Sync to Google Docs / Sync to Git |
| Edited directly in the Google Doc | Google Doc is newer (editor's name) | Pull into WordPress |
| Changed directly in the repository | Git file is newer (commit author and message) | Overwrite with WordPress |
| Changed on both sides | Changed on both sides | Keep doc version / Keep WordPress version |
| Doc deleted or trashed; file deleted or moved | … missing | Recreate from WordPress |
| Last sync failed | Sync failed (the error) | Retry sync |
| Published but never synced: a new post, or a duplicate | Not synced yet ("Duplicated from …") | Sync to … / Ignore |
| The copy holds a *different* post's content | Holds another post's content | Restore from WordPress |
| The doc's title and source link don't match this post | Needs checking (title and link shown) | Open doc, then Restore or Pull |

Above the list, summary cards count **WordPress**, **Google Docs**, **Git**, **Not synced yet** and **Problems**. The **Show** filter narrows the list.

### Fixing in bulk
Tick rows, or use the header box to select every row the filter is showing. Then choose:
- **Fix selected:** each row's recommended action (Sync, Restore, Recreate or Retry). Rows edited remotely or on both sides are skipped, because they need your decision.
- **Overwrite with WordPress version**
- **Pull Google Doc into WordPress**
- **Ignore:** for *Not synced yet* rows you never want synced. Syncing the post later undoes this.

Shortcut buttons: **Sync all WordPress changes** (only items changed in WordPress alone) and **Sync all not-synced-yet**. Every bulk action runs as one background job with a progress bar.

### How detection works
- **WordPress edits** are detected immediately. Each post's modified time is compared with the time of its last sync.
- **Google Drive and Git** are checked **hourly**, or straight away with **Scan now**. A check makes one Drive folder listing and one repository tree listing. Only docs whose modified time moved get a second, exact revision check, so renames and sharing changes aren't reported as edits.
- **Content check:** each synced copy is checked once to confirm it belongs to its post. For Git, the file's `id:` must match; for Google Docs, the doc's *Source:* link and title must match. Only a confirmed mismatch, where the copy names another post on this site, shows as *Holds another post's content*. Docs first synced from another address (local or staging site, old permalink) are accepted when their slug or title matches. Anything still unclear shows as *Needs checking*.
- **Bitbucket** can't list file versions cheaply, so only WordPress-side Git changes are shown for Bitbucket.
- **Notice:** while anything is out of sync, every admin page shows *"MasCamp DocSync has detected N changes…"*. Dismiss it to hide it until something else changes.

---

## Plugin & Theme Sync

**Settings → MasCamp DocSync → Plugin Sync** and **Theme Sync.** Install and update plugins and themes from a Git branch on GitHub, GitLab, Bitbucket or Gitea. Git doesn't need to be installed on the server.

### Connecting a plugin or theme
Click **Connect a plugin from Git** (or **theme**) and fill in:
- **Plugin / Theme:** an installed one to manage from Git, or **New: install from the repository** (with an optional folder name; by default the repository's name).
- **Provider Type**, **Repository URL** and **Branch**, e.g. `main` for clients that get every release, or a release branch for legacy clients.
- **Folder in repository:** only if the plugin or theme lives in a subfolder of the repository.
- **Access:**
  - *Public repository*: no token.
  - *Use the token from the Git tab*: the same provider only.
  - *Private: use this token*: for GitHub, a fine-grained token with **Contents: Read** is enough. Bitbucket also takes a username/email for app passwords and API tokens.
- **Updates:** choose one mode per package:

  | Mode | What happens |
  |---|---|
  | **Manual** | Only when you click **Deploy**. Good for legacy clients on older code. |
  | **Check & notify** | Checked hourly, and whenever a push arrives. Shows **Update available** (commit, message, author) and an admin notice; you deploy when ready. |
  | **Automatic** | Deploys on every push to the branch through the **Push-to-Deploy URL**. The hourly check catches any missed push. For sites that should always run the latest, e.g. client sites on `main`. |
- **Keep on deploy:** files or folders the site creates inside the plugin or theme that aren't in Git (e.g. `config.php`, `cache/`). They're copied into each new version.
- **Activate after the first install** (plugins).

### The cards
Each connected package shows its name, folder, provider, branch, repository, update mode, the installed commit and the latest commit on the branch, plus a status:
- **Up to date**
- **Update available**
- **Not installed**
- **Version unknown**: not deployed by DocSync yet; deploy once to start tracking.
- **Local git clone**
- **Error**

Buttons: **Install / Deploy now / Update to …**, **Check**, **Edit**, **Disconnect** (leaves the files in place), and **Show Push-to-Deploy URL**. At the top of the tab, **Check all for updates** and **Deploy all updates** run as a background job with a progress bar.

### Push-to-Deploy (webhooks)
Each package has a URL like `https://example.com/wp-json/mascamp-docsync/v1/deploy?type=plugin&package=<folder>&secret=…`. Add it as a **push** webhook in the repository. On GitHub: *Settings → Webhooks → Add webhook*, content type `application/json`.
- **Only pushes to the package's branch count.** Pushes to other branches and tags are ignored.
- **Automatic** packages deploy right after the push, in the background. **Manual** and **Check & notify** packages just record the update.
- **Authentication:** the URL's `secret`, or the same secret set as the webhook's *Secret* (GitHub/Gitea signature, GitLab token).
- **CI:** add `&sync=1` to wait for the deploy result.

### Safety
- **Wrong repository:** the download must contain a plugin (a PHP file with `Plugin Name:`) or a theme (`style.css` with `Theme Name:`). If it doesn't, nothing on the site is changed.
- **Local git clones** (folders with `.git`, like a MAMP development copy) are never overwritten. Update them with `git pull`.
- **Swapping in the new version:** it's prepared next to the old one and swapped in with folder renames, so a failed deploy leaves the previous version in place. PHP's opcode cache is refreshed for the new files.
- **Disabled file changes:** deploys are refused when file changes are disabled (`DISALLOW_FILE_MODS`) or WordPress can't write to the folder directly.
- **No backups:** DocSync doesn't keep backups of previous versions. Git history is the record; redeploy an older branch or commit to roll back.

### Deploy settings
At the bottom of either tab:
- **Push-to-Deploy secret**, with **Regenerate**. Regenerating changes every webhook URL.
- **Flush cache** after every deploy: WP Rocket, WP-Optimize, W3 Total Cache, LiteSpeed Cache, WP Super Cache, WP Fastest Cache and Autoptimize; detected plugins are shown.
- **Alert notification:** a wp-admin banner reminding developers not to edit connected plugins or themes on the site, but to use Git.

### Switching from Deployer for Git
When Deployer for Git's data is on the site, **Import N package(s)** copies each plugin and theme connection (repository, branch, token). Its own data is left untouched.
- Imported packages start in **Check & notify**. Switch the ones that should update on every push to **Automatic**.
- Click **Deploy** once on each, to start tracking its version.
- After you deactivate Deployer for Git, its existing Push-to-Deploy URLs (`/wp-json/dfg/v1/package_update?…`) keep working, handled by DocSync with the imported secret. You don't need to change any repository webhooks.

---

## Activity log

Shown below the list on the *Changes* tab. Each event has a heading, an event type and a time:
```
Push
Event Type: Post to Git started
Time: October 25, 2026 1:22:03 pm
```

| Heading | Meaning |
|---|---|
| **Push** | A sync to Google Docs or Git started |
| **Update** | A sync or pull finished (or the post was already up to date) |
| **Pull** | A pull from Google Docs started |
| **Import** | A doc was imported as a new draft |
| **Sync Update** | A scan of Google Drive / Git started or finished |
| **Change detected** | Something went out of sync: WordPress edit, remote edit, duplicate, wrong content, not synced yet |
| **Sync all** | A bulk job started, finished, stopped or was cancelled |
| **Deploy** | A plugin or theme was connected, deployed from Git, queued by a push, or disconnected |
| **Error** | A sync or pull failed, with the reason |

**Filters:** *Activity type*, *Location* (Google Docs, Git, or Plugins & themes) and **Only posts with sync issues**. They can be combined. Each post's **activity history** link shows only that post's events. Entries show who did it, or *Automatic* for scheduled runs. Events are kept for **90 days**.

---

## Background jobs and progress

- **Long tasks run in the background with a progress bar:** Sync all, the bulk fixes on *Changes*, Sync all WordPress changes, Sync all not-synced-yet, and Scan now.
  - **Changes and Sync all tabs:** a full panel shows the current item, *N of M* done, errors, the latest error and a **Cancel** button.
  - **Other admin pages:** a compact progress notice, and the admin bar shows **DocSync N of M**.
  - **You can leave the page or close the browser.** The job continues through WP-Cron, a little more slowly. Any open admin page speeds it up.
  - **One job at a time:** related buttons are disabled until the current job finishes.
  - **Rate limits:** if Google or the Git host limits requests, the job pauses for a minute and resumes on its own.
  - **Repeated setup errors:** if the first three items fail with the same error (for example a bad token), the job stops and shows what to fix.
- **Single actions** (Sync now, Pull, Retry, Import) show a "working…" notification immediately. If you leave the page mid-way, the server still finishes the action.

---

## Duplicated posts

Duplicator plugins copy every custom field, including the link to the original's Google Doc and Git file. Without protection, a duplicate would overwrite the original's copies.

- Every link is stamped with the ID of the post that owns it. A duplicate carrying another post's link is detected the next time it's used, and the copied sync data is cleared. The duplicate then appears in *Changes* as **Not synced yet: Duplicated from "…"**, and gets its own doc and file on its first sync. *Keep synced* choices are kept.
- Links made before stamping existed: the lowest-ID post with that link is treated as the original.
- With **Yoast Duplicate Post**, DocSync's fields are excluded from copying in the first place.
- If a duplicate wrote into the original's copy before this protection existed, the Sync Monitor's content check flags the original as **Holds another post's content**. Use **Restore from WordPress**.

---

## Content handling

- **Elementor:** pages are synced from their rendered content. Hidden duplicates (mobile-only copies, tab titles, cloned slides) are skipped. Layout (columns, spacing, backgrounds) isn't kept; content comes through in page order.
- **Password-protected posts:** the real content is synced, not the password form, and titles carry no "Protected:" label. Google Docs gets the full content (your Drive is private). Git gets the post's details plus `password_protected: true` and a note, unless *Include their content in the repository* is on, because repositories can be public.
- **Emoji:** emoji that WordPress shows as small images (📍, ✅…) stay as emoji characters.
- **Formatting:** curly quotes, accents and emoji keep their exact positions in Google Docs, which counts text in UTF-16 units.

---

## Security and data stored

- Only administrators can change settings, run bulk jobs or scans, and see the monitor. Per-post actions require permission to edit that post. Every action is nonce-protected.
- **Secrets:** the Google client secret and the Git token can be kept out of the database with `MASCAMP_GDOCS_CLIENT_SECRET` and `MASCAMP_DOCSYNC_GIT_TOKEN` in `wp-config.php`. Google OAuth tokens are stored in the options table.
- **Google access:** the `documents` and `drive` scopes, needed to write into a folder you created yourself.
- **Deployments** need permission to install plugins and themes. Webhooks require the secret or a valid signature.
- **Data stored:**
  - **Options:** `mascamp_docsync_settings`, `mascamp_docsync_tokens`, `mascamp_docsync_git`, `mascamp_docsync_batch`, `mascamp_docsync_monitor`, `mascamp_docsync_packages` (plugin and theme connections, including tokens), `mascamp_docsync_deploy`.
  - **Post meta:** keys beginning `_mascamp_gdocs_`, `_mascamp_docsync_` and `_mascamp_git_`, holding links, status, baselines and flags.
  - **Activity log:** the database table `wp_mascamp_docsync_log`.
- **Scheduled tasks:** the hourly `mascamp_docsync_monitor_scan` and `mascamp_docsync_deploy_check` (only while a package uses Check & notify or Automatic), both cleared on deactivation, plus short-lived job, queue and deploy events.

---

## Troubleshooting

| Message / symptom | Fix |
|---|---|
| `redirect_uri_mismatch` | Copy the redirect URI from the *Google Docs* tab into the OAuth client exactly. Changes can take a few minutes to apply. |
| `403 access_denied` … "has not completed the Google verification process" | Publish the app, or add your account as a test user (Google Auth Platform → Audience). |
| Google connection stops working after 7 days | The app is still in Testing. Publish it, then reconnect. |
| "Google Drive folder not found" | Check the folder ID/URL, and that the connected account can edit the folder. |
| Git: "access token was rejected (401)" / "Access denied (403)" | Recreate the token with write access to this repository's contents. |
| Git: "branch … was not found" | Use the branch name that **Test connection** reports. |
| Git: "repository is empty" | Add a README or any first commit, then test again. |
| "the Google Doc was edited since the last sync" | Choose **Pull** (keep the doc's version) or **Overwrite the doc** (keep WordPress's). |
| "Image skipped" warning | Google couldn't fetch the image (WebP, protected or localhost URL). Use JPG/PNG/GIF on a public URL, or switch to *Link to images only*. |
| A job seems stuck | Keep an admin page open, or make sure WP-Cron runs (site traffic or a server cron calling `wp-cron.php`). Cancel and restart if needed. |
| "Sync all stopped: the first N items failed with the same error" | Fix the setting it names (usually the token), use **Test connection**, then run it again. |
| Deploy: "isn't a plugin" / "isn't a theme" | Check the branch and *Folder in repository*: the plugin's main PHP file (or the theme's `style.css`) must be at that level. |
| Deploy: "This folder is a local git clone" | Expected on a development copy: update it with `git pull`. |
| Deploy: "wasn't a zip file" / 404 | The token can't read the repository, or the branch doesn't exist. Use a token with read access to contents. |
| Push-to-Deploy does nothing | The package must be in **Automatic** mode, the push must be to its branch, and WP-Cron must run. Check the Activity log for "push received". |

---

## Development workflow

On the development Mac the plugin folder is a clone of this repository (`/Applications/MAMP/htdocs/mascamp/wp-content/plugins/mascamp-docsync`).

- **Get the latest:** `git pull`
- **Apply a patch file:** `git am path/to/0001-….patch`, then `git push`. Run each patch once; a second run fails because the changes are already there. If `git am` stops, run `git am --abort`.
- **Release to the live site:** build or download `mascamp-docsync.zip` and upload it under Plugins. Never upload the zip into the git-clone folder.
- `.DS_Store` files are ignored by `.gitignore`.

---

## File structure

```
mascamp-docsync/
├── mascamp-docsync.php                      Plugin header, loader, deactivation cleanup
├── README.md
└── includes/
    ├── class-mascamp-docsync.php            Core: settings tabs, editor box, Posts column, row/bulk
    │                                        actions, sync runner, background jobs, progress UI
    ├── class-mascamp-docsync-destination.php  Base class for destinations; duplicate detection;
    │                                        baselines for change detection
    ├── class-mascamp-docsync-monitor.php    Sync Monitor: detection, Changes tab, bulk fixes, notice
    ├── class-mascamp-docsync-log.php        Activity log table and queries
    ├── class-mascamp-gdocs-destination.php  Google Docs: OAuth, push, pull, import, Drive scan
    ├── class-mascamp-gdocs-converter.php    HTML → Google Docs text and formatting
    ├── class-mascamp-docs-importer.php      Google Doc → WordPress blocks
    ├── class-mascamp-git-destination.php    Git settings, Markdown file builder, repository scan
    ├── class-mascamp-git-client.php         GitHub / GitLab / Bitbucket / Gitea REST client
    │                                        (files, commits, archives)
    ├── class-mascamp-deploy.php             Plugin & Theme Sync: deploys, checks, webhooks, import
    └── class-mascamp-markdown-converter.php HTML → Markdown
```

---

## Changelog

**0.5.1**
- Installing a second copy of DocSync (a zip next to a Deployer for Git install, or a GitHub "Download ZIP") no longer causes a fatal error on activation. It explains which folder is already active instead.

**0.5.0**
- **Plugin Sync** and **Theme Sync** tabs: install and update plugins and themes from GitHub, GitLab, Bitbucket or Gitea.
- Each package has its own update mode (Manual / Check & notify / Automatic), a Push-to-Deploy webhook, Keep-on-deploy files, safety checks and a folder-swap install.
- Deploy settings: secret, cache flushing and a developer alert banner.
- Import from Deployer for Git, with its old webhook URLs kept working.

**0.4.4**
- Password-protected posts sync their real content and title. Git leaves protected content out unless enabled.
- Emoji stay as characters instead of "Image skipped" warnings.
- Scans are logged as *Sync Update*, and bulk pulls as *Pull*.

**0.4.3**
- Bulk actions on *Changes*: Fix selected, Overwrite, Pull, Ignore.
- Fewer false "holds another post" warnings: docs synced from another address are accepted. Unclear cases show *Needs checking*.

**0.4.2**
- *Not synced yet* list, including duplicates and new posts, with Ignore and Sync all not-synced-yet.
- Content check flags copies that hold another post's content, with Restore from WordPress.

**0.4.1**
- Fix: re-syncing a Google Doc that already had content failed (`Unknown name "deleteContent"`).
- Background job queue with a progress bar, compact notice and admin-bar counter. Jobs keep running when you leave the page.

**0.4.0**
- Sync Monitor (*Changes* tab), admin notice, hourly scan, activity log with filters.

**0.3.1**
- Duplicated posts no longer overwrite the original's Google Doc or Git file.

**0.3.0**
- Two-way Google Docs: Pull from Google Doc, Import from Docs, protection for edited docs.

**0.2.x**
- Git sync (GitHub, GitLab, Bitbucket, Gitea). Plugin split into destinations. Sync all errors shown, with early stop on repeated setup errors.

**0.1.x**
- Google Docs sync with images, Elementor support, per-post Keep synced, bulk actions, Sync all.
