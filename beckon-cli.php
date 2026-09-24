#!/usr/bin/env php
<?php
/**
 * Beckon CLI - Command-line interface for managing Beckon cards
 * 
 * Usage: php beckon-cli.php <command> [arguments] [options]
 * 
 * Run `php beckon-cli.php help` for full documentation.
 */

namespace BeckonCLI;

// ============================================
// CONFIGURATION
// ============================================

define('CLI_VERSION', '2.0.0');
define('BOARDS_DIR', __DIR__ . '/boards');
define('VALID_COLORS', ['orange', 'green', 'red', 'yellow', 'purple', 'blue', 'sky', 'lime', 'pink', 'black', 'slate']);

// ============================================
// TERMINAL OUTPUT HELPERS
// ============================================

class Output {
    private static $colors = [
        'reset'   => "\033[0m",
        'bold'    => "\033[1m",
        'dim'     => "\033[2m",
        'red'     => "\033[31m",
        'green'   => "\033[32m",
        'yellow'  => "\033[33m",
        'blue'    => "\033[34m",
        'magenta' => "\033[35m",
        'cyan'    => "\033[36m",
        'white'   => "\033[37m",
    ];
    
    private static $useColors = true;
    
    public static function disableColors() {
        self::$useColors = false;
    }
    
    public static function color($text, $color) {
        if (!self::$useColors || !isset(self::$colors[$color])) return $text;
        return self::$colors[$color] . $text . self::$colors['reset'];
    }
    
    public static function success($msg) { echo self::color("✓ $msg", 'green') . "\n"; }
    public static function error($msg)   { echo self::color("✗ $msg", 'red') . "\n"; }
    public static function warn($msg)    { echo self::color("⚠ $msg", 'yellow') . "\n"; }
    public static function info($msg)    { echo self::color("→ $msg", 'cyan') . "\n"; }
    public static function dim($msg)     { echo self::color($msg, 'dim') . "\n"; }
    
    public static function line($msg = '') { echo "$msg\n"; }
    
    public static function table($headers, $rows) {
        if (empty($rows)) {
            self::dim("  (no results)");
            return;
        }
        
        // Calculate column widths
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }
        
        // Header
        $headerLine = '';
        foreach ($headers as $i => $h) {
            $headerLine .= str_pad($h, $widths[$i] + 2);
        }
        echo self::color($headerLine, 'bold') . "\n";
        echo str_repeat('─', array_sum($widths) + count($widths) * 2) . "\n";
        
        // Rows
        foreach ($rows as $row) {
            $line = '';
            foreach ($row as $i => $cell) {
                $line .= str_pad($cell, $widths[$i] + 2);
            }
            echo "$line\n";
        }
    }
}

// ============================================
// ARGUMENT PARSER
// ============================================

class Args {
    public $command;
    public $args = [];
    public $options = [];
    
    public function __construct($argv) {
        array_shift($argv); // Remove script name
        
        foreach ($argv as $arg) {
            if (strpos($arg, '--') === 0) {
                // Option: --key=value or --flag
                $arg = substr($arg, 2);
                if (strpos($arg, '=') !== false) {
                    [$key, $value] = explode('=', $arg, 2);
                    $this->options[$key] = $value;
                } else {
                    $this->options[$arg] = true;
                }
            } else {
                // Positional argument
                if ($this->command === null) {
                    $this->command = $arg;
                } else {
                    $this->args[] = $arg;
                }
            }
        }
    }
    
    public function get($index, $default = null) {
        return $this->args[$index] ?? $default;
    }
    
    public function opt($key, $default = null) {
        return $this->options[$key] ?? $default;
    }
    
    public function has($key) {
        return isset($this->options[$key]);
    }
}

// ============================================
// FILE OPERATIONS (mirrors index.php patterns)
// ============================================

class FileOps {
    
    public static function atomicWrite($filepath, $data) {
        $content = (is_array($data) || is_object($data)) 
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) 
            : $data;

        $tempPath = $filepath . '.tmp.' . uniqid();
        
        $fp = fopen($tempPath, 'w');
        if (!$fp) throw new \Exception("Could not open temp file: $tempPath");

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new \Exception("Could not lock file: $tempPath");
        }

        $written = fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($written === false) {
            @unlink($tempPath);
            throw new \Exception("Failed to write data to $tempPath");
        }

        if (DIRECTORY_SEPARATOR === '\\' && file_exists($filepath)) {
            @unlink($filepath);
        }

        if (!rename($tempPath, $filepath)) {
            @unlink($tempPath);
            throw new \Exception("Failed to move temp file to $filepath");
        }

        @chmod($filepath, 0644);
        return true;
    }
    
    public static function withBoardLock($boardId, callable $callback) {
        $lockFile = BOARDS_DIR . '/' . $boardId . '/lock';
        
        // Ensure directory exists
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            throw new \Exception("Board directory does not exist: $boardId");
        }
        
        $fp = fopen($lockFile, 'c+');
        if (!$fp) throw new \Exception("Could not open lock file");

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new \Exception("Could not acquire lock");
        }

        try {
            return $callback();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    
    public static function slugify($text) {
        $text = preg_replace('/[^a-z0-9-_]/i', '-', strtolower($text));
        return trim(preg_replace('/-+/', '-', $text), '-');
    }
    
    public static function generateCardId() {
        return date('Y-m-d') . '_' . uniqid();
    }
}

// ============================================
// SEARCH INDEX INTEGRATION
// ============================================

class SearchIndexCLI {
    private $db;
    
    public function __construct() {
        $dbPath = BOARDS_DIR . '/search.db';
        if (!file_exists($dbPath)) {
            $this->db = null;
            return;
        }
        
        try {
            $this->db = new \PDO("sqlite:$dbPath");
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->db = null;
        }
    }
    
    public function isAvailable() {
        return $this->db !== null;
    }
    
