# Beckon

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

**Beckon** is a lightweight, open-source, self-hosted Kanban board that lives in a single PHP file. It requires no database setup, using a flat-file storage system (JSON and Markdown) to keep your data portable, human-readable, and easy to back up. The frontend is built with Vue 3 and Tailwind CSS (bundled via CDN), making deployment as simple as dropping one file onto your server.

## ✨ Features

* **Single-File Deployment:** No `composer install`, no `npm run build`, and no database required. Just `index.php`.
* **Flat-File Storage:**
    * **Boards:** Organized as directories.
    * **Cards:** Stored as individual `.md` files for content and `.json` for metadata.
    * **Portability:** Easy to sync with Dropbox/Nextcloud or version control with Git.
* **Smart Editor:**
    * Full Markdown rendering with a **resizable split-pane** preview.
    * **Drag-and-drop image uploads** directly into the editor.
    * **Task Tracking:** Interactive checkboxes (`- [ ]`) in card descriptions are automatically tracked as progress bars on the board view.
* **Kanban Workflow:**
    * Drag-and-drop cards between lists.
    * **Cross-Board Moving:** Move cards easily from one board to another via the sidebar actions.
    * **Due Dates:** Set deadlines with visual color indicators for approaching or overdue tasks.
* **Time Travel & Revisions:**
    * Detailed revision history for every card description.
    * Use the **history slider** to preview past versions and restore them with a single click.
* **Trello Import:** Native support for importing Trello JSON exports (lists, cards, checklists, labels, and comments).
* **User Identity:** Simple "Guest" identity settings to customize your display name and avatar color for the session.
* **Dark Mode:** Built-in toggle for light/dark themes.
* **Activity Log:** Comments and activity history (moves, creations) are tracked per card.

## 🚀 Installation

If you are on a server with PHP installed, you can get running in seconds:

```bash
mkdir beckon
cd beckon
curl -OL https://github.com/austinginder/beckon/releases/latest/download/index.php
php -S localhost:8000
```

## 🏝️ Using [Cove](https://cove.run) to run Beckon

You will need to have Cove installed and running: https://cove.run. Beckon is a simple PHP app so it can be added to Cove by running the following commands:

```bash
cove add beckon --plain
cd $(cove path beckon)
git clone https://github.com/austinginder/beckon.git .
```

Then open https://beckon.localhost in your browser.

Here is a section you can add to your `README.md`. It highlights the architectural differences and feature gaps, using the provided Trello export and source code to verify specific limitations (like the handling of `customFields` and the local-only nature of the user system).

## ⚠️ Beckon vs. Trello: Feature Parity & Limitations

Beckon is designed as a **local-first, markdown-centric** Kanban tool. It is not a 1:1 clone of Trello's cloud SaaS architecture. While it preserves the "spirit" of your boards, there are fundamental differences in how it handles users, data, and interactivity.

### 1. Local-First vs. Cloud SaaS
Beckon is a self-hosted, single-file PHP application. It does not rely on a central database or cloud infrastructure.
* **No Real-Time Collaboration:** Unlike Trello, updates do not push to other open clients in real-time (no WebSockets).
* **No Email/Notifications:** Beckon does not send transactional emails, push notifications, or reminders for due dates.
* **No API Integrations:** Trello Power-Ups (GitHub, Google Drive, Slack, etc.) are not supported.

### 2. User Authentication & Security
Beckon **does not have an authentication system**.
* **Single-User / Local Mode:** The application assumes it is running in a trusted local environment or behind your own server-level authentication (e.g., Basic Auth).
* **"Ghost" Accounts:** When importing from Trello, Beckon preserves member data (avatars, names) for historical accuracy in comments and activity logs. You can "Login As" these users via the UI to make edits under their persona, but there are no passwords, sessions, or permissions enforcing access control.

### 3. Trello Data Import Limitations
While the importer is robust, specific Trello-native features are not converted:
* **Reactions (Emoji):** Trello's JSON exports do not include emoji reaction data for comments. While Beckon supports adding reactions, your historical Trello reactions cannot be imported.
* **Custom Fields:** Trello `customFields` and `pluginData` are not currently parsed or displayed in the Beckon UI.
* **Stickers & Voting:** Visual stickers and card voting data are discarded during import.
* **Archived Data:** While archived cards are imported, granular "closed" list states or complex board permissions are simplified to fit Beckon's flat structure.
* **Automation:** Trello "Butler" rules and automation scripts are not executable in Beckon.

### 4. Markdown vs. Rich Text
Trello uses a specific flavor of Markdown mixed with proprietary rich text features. Beckon treats descriptions as **pure GitHub Flavored Markdown (GFM)**.
* **Formatting:** Some Trello-specific formatting might render slightly differently.
* **Checklists:** Beckon supports two types of checklists:
    1.  **UI Checklists:** Native, database-driven checklists (imported from Trello checklists).
    2.  **Markdown Tasks:** Standard `- [ ]` syntax inside the description (fully supported and interactive).

Use Beckon if you want full ownership of your data in flat files (`.md` and `.json`) and a fast, offline-capable interface. Stick with Trello if you need team management, extensive integrations, or enterprise-grade permissions.