=== WordPress Post and Page Notes ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: notes, editorial, collaboration, comments, team
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Threaded notes on posts and pages for your team, in the block editor and the classic editor, with a Notes column in your post lists.

== Description ==

Leave notes on any post or page for the people you work with: things to fix, changes to make, questions to answer. Notes sit right beside the content in the editor, and only people who can edit it ever see them.

= Threads and replies =

* Add a note about the whole page, or reply to any note. Replies stay under the note they answer.
* Each note shows who wrote it, as "Display Name (username)" from their WordPress account (or the display name only, if you prefer), with their avatar.
* Edit or delete your own notes. Editors and administrators can delete anyone's.
* **Resolve** a thread once it's done. Resolved threads fold away, and a new reply reopens one.
* Web addresses in notes become links.

= In both editors =

* **Block editor:** a "Page notes" (or "Post notes") button in the top toolbar shows how many threads are open and opens the notes in the sidebar. It's separate from WordPress's own block notes (6.9+), which attach to single blocks.
* **Classic editor:** a "Page notes" box under the content.
* Unsent text is kept if you close the sidebar, and the browser warns you before you leave with a note you haven't posted.
* New notes from other people show up while the panel is open.

= Notes column =

Post and page lists get a **Notes** column with how many notes each one has and how many threads are still open. Click it to open the editor right at its notes. Anyone can hide the column for themselves under Screen Options.

= Reply emails (off by default) =

Switch on reply emails and people get an email when someone replies to their note, with the reply, their own note, and a link straight to the thread. The thread's starter hears about replies too. Nobody is emailed about their own replies, and people who can no longer edit the post or page aren't emailed at all. Emails go to the address on each WordPress account, sent however your site sends email.

= Overview =

Content Powered > Post and Page Notes shows totals and a "Waiting on someone" list: every post and page with open threads, the latest note on each, newest first.

= Private by design =

* Notes are kept in their own database table, not in comments, so they never appear on your site, in feeds, in comment counts or in the REST comments endpoint.
* Everyone who can edit a post or page sees its notes; nobody else does. Contributors see notes on their own drafts.
* Notes are plain text. Nothing anyone types is ever run as HTML.
* Deleting a post for good deletes its notes. Deleting a user keeps their notes under their name, marked "(account deleted)".

Choose which content types get notes (posts and pages by default; products and custom types too) under Content Powered > Post and Page Notes.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `post-page-notes` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open any post or page: the notes are in the block editor's toolbar, or in a box under the classic editor.
4. Optional: go to Content Powered > Post and Page Notes to choose content types, the name format and reply emails.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Who can see the notes? =

Only logged-in people who can edit that post or page. Visitors never see them, and neither do users who can't edit it (an author can't see notes on pages, for example).

= How is this different from WordPress's own Notes? =

WordPress 6.9 added notes on single blocks in the block editor. These notes are about the whole post or page, work in the classic editor too, show up as a count in your post lists, and can email people when someone replies.

= Who can delete a note? =

The person who wrote it, plus anyone who can edit other people's posts (editors and administrators). A deleted note that has replies stays as "This note was deleted." so the thread still makes sense.

= Does it send emails? =

Not unless you switch on reply emails. They're off by default.

= What happens when I deactivate or delete the plugin? =

Deactivating keeps everything. Deleting the plugin from the Plugins screen removes its notes table and settings.

== Changelog ==

= 1.0.1 =
* Links to a page's notes open the block editor's sidebar at the top instead of part-way down.
* The overview's "Waiting on someone" list orders posts by their open threads and quotes the latest note from one of them, not from a resolved thread.
* Replies are indented less, so nested replies have more room in the sidebar.

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/post-page-notes/releases for the full release history.
