# Beckon

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)

**Beckon** is a lightweight, open-source, self-hosted Kanban board that lives in a single PHP file. 

It requires no database setup, using a flat-file storage system (JSON and Markdown) to keep your data portable, human-readable, and easy to back up. The frontend is built with Vue 3 and Tailwind CSS (bundled via CDN), making deployment as simple as dropping one file onto your server.

## ✨ Features

* **Single-File Deployment:** No `composer install`, no `npm run build`, and no database required. Just `index.php`.
* **Flat-File Storage:**
    * **Boards:** Organized as directories.
    * **Cards:** Stored as individual `.md` files for content and `.json` for metadata.
    * **Portability:** Easy to sync with Dropbox/Nextcloud or version control with Git.
* **Markdown Support:** Full Markdown rendering for card descriptions with a live split-pane preview.
* **Task Tracking:** Interactive checkboxes (`- [ ]`) in card descriptions are automatically tracked as progress bars on the board view.
* **Trello Import:** Native support for importing Trello JSON exports (lists, cards, checklists, labels, and comments).
* **Dark Mode:** Built-in toggle for light/dark themes.
* **Multi-Board Support:** Create multiple projects and switch between them easily.
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