    public function indexCard($boardId, $boardName, $cardId, $title, $description, $comments, $labels) {
        if (!$this->db) return;
        
        $this->removeCard($cardId);
        
        $labelText = is_array($labels) ? implode(' ', array_column($labels, 'name')) : '';
        $commentText = is_array($comments) ? implode("\n", array_column($comments, 'text')) : '';
        
        $stmt = $this->db->prepare(
            "INSERT INTO cards_fts (board_id, card_id, title, description, comments, labels) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$boardId, $cardId, $title, $description ?? '', $commentText, $labelText]);
        
        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO card_index (card_id, board_id, board_name, updated_at, title, has_description, label_json) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $cardId, 
            $boardId, 
            $boardName,
            time(), 
            $title, 
            !empty(trim($description ?? '')) ? 1 : 0,
            json_encode($labels)
        ]);
    }
    
    public function removeCard($cardId) {
        if (!$this->db) return;
        $this->db->prepare("DELETE FROM cards_fts WHERE card_id = ?")->execute([$cardId]);
        $this->db->prepare("DELETE FROM card_index WHERE card_id = ?")->execute([$cardId]);
    }
}

// ============================================
// BOARD OPERATIONS
// ============================================

class Board {
    public $id;
    public $dir;
    public $layout;
    
    public function __construct($boardId) {
        $this->id = FileOps::slugify($boardId);
        if (!$this->id) throw new \Exception("Invalid board ID");
        
        $this->dir = BOARDS_DIR . '/' . $this->id;
        
        if (!is_dir($this->dir)) {
            throw new \Exception("Board not found: {$this->id}");
        }
        
        $this->layout = $this->loadLayout();
    }
    
    public static function listAll() {
        $boards = [];
        foreach (glob(BOARDS_DIR . '/*', GLOB_ONLYDIR) as $dir) {
            $id = basename($dir);
            $layout = json_decode(@file_get_contents("$dir/layout.json"), true);
            $boards[] = [
                'id' => $id,
                'name' => $layout['title'] ?? $id,
                'lists' => count($layout['lists'] ?? []),
                'cards' => array_sum(array_map(fn($l) => count($l['cards'] ?? []), $layout['lists'] ?? []))
            ];
        }
        return $boards;
    }
    
    private function loadLayout() {
        $path = "{$this->dir}/layout.json";
        $data = json_decode(@file_get_contents($path), true);
        
        if (!$data) {
            $data = ['version' => 2, 'title' => $this->id, 'lists' => [], 'archive' => []];
        }
        
        if (!isset($data['archive'])) $data['archive'] = [];
        if (!isset($data['lists'])) $data['lists'] = [];
        $this->loadedRev = (int) ($data['rev'] ?? 0);
        
        return $data;
    }
    private $loadedRev = 0;
    
    public function saveLayout() {
        FileOps::withBoardLock($this->id, function() {
            // Bump the revision so open browser tabs know their copy is stale.
            $cur = json_decode(@file_get_contents("{$this->dir}/layout.json"), true);
            $diskRev = (int) ($cur['rev'] ?? 0);
            // Someone saved the board while this command ran: refuse rather than overwrite them.
            if ($diskRev !== $this->loadedRev) throw new \Exception("The board changed while this command ran. Nothing was saved; run it again.");
            $this->layout['rev'] = $diskRev + 1;
            $this->loadedRev = $this->layout['rev'];
            unset($this->layout['users']);
            FileOps::atomicWrite("{$this->dir}/layout.json", $this->layout);
        });
    }
    
    public function getName() {
        return $this->layout['title'] ?? $this->id;
    }
    
    public function getLists() {
        return $this->layout['lists'] ?? [];
    }
    
    public function getListByName($name) {
        foreach ($this->layout['lists'] as &$list) {
            if (strcasecmp($list['title'], $name) === 0) {
                return $list;
            }
        }
        return null;
    }
    
    public function getOrCreateList($name) {
        foreach ($this->layout['lists'] as $i => &$list) {
            if (strcasecmp($list['title'], $name) === 0) {
                return $i;
            }
        }
        
        // Create new list
        $newList = [
            'id' => 'l' . time() . rand(100, 999),
            'title' => $name,
            'cards' => []
        ];
        $this->layout['lists'][] = $newList;
        return count($this->layout['lists']) - 1;
    }
    
    public function getAllCards($includeArchive = false) {
        $cards = [];
        foreach ($this->layout['lists'] as $list) {
            foreach ($list['cards'] ?? [] as $card) {
                $card['_list'] = $list['title'];
                $card['_archived'] = false;
                $cards[] = $card;
            }
        }
        
        if ($includeArchive) {
            foreach ($this->layout['archive'] ?? [] as $card) {
                $card['_list'] = '(Archive)';
                $card['_archived'] = true;
                $cards[] = $card;
            }
        }
        
        return $cards;
    }
    
    public function findCard($cardId) {
        // Check lists
        foreach ($this->layout['lists'] as $listIdx => &$list) {
            foreach ($list['cards'] as $cardIdx => &$card) {
                // Compare as strings to handle both integer and string IDs
                if ((string)$card['id'] === (string)$cardId) {
                    return [
                        'card' => &$card,
                        'listIdx' => $listIdx,
                        'cardIdx' => $cardIdx,
                        'list' => &$list,
                        'archived' => false
                    ];
                }
            }
        }
        
        // Check archive
        foreach ($this->layout['archive'] as $cardIdx => &$card) {
            // Compare as strings to handle both integer and string IDs
            if ((string)$card['id'] === (string)$cardId) {
                return [
                    'card' => &$card,
                    'listIdx' => -1,
                    'cardIdx' => $cardIdx,
                    'list' => null,
                    'archived' => true
                ];
            }
        }
        
        return null;
    }
    
