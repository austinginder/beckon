# Changelog

## [2.0.0] - Unreleased

The rebuild release. Beckon 2.0 drops every third-party library and ships an interface written for it alone, adds search across boards, live reload, WordPress publishing and a command line, and stays one file.

### 🎨 New interface
* **Built from scratch, no frameworks:** Vue, Tailwind, markdown-it, dayjs and Tribute are gone. The interface is now hand-written CSS and JavaScript inside `index.php`, with a custom SVG icon set and a custom Markdown engine. Beckon makes no requests to any CDN.
* **Design tokens with light and dark themes:** follows the system preference by default, with a toggle in the top bar and no flash of the wrong theme on load. Right-click the toggle to pick Light, Dark or System.
* **Inline card composer:** type a title and press Enter to add a card, instead of a "New Card" placeholder.
* **Labels with names on the board:** labels render as named pills rather than bare color bars.
* **Card window:** segmented Edit / Split / Preview control, resizable panes, collapsible activity drawer, and a details sidebar that becomes an overlay on phones.
* **Custom dialogs:** confirm and prompt dialogs replace the browser's native ones.
* **Boards overview:** a full-screen board picker with the version and update button in the footer.
* **Emoji picker:** `:` autocomplete in the editor and comments, and a reaction picker, both backed by an embedded emoji table.
* **Phone layout:** snap-scrolling columns, a compact top bar menu, full-screen card window.
* **Deep links:** `?board=<slug>` opens a board directly.

### 📦 New features
* **Search:** full-text search across all boards (SQLite FTS5) with `/` or Cmd+K.
* **Live reload:** the board updates when its files change on disk (Server-Sent Events).
* **Device sync API:** PIN-based pairing and a pull/push sync endpoint for companion apps.
* **Beckon CLI:** `beckon-cli.php` for creating, listing, importing and exporting cards. See `cli.md`.
* **Paste images:** paste a screenshot into the editor to upload it.
* **A proper updater:** downloads are verified against the sha256 checksum GitHub publishes for each release asset, the outgoing files are kept in `boards/.updates/` and can be restored from the boards screen, release notes show before installing, and the web endpoint no longer accepts a target version so nobody can downgrade a board from the browser. Update checks are cached for a week and only happen while someone has a board open. `php beckon-cli.php update` runs the same updater from a shell (`--check`, `--yes`, `--rollback`), lints the download first, and updates the CLI itself.

### 🔒 Security
* **Uploads can no longer run as code:** uploads keep only known safe file types, so a `.php` (or `.phtml`, `.htaccess` and so on) can't be dropped into a board and executed. Avatars accept images only. Imported Trello attachments of any other type are kept but saved as inert `.bin` files, and only http and https links are fetched. On Apache, `boards/.htaccess` adds a second layer.
* **Other websites can't drive your board:** Beckon still has no login and anyone who can open it can use it, but requests that a different website sends through your browser are now refused.
* **Card ids are checked:** card ids can only name files inside their own board, which closes a path to reading, overwriting or deleting other files, including the updater's state.
* **Safer Markdown:** raw HTML in cards and comments still works, but scripts, event handlers and `javascript:` links are stripped before anything is shown.
* **Sync hardening:** synced uploads and card ids are held to the same rules, and a pairing PIN is cancelled after five wrong guesses.
* **The updater re-reads the release from GitHub before installing** instead of trusting its cache.

### 🐛 Fixes
* **Two tabs on one board no longer overwrite each other:** every board now carries a revision number. When a tab saves on top of changes it has not seen (another tab, the CLI, a card moved in from another board), Beckon merges both sides and saves again: cards added, moved, edited, archived or deleted in either place all survive. Rapid edits are batched into fewer saves.
* **Touch reordering:** on phones and tablets, hold a card to pick it up and drag it within a list or to another one, with the board scrolling at the edges. Hold a list's header to move the list. Holding without moving still opens the card menu, and swipes still scroll.
* **Changes made elsewhere no longer get wiped:** a card added from another tab or the CLI while you had a card open is kept, and your own saves no longer hide other people's updates for two seconds.
* **Deletes and archives always hit the card you picked,** even if the board reloads while the confirm dialog is open.
* **Enter on a dialog's Cancel button cancels.**
* **Cmd+C no longer offers to archive** the card under the pointer.
* **Task checkboxes toggle the right line** for numbered, quoted and nested tasks and skip code blocks, and those tasks now count toward the card's progress.
* **Nothing is saved from a card that failed to load,** so a slow or broken load can't blank its description or comments.
* **Closing or reloading the tab keeps the last second of typing** and records the revision.
* **Moving cards between boards can't deadlock** when two tabs move cards in opposite directions.
* **Renaming a board keeps search results, covers and avatars working.**
* **Cards in long lists keep their height** and the list scrolls instead.
* **Archived cards from early versions open again.**
* **Live reload on PHP's built-in server:** `php -S` answers one request at a time unless `PHP_CLI_SERVER_WORKERS` says otherwise, and the live reload stream held that one request, so every other tab and API call stalled while a board was open. On a single-worker server Beckon now says so once and the board runs without live reload; the readme's quick start sets four workers. On every server the stream sends a keep-alive comment every 15 seconds so a closed tab frees its worker promptly.
* **Leftover 1.0 backup:** the 1.0 updater left `index.php.bak` next to the app, where a web server serves it as plain text. The first update check after upgrading moves it into `boards/.updates/` under the name the Restore link understands.
* **WordPress publishing:** images now become proper image blocks in the draft. The transform previously never matched and swapped the id and URL.
* **Standalone export:** the exported presentation is self-contained and no longer loads Tailwind from a CDN.

## [1.0.0] - 2025-12-15

### 🚀 New Features
* **Zero-Config Core:** Single-file PHP deployment with flat-file storage (JSON/Markdown)—no database needed.
* **📦 Trello Import:** Import full boards, lists, cards, and checklists via JSON export.
* **🎨 Modern UI:** Built with Vue 3 and Tailwind CSS, featuring a built-in Dark Mode 🌙 and resizable split-pane editor.
* **📝 Smart Editor:** Markdown support with live preview, drag-and-drop image uploads, and interactive checkboxes that update progress bars ✅.
* **📊 Editor Stats:** Real-time word count and estimated read time display in the markdown editor.
* **🕰️ Time Travel:** Detailed revision history for card descriptions with a slider UI to preview and restore past versions.
* **🗂 Kanban Flow:** Drag-and-drop cards between lists, move cards across different boards, and organize with color labels.
* **🖱️ Context Actions:** Right-click cards to quickly duplicate, delete, or edit them.
* **🗄️ Archiving:** Keep boards clean by archiving cards, complete with search and restore functionality.
* **⚙️ Board Management:** Rename boards, safely delete projects, and filter through boards via the quick switcher.
* **📅 Due Dates:** Set deadlines with visual color indicators for approaching or overdue tasks.
* **👤 User Identity:** Guest-mode settings to customize your display name and avatar color.
* **💬 Activity Log:** Track card history (moves/creation) and leave comments.
* **🔄 Auto-Sync:** Visual status indicators (Synced/Saving/Offline) with local buffering.
* **🔄 Self-Updater:** Built-in notification and one-click update mechanism for future releases.
* **🖼️ Drag & Drop Images:** Paste or drag images directly into the Markdown editor to auto-upload them to a local folder.
* **⌨️ Keyboard Shortcuts:** Quick actions for opening (Enter) and archiving (c) cards via keyboard.
