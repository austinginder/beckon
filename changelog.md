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

### 🐛 Fixes
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