    public function addCard($listName, $cardData) {
        $listIdx = $this->getOrCreateList($listName);
        
        $cardId = FileOps::generateCardId();
        
        // Build layout card summary
        $layoutCard = [
            'id' => $cardId,
            'title' => $cardData['title'],
            'labels' => $cardData['labels'] ?? [],
            'dueDate' => $cardData['dueDate'] ?? null,
            'startDate' => $cardData['startDate'] ?? null,
            'assignees' => $cardData['assignees'] ?? [],
            'created_at' => date('c'),
            'commentCount' => 0,
            'hasDesc' => !empty($cardData['description'] ?? ''),
            'hasAtt' => false,
            'checklistStats' => null,
            'descStats' => null
        ];
        
        // Calculate checklist stats
        if (!empty($cardData['checklists'])) {
            $total = 0;
            $done = 0;
            foreach ($cardData['checklists'] as $cl) {
                foreach ($cl['items'] ?? [] as $item) {
                    $total++;
                    // Handle both string items (simple format) and array items
                    if (is_array($item)) {
                        if (($item['state'] ?? '') === 'complete' || ($item['done'] ?? false) === true) {
                            $done++;
                        }
                    }
                    // String items are always incomplete
                }
            }
            if ($total > 0) {
                $layoutCard['checklistStats'] = ['total' => $total, 'done' => $done];
            }
        }
        
        // Calculate description checkbox stats
        if (!empty($cardData['description'])) {
            $total = preg_match_all('/- \[[ xX]\]/', $cardData['description']);
            $done = preg_match_all('/- \[[xX]\]/', $cardData['description']);
            if ($total > 0) {
                $layoutCard['descStats'] = ['total' => $total, 'done' => $done];
            }
        }
        
        // Add to list
        $this->layout['lists'][$listIdx]['cards'][] = $layoutCard;
        
        // Write description file
        FileOps::atomicWrite("{$this->dir}/{$cardId}.md", $cardData['description'] ?? '');
        
        // Build and write metadata file
        $meta = [
            'title' => $cardData['title'],
            'labels' => $cardData['labels'] ?? [],
            'comments' => [],
            'activity' => [
                ['text' => 'Created via CLI', 'date' => date('c')]
            ],
            'revisions' => [],
            'assigned_to' => $cardData['assignees'] ?? [],
            'checklists' => $this->normalizeChecklists($cardData['checklists'] ?? [])
        ];
        FileOps::atomicWrite("{$this->dir}/{$cardId}.json", $meta);
        
        return $cardId;
    }
    
    public function updateCard($cardId, $updates) {
        $found = $this->findCard($cardId);
        if (!$found) {
            throw new \Exception("Card not found: $cardId");
        }
        
        $card = &$found['card'];
        $metaPath = "{$this->dir}/{$cardId}.json";
        $mdPath = "{$this->dir}/{$cardId}.md";
        
        $meta = json_decode(@file_get_contents($metaPath), true) ?? [];
        
        // Update fields
        if (isset($updates['title'])) {
            $card['title'] = $updates['title'];
            $meta['title'] = $updates['title'];
        }
        
        if (isset($updates['labels'])) {
            $card['labels'] = $updates['labels'];
            $meta['labels'] = $updates['labels'];
        }
        
        if (isset($updates['dueDate'])) {
            $card['dueDate'] = $updates['dueDate'] ?: null;
        }
        
        if (isset($updates['startDate'])) {
            $card['startDate'] = $updates['startDate'] ?: null;
        }
        
        if (isset($updates['assignees'])) {
            $card['assignees'] = $updates['assignees'];
            $meta['assigned_to'] = $updates['assignees'];
        }
        
        if (isset($updates['description'])) {
            FileOps::atomicWrite($mdPath, $updates['description']);
            $card['hasDesc'] = !empty(trim($updates['description']));
            $card['hasAtt'] = (strpos($updates['description'], '/uploads/') !== false);
            
            // Recalculate description checkbox stats
            $total = preg_match_all('/- \[[ xX]\]/', $updates['description']);
            $done = preg_match_all('/- \[[xX]\]/', $updates['description']);
            $card['descStats'] = $total > 0 ? ['total' => $total, 'done' => $done] : null;
        }
        
        if (isset($updates['checklists'])) {
            $meta['checklists'] = $this->normalizeChecklists($updates['checklists']);
            
            // Recalculate checklist stats
            $total = 0;
            $done = 0;
            foreach ($meta['checklists'] as $cl) {
                foreach ($cl['items'] ?? [] as $item) {
                    $total++;
                    if (($item['state'] ?? '') === 'complete') $done++;
                }
            }
            $card['checklistStats'] = $total > 0 ? ['total' => $total, 'done' => $done] : null;
        }
        
        // Add activity
        $meta['activity'] = $meta['activity'] ?? [];
        array_unshift($meta['activity'], ['text' => 'Updated via CLI', 'date' => date('c')]);
        
        FileOps::atomicWrite($metaPath, $meta);
        
        return true;
    }
    
    public function moveCard($cardId, $targetListName) {
        $found = $this->findCard($cardId);
        if (!$found) {
            throw new \Exception("Card not found: $cardId");
        }

        $card = $found['card'];

        // Remove from current location
        if ($found['archived']) {
            array_splice($this->layout['archive'], $found['cardIdx'], 1);
        } else {
            array_splice($this->layout['lists'][$found['listIdx']]['cards'], $found['cardIdx'], 1);
        }

        // Add to target list (creates list if it doesn't exist)
        $targetIdx = $this->getOrCreateList($targetListName);
        array_unshift($this->layout['lists'][$targetIdx]['cards'], $card);

        return true;
    }

    public function deleteCard($cardId) {
        $found = $this->findCard($cardId);
        if (!$found) {
            throw new \Exception("Card not found: $cardId");
        }
        
        // Remove from layout
        if ($found['archived']) {
            array_splice($this->layout['archive'], $found['cardIdx'], 1);
        } else {
            array_splice($this->layout['lists'][$found['listIdx']]['cards'], $found['cardIdx'], 1);
        }
        
        // Delete files
        @unlink("{$this->dir}/{$cardId}.md");
        @unlink("{$this->dir}/{$cardId}.json");
        
        return true;
    }
    
    public function archiveCard($cardId) {
        $found = $this->findCard($cardId);
        if (!$found) {
            throw new \Exception("Card not found: $cardId");
        }
        
        if ($found['archived']) {
            throw new \Exception("Card is already archived");
        }
        
        $card = $found['card'];
        
        // Remove from list
        array_splice($this->layout['lists'][$found['listIdx']]['cards'], $found['cardIdx'], 1);
        
        // Add to archive
        $this->layout['archive'][] = $card;
        
        // Update activity
        $metaPath = "{$this->dir}/{$cardId}.json";
        $meta = json_decode(@file_get_contents($metaPath), true) ?? [];
        $meta['activity'] = $meta['activity'] ?? [];
        array_unshift($meta['activity'], ['text' => 'Archived via CLI', 'date' => date('c')]);
        FileOps::atomicWrite($metaPath, $meta);
        
        return true;
    }
    
