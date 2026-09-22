# Beckon CLI

A command-line interface for programmatically managing Beckon cards. Perfect for automating card creation, bulk imports from external datasets, and integrating Beckon with other tools.

## Installation

The CLI is a single PHP file included with Beckon. No additional installation required.

```bash
# From the Beckon directory
php beckon-cli.php help
```

## Quick Start

```bash
# List all boards
php beckon-cli.php boards

# List cards in a board
php beckon-cli.php cards my-board

# Create a card
php beckon-cli.php card:create my-board \
  --list="To Do" \
  --title="New Feature"

# Bulk import from JSON
php beckon-cli.php import my-board cards.json

# Export board to JSON
php beckon-cli.php export my-board > backup.json
```

## Commands

### Board & List Commands

#### `boards`
List all available boards.

```bash
php beckon-cli.php boards
```

Output:
```
ID                    Name              Lists  Cards
────────────────────────────────────────────────────
my-project            My Project        4      23
another-board         Another Board     3      15
```

#### `board:create <title>`
Create a new board.

```bash
# Create an empty board
php beckon-cli.php board:create "My New Project"

# Create a board with initial lists
php beckon-cli.php board:create "Sprint Board" --lists="To Do,In Progress,Review,Done"

# Capture the board slug for scripting
BOARD_ID=$(php beckon-cli.php board:create "Automated Board" --quiet)
```

**Options:**
| Option | Description |
|--------|-------------|
| `--lists=LIST` | Comma-separated list of initial list names |

The board slug is automatically generated from the title (e.g., "My New Project" becomes `my-new-project`). If a board with that slug already exists, a number suffix is added.

#### `lists <board>`
List all lists in a board.

```bash
php beckon-cli.php lists my-board
```

Output:
```
ID              Title        Cards
──────────────────────────────────
l1765764087544  To Do        5
l1765764141776  In Progress  3
l1765764200000  Done         12

  + 2 cards in archive
```

#### `cards <board>`
List all cards in a board.

```bash
# List all cards
php beckon-cli.php cards my-board

# Filter by list
php beckon-cli.php cards my-board --list="To Do"

# Include archived cards
php beckon-cli.php cards my-board --include-archive
```

Output:
```
ID                        Title              List     Labels    Due
─────────────────────────────────────────────────────────────────────
2026-01-29_abc123         New Feature        To Do    Priority  2026-02-15
2026-01-28_def456         Bug Fix            To Do    Bug       -
```

### Card Commands

#### `card:show <board> <card-id>`
Display detailed information about a card.

```bash
php beckon-cli.php card:show my-board 2026-01-29_abc123
```

Output:
```
Card: New Feature
──────────────────────────────────────────────────
ID:       2026-01-29_abc123
List:     To Do
Labels:   [orange] Priority, [blue] Feature
Due:      2026-02-15
Created:  2026-01-29T10:30:00+00:00

Description:
Implement the new dashboard feature.

## Requirements
- [ ] Design mockups
- [x] API endpoints

Checklists:
  Tasks:
    ✓ Create database schema
    ○ Build API endpoints
    ○ Frontend components

Comments: (2)
  [2026-01-29] Alice: Looks good!
  [2026-01-28] Bob: Started working on this.
```

#### `card:create <board>`
Create a new card.

```bash
# Basic card
php beckon-cli.php card:create my-board \
  --list="To Do" \
  --title="New Feature"

# Full options
php beckon-cli.php card:create my-board \
  --list="To Do" \
  --title="New Feature" \
  --description="Implement the thing" \
  --labels='[{"color":"orange","name":"Priority"}]' \
  --due="2026-02-15" \
  --start="2026-01-29" \
  --assignees='["user-id-1","user-id-2"]' \
  --checklist='{"name":"Tasks","items":["Item 1","Item 2"]}'

# From a JSON file
php beckon-cli.php card:create my-board --json=card.json

# From stdin (heredoc)
php beckon-cli.php card:create my-board --json=- << 'EOF'
{
  "title": "Card from Heredoc",
  "list": "To Do",
  "description": "# Description\n\nSupports **full markdown**.",
  "labels": [{"color": "blue", "name": "Feature"}]
}
EOF
```

**Options:**
| Option | Description |
|--------|-------------|
| `--list=NAME` | Target list name (required) |
| `--title=TEXT` | Card title (required) |
| `--description=TEXT` | Card description (Markdown) |
| `--desc-file=PATH` | Read description from a file |
| `--labels=JSON` | Labels as JSON array |
| `--due=DATE` | Due date (YYYY-MM-DD) |
| `--start=DATE` | Start date (YYYY-MM-DD) |
| `--assignees=JSON` | Assignee user IDs as JSON array |
| `--checklist=JSON` | Checklist as JSON object |
| `--json=PATH` | Read all card data from JSON file (use `-` for stdin) |

