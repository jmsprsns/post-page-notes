# WordPress Post and Page Notes

Threaded notes on posts and pages for your team, in the block editor and the classic editor, with a Notes column in your post lists.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

- **Notes beside the content.** A "Page notes" / "Post notes" sidebar in the block editor (toolbar button with the number of open threads) and a box under the content in the classic editor. Both are the same interface (`assets/notes.js`).
- **Threads.** New notes, replies to any note (nested three deep, deeper replies say who they answer), edit and delete your own, resolve/reopen threads. A reply reopens a resolved thread.
- **Names** come from the WordPress account: "Display Name (username)", or the display name only (setting).
- **Notes column** in post and page lists: total notes and open threads, linked to `post.php?post=ID&action=edit#post-page-notes`, which opens the notes. `#ppn-note-<id>` opens one note (reply emails use it).
- **Reply emails**, off by default: the author of the note answered and the thread's starter, never the replier, only people who can still edit the post.
- **Overview** at Content Powered > Post and Page Notes: totals and the posts and pages with open threads.

## How it's built

- Notes live in their own table, `{prefix}ppn_notes` (`includes/class-post-page-notes-install.php`), created on activation and on the first load after an update (schema version option `post_page_notes_db_version`). Not in `wp_comments`, so nothing that lists, counts or feeds comments can show them.
- The editor talks to four REST routes under `post-page-notes/v1` (`includes/class-post-page-notes-rest.php`). Every route needs a user who can `edit_post` the post, on a content type with notes switched on; with cookie logins WordPress's REST nonce is required too. Editing needs the note's author; deleting needs its author or the type's `edit_others_posts`. Missing and forbidden IDs get the same answer. Limits: 5,000 characters a note, 500 notes a post, 20 new notes a minute per person.
- Notes are plain text: stored as typed (cleaned of invalid UTF-8 and control characters), escaped on output (`textContent` in the editor, `esc_html()` in PHP). Links are built as elements, `http`/`https` only. The editor's first data is printed with `JSON_HEX_TAG`.
- The block editor gets no meta box (a meta box there turns off the framed editor canvas): the classic box is registered with `__back_compat_meta_box`.
- Deleting a post for good deletes its notes (`deleted_post`). Deleting a user sets their notes' `user_id` to 0 and keeps the saved name, so a new account that reuses the ID can't take them over.

## Automatic updates

Updates come from https://github.com/jmsprsns/post-page-notes through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `post-page-notes.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `post-page-notes.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Tag that version and push the tag (or publish a GitHub release for it):

   ```bash
   git tag -a v1.0.1 -m "1.0.1" && git push origin v1.0.1
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'POST_PAGE_NOTES_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'POST_PAGE_NOTES_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
post-page-notes.php                         Plugin header, constants, boot, activation
uninstall.php                               Drops the notes table and options (every site of a network)
readme.txt                                  WordPress readme; feeds the "View details" popup
includes/
  class-post-page-notes-plugin.php          Main singleton, settings, who can use notes
  class-post-page-notes-install.php         Notes table (dbDelta) and its schema version
  class-post-page-notes-store.php           Reading and writing notes, counts, what the editor gets
  class-post-page-notes-rest.php            REST routes the editor uses
  class-post-page-notes-editor.php          Classic editor box, block editor sidebar, their scripts
  class-post-page-notes-columns.php         Notes column in post and page lists
  class-post-page-notes-notify.php          Reply emails
  class-post-page-notes-admin.php           Content Powered > Post and Page Notes page and Plugins-screen link
  class-post-page-notes-menu.php            Shared top-level "Content Powered" menu
  class-post-page-notes-feedback.php        Shared Submit Feedback and Report a Bug pages
  class-post-page-notes-updater.php         GitHub update checks (PUC wiring)
  class-post-page-notes-icons.php           Inline Lucide icon helper
assets/
  notes.js, notes.css                       The notes panel (both editors)
  sidebar.js                                Block editor sidebar and toolbar button
  admin.css, admin.js                       Loaded only on the settings page
  icon.svg                                  Icon on the update screens
  content-powered.svg                       Sumo logo for the Content Powered menu
icons/                                      The Lucide icons the plugin uses (plus LICENSE)
plugin-update-checker/                      Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-post-page-notes-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-post-page-notes-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/`. Print one inline with:

```php
echo Post_Page_Notes_Icons::get( 'package', 20 );           // name, size in px
echo Post_Page_Notes_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