    public function unarchiveCard($cardId, $targetList = null) {
        $found = $this->findCard($cardId);
        if (!$found) {
            throw new \Exception("Card not found: $cardId");
        }
        
        if (!$found['archived']) {
            throw new \Exception("Card is not archived");
        }
        
        $card = $found['card'];
        
        // Remove from archive
        array_splice($this->layout['archive'], $found['cardIdx'], 1);
        
        // Add to target list (or first list)
        if ($targetList) {
            $listIdx = $this->getOrCreateList($targetList);
        } else {
            $listIdx = 0;
            if (empty($this->layout['lists'])) {
                $this->getOrCreateList('Restored');
                $listIdx = 0;
            }
        }
        
        array_unshift($this->layout['lists'][$listIdx]['cards'], $card);
        
        // Update activity
        $metaPath = "{$this->dir}/{$cardId}.json";
        $meta = json_decode(@file_get_contents($metaPath), true) ?? [];
        $meta['activity'] = $meta['activity'] ?? [];
        array_unshift($meta['activity'], ['text' => 'Restored from archive via CLI', 'date' => date('c')]);
        FileOps::atomicWrite($metaPath, $meta);
        
        return true;
    }
    
    public function getCardDetails($cardId) {
        $found = $this->findCard($cardId);
        if (!$found) {
            throw new \Exception("Card not found: $cardId");
        }
        
        $card = $found['card'];
        $description = @file_get_contents("{$this->dir}/{$cardId}.md") ?: '';
        $meta = json_decode(@file_get_contents("{$this->dir}/{$cardId}.json"), true) ?? [];
        
        return [
            'id' => $cardId,
            'title' => $card['title'],
            'list' => $found['archived'] ? '(Archive)' : $found['list']['title'],
            'archived' => $found['archived'],
            'description' => $description,
            'labels' => $card['labels'] ?? [],
            'dueDate' => $card['dueDate'] ?? null,
            'startDate' => $card['startDate'] ?? null,
            'assignees' => $card['assignees'] ?? [],
            'created_at' => $card['created_at'] ?? null,
            'comments' => $meta['comments'] ?? [],
            'activity' => $meta['activity'] ?? [],
            'checklists' => $meta['checklists'] ?? []
        ];
    }
    
    private function normalizeChecklists($checklists) {
        $normalized = [];
        foreach ($checklists as $cl) {
            $items = [];
            foreach ($cl['items'] ?? [] as $item) {
                if (is_string($item)) {
                    $items[] = [
                        'id' => uniqid(),
                        'name' => $item,
                        'state' => 'incomplete',
                        'pos' => count($items) + 1
                    ];
                } else {
                    $items[] = [
                        'id' => $item['id'] ?? uniqid(),
                        'name' => $item['name'] ?? $item['text'] ?? '',
                        'state' => ($item['done'] ?? false) ? 'complete' : ($item['state'] ?? 'incomplete'),
                        'pos' => $item['pos'] ?? count($items) + 1
                    ];
                }
            }
            
            $normalized[] = [
                'id' => $cl['id'] ?? uniqid(),
                'name' => $cl['name'] ?? 'Checklist',
                'items' => $items
            ];
        }
        return $normalized;
    }
}

// ============================================
// CLI COMMANDS
// ============================================

class CLI {
    private $args;
    private $searchIndex;
    private $quiet = false;
    private $dryRun = false;
    private $warnings = [];
    
    public function __construct($argv) {
        $this->args = new Args($argv);
        $this->quiet = $this->args->has('quiet');
        $this->dryRun = $this->args->has('dry-run');
        
        if ($this->args->has('no-color') || !stream_isatty(STDOUT)) {
            Output::disableColors();
        }
        
        $this->searchIndex = new SearchIndexCLI();
    }
    
    public function run() {
        if (!is_dir(BOARDS_DIR)) {
            Output::error("Boards directory not found: " . BOARDS_DIR);
            exit(1);
        }
        
        $command = $this->args->command ?? 'help';
        
        try {
            switch ($command) {
                case 'help':
                case '--help':
                case '-h':
                    $this->showHelp();
                    break;
                    
                case 'version':
                case '--version':
                case '-v':
                    echo "Beckon CLI v" . CLI_VERSION . "\n";
                    break;
                    
                case 'boards':
                    $this->listBoards();
                    break;
                    
                case 'board:create':
                    $this->createBoard();
                    break;
                    
                case 'lists':
                    $this->listLists();
                    break;
                    
                case 'cards':
                    $this->listCards();
                    break;
                    
                case 'card:show':
                    $this->showCard();
                    break;
                    
                case 'card:create':
                    $this->createCard();
                    break;
                    
                case 'card:update':
                    $this->updateCard();
                    break;
                    
                case 'card:delete':
                    $this->deleteCard();
                    break;
                    
                case 'card:archive':
                    $this->archiveCard();
                    break;
                    
                case 'card:unarchive':
                    $this->unarchiveCard();
                    break;
                    
                case 'import':
                    $this->importCards();
                    break;
                    
                case 'export':
                    $this->exportCards();
                    break;

                case 'update':
                    $this->update();
                    break;
                    
                default:
                    Output::error("Unknown command: $command");
                    Output::line("Run 'php beckon-cli.php help' for usage.");
                    exit(1);
            }
        } catch (\Exception $e) {
            Output::error($e->getMessage());
            exit(1);
        }
        
        // Show any collected warnings
        foreach ($this->warnings as $warn) {
            Output::warn($warn);
        }
    }
    