#### `card:update <board> <card-id>`
Update an existing card.

```bash
# Update title
php beckon-cli.php card:update my-board 2026-01-29_abc123 \
  --title="Updated Title"

# Update multiple fields
php beckon-cli.php card:update my-board 2026-01-29_abc123 \
  --title="Updated Title" \
  --description="New description" \
  --due="2026-03-01" \
  --labels='[{"color":"green","name":"Done"}]'

# Update description from file
php beckon-cli.php card:update my-board 2026-01-29_abc123 \
  --desc-file=description.md
```

#### `card:delete <board> <card-id>`
Permanently delete a card.

```bash
php beckon-cli.php card:delete my-board 2026-01-29_abc123
```

#### `card:archive <board> <card-id>`
Move a card to the archive.

```bash
php beckon-cli.php card:archive my-board 2026-01-29_abc123
```

#### `card:unarchive <board> <card-id>`
Restore a card from the archive.

```bash
# Restore to first list
php beckon-cli.php card:unarchive my-board 2026-01-29_abc123

# Restore to specific list
php beckon-cli.php card:unarchive my-board 2026-01-29_abc123 --list="To Do"
```

### Bulk Commands

#### `import <board> <file.json>`
Bulk import cards from a JSON file or stdin.

```bash
# Import cards (list specified in JSON)
php beckon-cli.php import my-board cards.json

# Import all cards to a default list
php beckon-cli.php import my-board cards.json --list="Imported"

# Preview import without making changes
php beckon-cli.php import my-board cards.json --dry-run

# Import from stdin (use - as filename)
echo '[{"title": "Card", "list": "Inbox"}]' | php beckon-cli.php import my-board -

# Import using heredoc (great for scripts)
php beckon-cli.php import my-board - --list="Imported" << 'EOF'
[
  {
    "title": "First Card",
    "description": "Supports **markdown** and\nmulti-line content."
  },
  {
    "title": "Second Card",
    "labels": [{"color": "orange", "name": "Priority"}]
  }
]
EOF
```

**Behavior:**
- Use `-` as filename to read from stdin (enables piping and heredocs)
- Lists that don't exist are automatically created
- Cards without a `list` field use the `--list` default
- Invalid data is skipped with warnings
- Search index is updated automatically

#### `export <board>`
Export cards to JSON format.

```bash
# Export all cards
php beckon-cli.php export my-board > backup.json

# Export specific list
php beckon-cli.php export my-board --list="Done" > done.json

# Include archived cards
php beckon-cli.php export my-board --include-archive > full-backup.json
```

### Update Commands

#### `update`
Update Beckon (`index.php` and `beckon-cli.php`) from the latest GitHub release.

```bash
# See whether a newer release exists (always asks GitHub)
php beckon-cli.php update --check

# Install it, with a prompt
php beckon-cli.php update

# Install without a prompt, for cron
php beckon-cli.php update --yes --quiet

# Put back the copy saved before the last update
php beckon-cli.php update --rollback
```

**Options:**
| Option | Description |
|--------|-------------|
| `--check` | Report the installed and latest versions and stop |
| `--yes` | Skip the confirmation prompt |
| `--rollback` | Restore the previous copies from `boards/.updates/` |