    private function showHelp() {
        $version = CLI_VERSION;
        echo <<<HELP
Beckon CLI v{$version} - Command-line interface for managing Beckon cards

USAGE:
  php beckon-cli.php <command> [arguments] [options]

COMMANDS:
  boards                          List all boards
  board:create <title>            Create a new board
  lists <board>                   List all lists in a board
  cards <board>                   List cards (--list=NAME to filter)
  
  card:show <board> <id>          Show card details
  card:create <board>             Create a card
  card:update <board> <id>        Update a card
  card:delete <board> <id>        Delete a card permanently
  card:archive <board> <id>       Move card to archive
  card:unarchive <board> <id>     Restore card from archive
  
  import <board> <file.json>      Bulk import cards from JSON
  export <board>                  Export cards to JSON
  
  update                          Update Beckon from the latest GitHub release
                                  (--check only looks, --yes skips the prompt,
                                   --rollback restores the previous copy)
  
  help                            Show this help message
  version                         Show version

OPTIONS:
  --lists=LIST         Comma-separated list names (for board:create)
  --list=NAME          Target list name (for card:create, cards, card:unarchive)
  --title=TEXT         Card title
  --description=TEXT   Card description (Markdown)
  --desc-file=PATH     Read description from file
  --labels=JSON        Labels array as JSON string
  --due=DATE           Due date (YYYY-MM-DD)
  --start=DATE         Start date (YYYY-MM-DD)
  --assignees=JSON     Assignee IDs as JSON array
  --checklist=JSON     Checklist as JSON
  --json=PATH          Read card data from JSON file
  
  --include-archive    Include archived cards in listing/export
  --dry-run            Show what would happen without making changes
  --quiet              Suppress non-error output
  --no-color           Disable colored output

EXAMPLES:
  # List all boards
  php beckon-cli.php boards

  # List cards in a board
  php beckon-cli.php cards my-board --list="To Do"

  # Create a card
  php beckon-cli.php card:create my-board \\
    --list="To Do" \\
    --title="New Feature" \\
    --description="Implement the thing" \\
    --labels='[{"color":"orange","name":"Priority"}]' \\
    --due="2026-02-15"

  # Create card from JSON file
  php beckon-cli.php card:create my-board --json=card.json

  # Bulk import from JSON
  php beckon-cli.php import my-board cards.json

  # Export board to JSON
  php beckon-cli.php export my-board > backup.json

JSON IMPORT FORMAT:
  [
    {
      "title": "Card Title",
      "list": "List Name",
      "description": "Markdown content",
      "labels": [{"color": "orange", "name": "Label"}],
      "dueDate": "2026-02-15",
      "startDate": "2026-01-29",
      "assignees": ["user-id"],
      "checklists": [
        {
          "name": "Tasks",
          "items": [
            {"name": "Item 1", "done": false},
            "Simple item"
          ]
        }
      ]
    }
  ]

Valid label colors: orange, green, red, yellow, purple, blue, sky, lime, pink, black

HELP;
    }
    
    // --- Board Commands ---
    
    private function listBoards() {
        $boards = Board::listAll();
        
        if (empty($boards)) {
            Output::dim("No boards found.");
            return;
        }
        
        $rows = [];
        foreach ($boards as $b) {
            $rows[] = [$b['id'], $b['name'], (string)$b['lists'], (string)$b['cards']];
        }
        
        Output::table(['ID', 'Name', 'Lists', 'Cards'], $rows);
    }
    
    private function createBoard() {
        $title = $this->args->get(0) ?? $this->args->opt('title');
        
        if (!$title) {
            throw new \Exception("Usage: board:create <title> [--lists=\"List 1,List 2,List 3\"]");
        }
        
        // Generate slug from title
        $slug = FileOps::slugify($title);
        if (!$slug) {
            $slug = 'board-' . date('ymd');
        }
        
        // Handle slug conflicts
        $baseSlug = $slug;
        $counter = 1;
        while (is_dir(BOARDS_DIR . '/' . $slug)) {
            $slug = $baseSlug . '-' . $counter++;
        }
        
        $boardDir = BOARDS_DIR . '/' . $slug;
        
        if ($this->dryRun) {
            Output::info("[DRY RUN] Would create board: $title (slug: $slug)");
            return;
        }
        
        // Create directories
        mkdir($boardDir, 0755, true);
        mkdir("$boardDir/uploads", 0755, true);
        
        // Parse initial lists
        $lists = [];
        if ($listsArg = $this->args->opt('lists')) {
            $listNames = array_map('trim', explode(',', $listsArg));
            foreach ($listNames as $name) {
                if (!empty($name)) {
                    $lists[] = [
                        'id' => 'l' . time() . rand(100, 999),
                        'title' => $name,
                        'cards' => []
                    ];
                }
            }
        }
        
        // Create layout.json
        $layout = [
            'version' => 2,
            'title' => $title,
            'lists' => $lists,
            'archive' => []
        ];
        FileOps::atomicWrite("$boardDir/layout.json", $layout);
        
        // Create empty users.json
        FileOps::atomicWrite("$boardDir/users.json", new \stdClass());
        
        if (!$this->quiet) {
            Output::success("Created board: $slug");
            if (!empty($lists)) {
                Output::dim("  Lists: " . implode(', ', array_column($lists, 'title')));
            }
        } else {
            echo "$slug\n";
        }
    }
    
    // --- List Commands ---
    
    private function listLists() {
        $boardId = $this->args->get(0);
        if (!$boardId) {
            throw new \Exception("Usage: lists <board>");
        }
        
        $board = new Board($boardId);
        $lists = $board->getLists();
        
        if (empty($lists)) {
            Output::dim("No lists found.");
            return;
        }
        
        $rows = [];
        foreach ($lists as $list) {
            $rows[] = [$list['id'], $list['title'], (string)count($list['cards'] ?? [])];
        }
        
        Output::table(['ID', 'Title', 'Cards'], $rows);
        
        $archiveCount = count($board->layout['archive'] ?? []);
        if ($archiveCount > 0) {
            Output::dim("\n  + $archiveCount cards in archive");
        }
    }
    
    private function listCards() {
        $boardId = $this->args->get(0);
        if (!$boardId) {
            throw new \Exception("Usage: cards <board> [--list=NAME] [--include-archive]");
        }
        
        $board = new Board($boardId);
        $filterList = $this->args->opt('list');
        $includeArchive = $this->args->has('include-archive');
        
        $cards = $board->getAllCards($includeArchive);
        
        // Filter by list
        if ($filterList) {
            $cards = array_filter($cards, fn($c) => strcasecmp($c['_list'], $filterList) === 0);
        }
        
        if (empty($cards)) {
            Output::dim("No cards found.");
            return;
        }
        
        $rows = [];
        foreach ($cards as $card) {
            $labels = implode(', ', array_column($card['labels'] ?? [], 'name')) ?: '-';
            $due = $card['dueDate'] ?? '-';
            $rows[] = [
                $card['id'],
                mb_substr($card['title'], 0, 40) . (mb_strlen($card['title']) > 40 ? '...' : ''),
                $card['_list'],
                $labels,
                $due
            ];
        }
        
        Output::table(['ID', 'Title', 'List', 'Labels', 'Due'], $rows);
    }
    