**Behavior:**
- Every download is verified against the sha256 checksum GitHub publishes for the release asset (or the release's `SHA256SUMS` file). A release without a checksum is refused.
- The downloaded file is checked with `php -l` before it replaces anything.
- The outgoing copies are kept in `boards/.updates/` (the last three) and `--rollback` restores the newest one.
- A git checkout is left alone; use `git pull` there.
- The web interface installs through the same code. The CLI is the way to update when the web server cannot write to `index.php`, or to run updates unattended:

```bash
# weekly, Sunday 04:00
0 4 * * 0 cd /var/www/beckon && php beckon-cli.php update --yes --quiet
```

## JSON Format

### Import Format

```json
[
  {
    "title": "Card Title",
    "list": "List Name",
    "description": "Markdown description with **formatting**",
    "labels": [
      {"color": "orange", "name": "Priority"},
      {"color": "blue", "name": "Feature"}
    ],
    "dueDate": "2026-02-15",
    "startDate": "2026-01-29",
    "assignees": ["user-id-1", "user-id-2"],
    "checklists": [
      {
        "name": "Tasks",
        "items": [
          {"name": "Task 1", "done": false},
          {"name": "Task 2", "done": true},
          "Task 3 (simple format)"
        ]
      }
    ]
  }
]
```

**Required fields:** `title`

**Optional fields:** `list`, `description`, `labels`, `dueDate`, `startDate`, `assignees`, `checklists`

### Card JSON File

For `card:create --json=file.json`:

```json
{
  "title": "Card Title",
  "list": "To Do",
  "description": "Description here",
  "labels": [{"color": "orange", "name": "Priority"}],
  "dueDate": "2026-02-15"
}
```

### Valid Label Colors

```
orange, green, red, yellow, purple, blue, sky, lime, pink, black
```

## Global Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Preview changes without making them |
| `--yes` | Skip confirmation prompts (`update`) |
| `--quiet` | Suppress output (useful for scripting) |
| `--no-color` | Disable colored terminal output |
| `--help` | Show help message |

## Scripting Examples

### Create card and capture ID

```bash
CARD_ID=$(php beckon-cli.php card:create my-board \
  --list="To Do" \
  --title="Automated Card" \
  --quiet)

echo "Created card: $CARD_ID"
```

### Import from CSV (using jq)

```bash
# Convert CSV to JSON and import
cat data.csv | \
  python3 -c "import csv,json,sys; print(json.dumps([dict(r) for r in csv.DictReader(sys.stdin)]))" | \
  php beckon-cli.php import my-board -
```

### Batch operations

```bash
# Archive all cards in "Done" list
php beckon-cli.php cards my-board --list="Done" --no-color | \
  tail -n +3 | \
  awk '{print $1}' | \
  xargs -I {} php beckon-cli.php card:archive my-board {}
```

### Backup all boards

```bash
mkdir -p backups/$(date +%Y-%m-%d)
for board in $(php beckon-cli.php boards --no-color | tail -n +3 | awk '{print $1}'); do
  php beckon-cli.php export "$board" --include-archive > "backups/$(date +%Y-%m-%d)/$board.json"
done
```

### Sync from external API

```bash
# Fetch data from API and import as cards
curl -s "https://api.example.com/items" | \
  jq '[.[] | {title: .name, list: "Inbox", description: .details, dueDate: .deadline}]' | \
  php beckon-cli.php import my-board -
```

### Using heredocs in scripts

Heredocs are ideal when you need to embed JSON directly in shell scripts:

```bash
#!/bin/bash
# Example: Create cards for a sprint

BOARD="sprint-42"
DUE_DATE="2026-02-15"

php beckon-cli.php import "$BOARD" - << EOF
[
  {
    "title": "Setup development environment",
    "list": "To Do",
    "labels": [{"color": "blue", "name": "Infrastructure"}],
    "dueDate": "$DUE_DATE",
    "description": "## Tasks\n- [ ] Clone repo\n- [ ] Install dependencies\n- [ ] Configure env"
  },
  {
    "title": "Implement user authentication",
    "list": "To Do", 
    "labels": [{"color": "orange", "name": "Feature"}],
    "checklists": [
      {
        "name": "Subtasks",
        "items": ["Design login flow", "Build API endpoints", "Create UI components"]
      }
    ]
  }
]
EOF

echo "Sprint cards created!"
```

**Tip:** Use `<< 'EOF'` (quoted) to prevent variable expansion, or `<< EOF` (unquoted) to allow bash variables like `$DUE_DATE` in your JSON.

## Error Handling

The CLI uses exit codes for scripting:
- `0` - Success
- `1` - Error (missing arguments, invalid data, etc.)

Errors are printed to stderr:
```bash
php beckon-cli.php card:show invalid-board invalid-id
# ✗ Board not found: invalid-board
```

Warnings (non-fatal issues) are printed after the operation:
```bash
php beckon-cli.php import my-board cards.json
# ✓ Imported 5 cards
# ⚠ Card at index 3 has invalid label color 'invalid' - using 'slate'
```

## Search Index

The CLI automatically updates Beckon's search index (SQLite FTS5) when cards are created, updated, or deleted. This ensures cards are immediately searchable in the web interface.

If the search index is unavailable (e.g., SQLite not installed), the CLI will continue to work but skip index updates silently.

## File Locking

The CLI uses the same file locking mechanism as the Beckon web interface to prevent data corruption from concurrent access. Operations that modify board data acquire an exclusive lock before writing.

## Limitations

- The CLI must be run on the same server as Beckon (local file access required)
- Comments cannot be added via CLI (only through web interface)
- User management is not available via CLI
- Board deletion is not available via CLI (use web interface for safety)