    // --- Card Commands ---
    
    private function showCard() {
        $boardId = $this->args->get(0);
        $cardId = $this->args->get(1);
        
        if (!$boardId || !$cardId) {
            throw new \Exception("Usage: card:show <board> <card-id>");
        }
        
        $board = new Board($boardId);
        $card = $board->getCardDetails($cardId);
        
        echo Output::color("Card: {$card['title']}", 'bold') . "\n";
        echo str_repeat('─', 50) . "\n";
        
        echo "ID:       {$card['id']}\n";
        echo "List:     {$card['list']}" . ($card['archived'] ? ' (archived)' : '') . "\n";
        
        if (!empty($card['labels'])) {
            $labelStr = implode(', ', array_map(fn($l) => "[{$l['color']}] {$l['name']}", $card['labels']));
            echo "Labels:   $labelStr\n";
        }
        
        if ($card['dueDate']) echo "Due:      {$card['dueDate']}\n";
        if ($card['startDate']) echo "Start:    {$card['startDate']}\n";
        if (!empty($card['assignees'])) echo "Assigned: " . implode(', ', $card['assignees']) . "\n";
        if ($card['created_at']) echo "Created:  {$card['created_at']}\n";
        
        if (!empty(trim($card['description']))) {
            echo "\n" . Output::color("Description:", 'bold') . "\n";
            echo $card['description'] . "\n";
        }
        
        if (!empty($card['checklists'])) {
            echo "\n" . Output::color("Checklists:", 'bold') . "\n";
            foreach ($card['checklists'] as $cl) {
                echo "  {$cl['name']}:\n";
                foreach ($cl['items'] as $item) {
                    $check = ($item['state'] === 'complete') ? '✓' : '○';
                    echo "    $check {$item['name']}\n";
                }
            }
        }
        
        if (!empty($card['comments'])) {
            echo "\n" . Output::color("Comments:", 'bold') . " (" . count($card['comments']) . ")\n";
            foreach (array_slice($card['comments'], 0, 5) as $comment) {
                $user = $comment['user']['name'] ?? 'Unknown';
                $date = substr($comment['date'] ?? '', 0, 10);
                echo "  [$date] $user: " . mb_substr($comment['text'], 0, 60) . "\n";
            }
            if (count($card['comments']) > 5) {
                echo "  ... and " . (count($card['comments']) - 5) . " more\n";
            }
        }
    }
    
    private function createCard() {
        $boardId = $this->args->get(0);
        if (!$boardId) {
            throw new \Exception("Usage: card:create <board> --list=NAME --title=TEXT [options]");
        }
        
        $board = new Board($boardId);
        
        // Get card data from --json file, stdin, or individual options
        if ($jsonFile = $this->args->opt('json')) {
            if ($jsonFile === '-') {
                // Read from stdin
                if (posix_isatty(STDIN)) {
                    throw new \Exception("No input provided on stdin");
                }
                $jsonContent = stream_get_contents(STDIN);
            } else {
                if (!file_exists($jsonFile)) {
                    throw new \Exception("JSON file not found: $jsonFile");
                }
                $jsonContent = file_get_contents($jsonFile);
            }
            $cardData = json_decode($jsonContent, true);
            if (!$cardData) {
                throw new \Exception("Invalid JSON" . ($jsonFile !== '-' ? " in file: $jsonFile" : ""));
            }
        } else {
            $cardData = $this->buildCardDataFromOptions();
        }
        
        // Validate required fields
        if (empty($cardData['title'])) {
            throw new \Exception("Card title is required (--title=TEXT)");
        }
        
        $listName = $cardData['list'] ?? $this->args->opt('list');
        if (empty($listName)) {
            throw new \Exception("List name is required (--list=NAME)");
        }
        
        // Validate labels
        $cardData['labels'] = $this->validateLabels($cardData['labels'] ?? []);
        
        if ($this->dryRun) {
            Output::info("[DRY RUN] Would create card: {$cardData['title']} in list: $listName");
            return;
        }
        
        $cardId = $board->addCard($listName, $cardData);
        $board->saveLayout();
        
        // Update search index
        $this->reindexCard($board, $cardId);
        
        if (!$this->quiet) {
            Output::success("Created card: $cardId");
        } else {
            echo "$cardId\n";
        }
    }
    
    private function updateCard() {
        $boardId = $this->args->get(0);
        $cardId = $this->args->get(1);
        
        if (!$boardId || !$cardId) {
            throw new \Exception("Usage: card:update <board> <card-id> [options]");
        }
        
        $board = new Board($boardId);
        
        // Build updates from options
        $updates = [];
        
        if ($this->args->has('title')) {
            $updates['title'] = $this->args->opt('title');
        }
        
        if ($this->args->has('description')) {
            $updates['description'] = $this->args->opt('description');
        } elseif ($descFile = $this->args->opt('desc-file')) {
            if (!file_exists($descFile)) {
                throw new \Exception("Description file not found: $descFile");
            }
            $updates['description'] = file_get_contents($descFile);
        }
        
        if ($this->args->has('labels')) {
            $labels = json_decode($this->args->opt('labels'), true);
            if ($labels === null) {
                throw new \Exception("Invalid JSON for --labels");
            }
            $updates['labels'] = $this->validateLabels($labels);
        }
        
        if ($this->args->has('due')) {
            $updates['dueDate'] = $this->args->opt('due') ?: null;
        }
        
        if ($this->args->has('start')) {
            $updates['startDate'] = $this->args->opt('start') ?: null;
        }
        
        if ($this->args->has('assignees')) {
            $assignees = json_decode($this->args->opt('assignees'), true);
            if ($assignees === null) {
                throw new \Exception("Invalid JSON for --assignees");
            }
            $updates['assignees'] = $assignees;
        }
        
        if ($this->args->has('checklist')) {
            $checklists = json_decode($this->args->opt('checklist'), true);
            if ($checklists === null) {
                throw new \Exception("Invalid JSON for --checklist");
            }
            // Allow single checklist or array
            if (isset($checklists['name'])) {
                $checklists = [$checklists];
            }
            $updates['checklists'] = $checklists;
        }
        
        $targetList = $this->args->opt('list');

        if (empty($updates) && !$targetList) {
            throw new \Exception("No updates specified. Use --title, --description, --labels, --due, --start, --assignees, --checklist, or --list");
        }

        if ($this->dryRun) {
            Output::info("[DRY RUN] Would update card: $cardId");
            Output::dim("  Updates: " . json_encode(array_keys($updates)));
            return;
        }

        if (!empty($updates)) {
            $board->updateCard($cardId, $updates);
        }

        if ($targetList) {
            $board->moveCard($cardId, $targetList);
        }
        $board->saveLayout();
        
        // Update search index
        $this->reindexCard($board, $cardId);
        
        if (!$this->quiet) {
            Output::success("Updated card: $cardId");
        }
    }
    
    private function deleteCard() {
        $boardId = $this->args->get(0);
        $cardId = $this->args->get(1);
        
        if (!$boardId || !$cardId) {
            throw new \Exception("Usage: card:delete <board> <card-id>");
        }
        
        $board = new Board($boardId);
        
        if ($this->dryRun) {
            Output::info("[DRY RUN] Would delete card: $cardId");
            return;
        }
        
        $board->deleteCard($cardId);
        $board->saveLayout();
        
        // Remove from search index
        $this->searchIndex->removeCard($cardId);
        
        if (!$this->quiet) {
            Output::success("Deleted card: $cardId");
        }
    }
    
    private function archiveCard() {
        $boardId = $this->args->get(0);
        $cardId = $this->args->get(1);
        
        if (!$boardId || !$cardId) {
            throw new \Exception("Usage: card:archive <board> <card-id>");
        }
        
        $board = new Board($boardId);
        
        if ($this->dryRun) {
            Output::info("[DRY RUN] Would archive card: $cardId");
            return;
        }
        
        $board->archiveCard($cardId);
        $board->saveLayout();
        
        if (!$this->quiet) {
            Output::success("Archived card: $cardId");
        }
    }
    
    private function unarchiveCard() {
        $boardId = $this->args->get(0);
        $cardId = $this->args->get(1);
        
        if (!$boardId || !$cardId) {
            throw new \Exception("Usage: card:unarchive <board> <card-id> [--list=NAME]");
        }
        
        $board = new Board($boardId);
        $targetList = $this->args->opt('list');
        
        if ($this->dryRun) {
            Output::info("[DRY RUN] Would unarchive card: $cardId to list: " . ($targetList ?: '(first list)'));
            return;
        }
        
        $board->unarchiveCard($cardId, $targetList);
        $board->saveLayout();
        
        if (!$this->quiet) {
            Output::success("Unarchived card: $cardId");
        }
    }
    
    // --- Bulk Commands ---
    
    private function importCards() {
        $boardId = $this->args->get(0);
        $jsonFile = $this->args->get(1);
        
        if (!$boardId) {
            throw new \Exception("Usage: import <board> <file.json> [--list=NAME]\n       import <board> - [--list=NAME]  (read from stdin)");
        }
        
        // Read from stdin if "-" is specified or no file argument given
        if (!$jsonFile || $jsonFile === '-') {
            // Check if stdin has data
            if (posix_isatty(STDIN)) {
                throw new \Exception("No input provided. Use: import <board> <file.json>\nOr pipe/heredoc: echo '[...]' | php beckon-cli.php import <board> -");
            }
            $jsonContent = stream_get_contents(STDIN);
            if (empty($jsonContent)) {
                throw new \Exception("No data received from stdin");
            }
        } else {
            if (!file_exists($jsonFile)) {
                throw new \Exception("File not found: $jsonFile");
            }
            $jsonContent = file_get_contents($jsonFile);
        }
        
        $data = json_decode($jsonContent, true);
        if (!is_array($data)) {
            throw new \Exception("Invalid JSON - expected array of cards");
        }
        
        $board = new Board($boardId);
        $defaultList = $this->args->opt('list');
        
        $created = 0;
        $skipped = 0;
        
        foreach ($data as $i => $cardData) {
            // Validate title
            if (empty($cardData['title'])) {
                $this->warnings[] = "Card at index $i has no title - skipped";
                $skipped++;
                continue;
            }
            
            // Determine list
            $listName = $cardData['list'] ?? $defaultList;
            if (empty($listName)) {
                $this->warnings[] = "Card '{$cardData['title']}' has no list specified and no --list default - skipped";
                $skipped++;
                continue;
            }
            
            // Validate labels
            $cardData['labels'] = $this->validateLabels($cardData['labels'] ?? []);
            
            if ($this->dryRun) {
                Output::info("[DRY RUN] Would create: {$cardData['title']} in $listName");
                $created++;
                continue;
            }
            
            try {
                $cardId = $board->addCard($listName, $cardData);
                $this->reindexCard($board, $cardId);
                $created++;
                
                if (!$this->quiet) {
                    Output::dim("  + {$cardData['title']}");
                }
            } catch (\Exception $e) {
                $this->warnings[] = "Failed to create '{$cardData['title']}': {$e->getMessage()}";
                $skipped++;
            }
        }
        
        if (!$this->dryRun) {
            $board->saveLayout();
        }
        
        if (!$this->quiet) {
            Output::success("Imported $created cards" . ($skipped ? ", skipped $skipped" : ""));
        }
    }
    
    private function exportCards() {
        $boardId = $this->args->get(0);
        if (!$boardId) {
            throw new \Exception("Usage: export <board> [--list=NAME] [--include-archive]");
        }
        
        $board = new Board($boardId);
        $filterList = $this->args->opt('list');
        $includeArchive = $this->args->has('include-archive');
        
        $cards = $board->getAllCards($includeArchive);
        
        // Filter by list
        if ($filterList) {
            $cards = array_filter($cards, fn($c) => strcasecmp($c['_list'], $filterList) === 0);
            $cards = array_values($cards);
        }
        
        // Build export data
        $export = [];
        foreach ($cards as $card) {
            $details = $board->getCardDetails($card['id']);
            
            $export[] = [
                'title' => $details['title'],
                'list' => $details['list'],
                'description' => $details['description'],
                'labels' => $details['labels'],
                'dueDate' => $details['dueDate'],
                'startDate' => $details['startDate'],
                'assignees' => $details['assignees'],
                'checklists' => $details['checklists'],
                'created_at' => $details['created_at'],
                'archived' => $details['archived']
            ];
        }
        
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    
    // --- Helpers ---
    
    private function buildCardDataFromOptions() {
        $data = [
            'title' => $this->args->opt('title'),
            'list' => $this->args->opt('list'),
            'description' => $this->args->opt('description'),
            'labels' => [],
            'dueDate' => $this->args->opt('due'),
            'startDate' => $this->args->opt('start'),
            'assignees' => [],
            'checklists' => []
        ];
        
        // Read description from file
        if ($descFile = $this->args->opt('desc-file')) {
            if (!file_exists($descFile)) {
                throw new \Exception("Description file not found: $descFile");
            }
            $data['description'] = file_get_contents($descFile);
        }
        
        // Parse JSON options
        if ($labelsJson = $this->args->opt('labels')) {
            $data['labels'] = json_decode($labelsJson, true);
            if ($data['labels'] === null) {
                throw new \Exception("Invalid JSON for --labels");
            }
        }
        
        if ($assigneesJson = $this->args->opt('assignees')) {
            $data['assignees'] = json_decode($assigneesJson, true);
            if ($data['assignees'] === null) {
                throw new \Exception("Invalid JSON for --assignees");
            }
        }
        
        if ($checklistJson = $this->args->opt('checklist')) {
            $checklists = json_decode($checklistJson, true);
            if ($checklists === null) {
                throw new \Exception("Invalid JSON for --checklist");
            }
            // Allow single checklist or array
            if (isset($checklists['name'])) {
                $checklists = [$checklists];
            }
            $data['checklists'] = $checklists;
        }
        
        return $data;
    }
    
    private function validateLabels($labels) {
        $validated = [];
        foreach ($labels as $label) {
            if (!isset($label['color']) || !isset($label['name'])) {
                $this->warnings[] = "Label missing color or name - skipped";
                continue;
            }
            
            if (!in_array($label['color'], VALID_COLORS)) {
                $this->warnings[] = "Invalid label color '{$label['color']}' - using 'slate'";
                $label['color'] = 'slate';
            }
            
            $validated[] = $label;
        }
        return $validated;
    }
    
    // ----------------------------------------
    // UPDATE (shares the Updater class in index.php)
    // ----------------------------------------

    private function update() {
        $indexFile = __DIR__ . '/index.php';
        if (!file_exists($indexFile)) throw new \Exception("index.php not found next to beckon-cli.php");
        if (!defined('BECKON_NO_RUN')) define('BECKON_NO_RUN', true);
        require_once $indexFile;
        $updater = new \Beckon\Updater(__DIR__);
        $quiet = $this->args->has('quiet');

        if ($this->args->has('rollback')) {
            $summary = $updater->summary();
            if (!$summary['can_rollback']) throw new \Exception("No backup to restore (boards/.updates is empty).");
            if (!$this->args->has('yes') && !$this->confirm("Restore v{$summary['previous_version']} over the current v{$summary['current']}?")) { Output::info("Cancelled."); return; }
            $r = $updater->rollback();
            foreach ($r['versions'] as $file => $v) Output::success("Restored $file to v$v");
            return;
        }

        if (!$quiet) Output::info("Checking GitHub for the latest release...");
        $s = $updater->check(true);
        if (!empty($s['error']) && empty($s['latest'])) throw new \Exception("Check failed: {$s['error']}");
        Output::line("  Installed: v{$s['current']}" . (defined('CLI_VERSION') ? "  (CLI v" . CLI_VERSION . ")" : ''));
        Output::line("  Latest:    v{$s['latest']}" . ($s['published_at'] ? "  (" . date('M j, Y', strtotime($s['published_at'])) . ")" : ''));
        if ($s['git_checkout']) { Output::warn("This is a git checkout. Update it with git pull instead."); return; }
        if (!$s['update_available']) { Output::success("Already up to date."); return; }
        if (!$s['verified']) throw new \Exception("Release v{$s['latest']} publishes no checksum for index.php. Refusing to install an unverified file.");
        if ($this->args->has('check')) { Output::info("Run 'php beckon-cli.php update' to install v{$s['latest']}."); return; }
        if (!empty($s['notes']) && !$quiet) {
            Output::line();
            foreach (explode("\n", trim($s['notes'])) as $line) Output::dim("  " . $line);
            Output::line();
        }
        if (!$this->args->has('yes') && !$this->confirm("Install v{$s['latest']}?")) { Output::info("Cancelled."); return; }

        // Veto a download that does not parse before it replaces anything.
        $lint = function ($tmp, $name) {
            if (!function_exists('exec')) return;
            $php = PHP_BINARY ?: 'php';
            @exec(escapeshellarg($php) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            if ($code !== 0) throw new \Exception("Downloaded $name failed php -l: " . implode(' ', $out));
        };
        $r = $updater->install($lint);
        foreach ($r['files'] as $f) Output::success("Updated $f");
        Output::success("Beckon v{$r['from']} -> v{$r['to']}. The previous copy is in boards/.updates/ (php beckon-cli.php update --rollback).");
    }

    private function confirm($question) {
        if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) return false;
        echo Output::color("? ", 'cyan') . "$question [y/N] ";
        $answer = trim((string) fgets(STDIN));
        return in_array(strtolower($answer), ['y', 'yes'], true);
    }

    private function reindexCard($board, $cardId) {
        if (!$this->searchIndex->isAvailable()) return;
        
        try {
            $details = $board->getCardDetails($cardId);
            $this->searchIndex->indexCard(
                $board->id,
                $board->getName(),
                $cardId,
                $details['title'],
                $details['description'],
                $details['comments'],
                $details['labels']
            );
        } catch (\Exception $e) {
            // Silently fail - search index is optional
        }
    }
}

// ============================================
// ENTRY POINT
// ============================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

$cli = new CLI($argv);
$cli->run();
