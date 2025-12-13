<?php
/**
 * Beckon - Where Markdown charts the course
 */
define('BECKON_VERSION', '1.0.0');
define('BASE_DIR', __DIR__);
define('BOARDS_BASE', BASE_DIR . '/boards');

// Ensure directories exist
if (!file_exists(BOARDS_BASE)) mkdir(BOARDS_BASE, 0755, true);

function getBoardPath($id) {
    $clean = preg_replace('/[^a-z0-9-]/i', '', $id);
    if (!$clean || $clean === '.' || $clean === '..') throw new Exception("Invalid ID");
    return BOARDS_BASE . '/' . $clean;
}

// --- API HANDLER ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $boardId = $_GET['board'] ?? 'main';
    // Validate boardId for basic safety
    $boardId = preg_replace('/[^a-z0-9-]/i', '', $boardId);
    $boardDir = $boardId ? BOARDS_BASE . '/' . $boardId : null;

    try {
        if ($action === 'import_trello') {
            if (!isset($_FILES['file'])) throw new Exception("No file");
            $json = json_decode(file_get_contents($_FILES['file']['tmp_name']), true);
            if (!$json) throw new Exception("Invalid JSON");

            $slug = preg_replace('/[^a-z0-9-]/i', '', strtolower($json['name'] ?? 'Imported'));
            $slug = substr($slug ?: 'board', 0, 30) . '-' . date('ymd');
            
            // Uniqueness check for import
            $baseSlug = $slug;
            $counter = 1;
            while(file_exists(BOARDS_BASE . '/' . $slug)) {
                $slug = $baseSlug . '-' . $counter++;
            }

            $targetDir = BOARDS_BASE . '/' . $slug;
            mkdir($targetDir, 0755, true);
            // Create uploads dir immediately
            mkdir("$targetDir/uploads", 0755, true);

            $colorMap = ['green'=>'green', 'yellow'=>'yellow', 'orange'=>'orange', 'red'=>'red', 'purple'=>'purple', 'blue'=>'blue', 'sky'=>'sky', 'lime'=>'lime', 'pink'=>'pink', 'black'=>'slate'];
            $lists = []; $listMap = []; $cardMeta = [];
            
            // Lists
            foreach ($json['lists'] as $l) {
                if ($l['closed']) continue;
                $lists[] = ['id' => $l['id'], 'title' => $l['name'], 'cards' => []];
                $listMap[$l['id']] = count($lists) - 1;
            }

            // Checklists
            $checklists = [];
            foreach ($json['checklists'] ?? [] as $cl) $checklists[$cl['idCard']][] = $cl;

            // Activity/Comments
            foreach (array_reverse($json['actions'] ?? []) as $act) {
                if (!isset($act['data']['card']['id'])) continue;
                $cid = $act['data']['card']['id'];
                if (!isset($cardMeta[$cid])) $cardMeta[$cid] = ['comments' => [], 'activity' => [], 'revisions' => []];

                if ($act['type'] === 'commentCard') 
                    $cardMeta[$cid]['comments'][] = ['id' => $act['id'], 'text' => $act['data']['text'], 'date' => $act['date']];
                elseif ($act['type'] === 'createCard') 
                    $cardMeta[$cid]['activity'][] = ['text' => 'Created card', 'date' => $act['date']];
                elseif ($act['type'] === 'updateCard' && isset($act['data']['listAfter'])) 
                    $cardMeta[$cid]['activity'][] = ['text' => "Moved from {$act['data']['listBefore']['name']} to {$act['data']['listAfter']['name']}", 'date' => $act['date']];
            }

            // Cards
            usort($json['cards'], fn($a, $b) => $a['pos'] <=> $b['pos']);
            foreach ($json['cards'] as $c) {
                if ($c['closed'] || !isset($listMap[$c['idList']])) continue;
                $md = $c['desc'];
                foreach ($checklists[$c['id']] ?? [] as $cl) {
                    $md .= "\n\n### {$cl['name']}\n";
                    foreach ($cl['checkItems'] as $item) $md .= "- [" . ($item['state'] === 'complete' ? 'x' : ' ') . "] {$item['name']}\n";
                }

                $lists[$listMap[$c['idList']]]['cards'][] = [
                    'id' => $c['id'], 'title' => $c['name'],
                    'labels' => array_map(fn($l) => $colorMap[$l['color']] ?? 'slate', $c['labels'] ?? []),
                    'dueDate' => $c['due'] ? substr($c['due'], 0, 10) : null
                ];
                
                file_put_contents("$targetDir/{$c['id']}.md", $md);
                $meta = $cardMeta[$c['id']] ?? ['comments' => [], 'activity' => [], 'revisions' => []];
                $meta['activity'] = array_reverse($meta['activity']);
                $meta['comments'] = array_reverse($meta['comments']);
                file_put_contents("$targetDir/{$c['id']}.json", json_encode($meta, JSON_PRETTY_PRINT));
            }

            file_put_contents("$targetDir/layout.json", json_encode(['title' => $json['name'], 'lists' => $lists], JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'imported', 'board' => $slug]);
            exit;
        }

        if ($action === 'list_boards') {
            $boards = [];
            foreach (glob(BOARDS_BASE . '/*', GLOB_ONLYDIR) as $dir) {
                $id = basename($dir);
                $layout = json_decode(@file_get_contents("$dir/layout.json"), true);
                $boards[] = ['id' => $id, 'name' => $layout['title'] ?? $id];
            }
            echo json_encode(['boards' => $boards]);
            exit;
        }

        if ($action === 'create_board') {
            $title = $input['title'] ?? 'New Board';
            $slug = $input['slug'] ?: preg_replace('/[^a-z0-9-]/', '', strtolower($title));
            if (!$slug) $slug = 'board-' . date('ymd');
            
            // Unique slug check
            $baseSlug = $slug;
            $counter = 1;
            while(file_exists(getBoardPath($slug))) {
                 $slug = $baseSlug . '-' . $counter++;
            }

            $p = getBoardPath($slug);
            mkdir($p, 0755, true);
            mkdir("$p/uploads", 0755, true); // Create uploads dir
            file_put_contents("$p/layout.json", json_encode(['title' => $title, 'lists' => []], JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'ok', 'id' => $slug]);
            exit;
        }

        if ($action === 'delete_board') {
            $p = getBoardPath($input['board']);
            if (is_dir($p) && !is_link($p)) {
                // Recursive delete for uploads subfolder
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($p, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($files as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    $todo($fileinfo->getRealPath());
                }
                rmdir($p);
            }
            echo json_encode(['status' => 'ok']);
            exit;
        }

        if ($action === 'rename_board') {
            $oldId = $input['board'];
            $newTitle = $input['title'];
            
            // Generate safe slug from new title
            $newSlug = preg_replace('/[^a-z0-9-]/i', '-', strtolower($newTitle));
            $newSlug = preg_replace('/-+/', '-', $newSlug); // remove duplicate dashes
            $newSlug = trim($newSlug, '-');
            
            if (!$newSlug) throw new Exception("Invalid title for URL generation");

            $oldPath = getBoardPath($oldId);
            $newPath = BOARDS_BASE . '/' . $newSlug;

            // Update title inside layout.json regardless of folder rename
            $layout = json_decode(file_get_contents("$oldPath/layout.json"), true);
            $layout['title'] = $newTitle;
            file_put_contents("$oldPath/layout.json", json_encode($layout, JSON_PRETTY_PRINT));

            // If the slug is different, we need to move the folder
            if ($oldId !== $newSlug) {
                if (file_exists($newPath)) throw new Exception("A board with this name already exists");

                // 1. Scan all .md files in the board and fix image links
                $files = glob("$oldPath/*.md");
                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    // Replace boards/OLD-SLUG/uploads with boards/NEW-SLUG/uploads
                    $newContent = str_replace("boards/$oldId/uploads/", "boards/$newSlug/uploads/", $content);
                    if ($content !== $newContent) {
                        file_put_contents($file, $newContent);
                    }
                }

                // 2. Rename the directory
                rename($oldPath, $newPath);
                
                echo json_encode(['status' => 'renamed', 'id' => $newSlug, 'name' => $newTitle]);
            } else {
                echo json_encode(['status' => 'updated', 'id' => $oldId, 'name' => $newTitle]);
            }
            exit;
        }

        if ($action === 'load') {
            $data = json_decode(@file_get_contents("$boardDir/layout.json"), true) ?? [];
            if (!isset($data['lists'])) $data['lists'] = [['id' => 'l1', 'title' => 'Start', 'cards' => []]];
            if (!isset($data['title'])) $data['title'] = ucfirst(basename($boardDir));

            foreach ($data['lists'] as &$list) {
                foreach ($list['cards'] as &$card) {
                    $card['description'] = @file_get_contents("$boardDir/{$card['id']}.md") ?: '';
                    $card['labels'] = $card['labels'] ?? [];
                    $card['dueDate'] = $card['dueDate'] ?? null;
                }
            }
            echo json_encode($data);
            exit;
        }

        if ($action === 'load_card_meta') {
            $meta = json_decode(@file_get_contents("$boardDir/{$input['id']}.json"), true) ?? [];
            echo json_encode(array_merge(['comments' => [], 'activity' => [], 'revisions' => []], $meta));
            exit;
        }

        if ($action === 'save_layout') {
            foreach ($input['lists'] as &$list) foreach ($list['cards'] as &$card) unset($card['description']);
            file_put_contents("$boardDir/layout.json", json_encode($input, JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'saved']);
            exit;
        }

        if ($action === 'save_card') {
            file_put_contents("$boardDir/{$input['id']}.md", $input['description'] ?? '');
            echo json_encode(['status' => 'saved']);
            exit;
        }

        if ($action === 'save_card_meta') {
            file_put_contents("$boardDir/{$input['id']}.json", json_encode($input['meta'], JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'saved']);
            exit;
        }

        if ($action === 'move_card_to_board') {
            $targetId = $input['target_board'];
            $cardId = $input['id'];
            $targetPath = getBoardPath($targetId);
            
            if (!file_exists($targetPath)) throw new Exception("Target board not found");
            
            // 1. Load Source Layout (Current Board)
            $sourceLayout = json_decode(file_get_contents("$boardDir/layout.json"), true);
            $cardData = null;
            
            // Remove from source layout
            foreach ($sourceLayout['lists'] as &$list) {
                foreach ($list['cards'] as $key => $card) {
                    if ($card['id'] == $cardId) {
                        $cardData = $card;
                        array_splice($list['cards'], $key, 1);
                        break 2;
                    }
                }
            }
            
            if (!$cardData) throw new Exception("Card not found");
            
            // 2. Load Target Layout
            $targetLayout = json_decode(@file_get_contents("$targetPath/layout.json"), true) ?? [];
            if (empty($targetLayout['lists'])) {
                $targetLayout['lists'] = [['id' => 'l1', 'title' => 'Inbox', 'cards' => []]];
            }
            if (!isset($targetLayout['title'])) $targetLayout['title'] = $targetId;
            
            // Add to the top of the first list in target
            array_unshift($targetLayout['lists'][0]['cards'], $cardData);
            
            // 3. Move Files (.md and .json)
            $files = ["$cardId.md", "$cardId.json"];
            foreach ($files as $f) {
                if (file_exists("$boardDir/$f")) {
                    rename("$boardDir/$f", "$targetPath/$f");
                }
            }

            // NOTE: Images embedded in the MD file are NOT moved automatically.
            // They will still point to boards/OLD/uploads/. 
            // In a future update, we could parse and move them, but cross-board linking is valid.
            
            // 4. Save both Layouts
            file_put_contents("$boardDir/layout.json", json_encode($sourceLayout, JSON_PRETTY_PRINT));
            file_put_contents("$targetPath/layout.json", json_encode($targetLayout, JSON_PRETTY_PRINT));
            
            // 5. Add system note to card history
            $metaFile = "$targetPath/$cardId.json";
            $meta = json_decode(@file_get_contents($metaFile), true) ?? ['activity' => []];
            $meta['activity'] = $meta['activity'] ?? [];
            array_unshift($meta['activity'], [
                'text' => "Moved from board '{$sourceLayout['title']}' to '{$targetLayout['title']}'",
                'date' => date('c')
            ]);
            file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));

            echo json_encode(['status' => 'moved']);
            exit;
        }

        // New Revision Handler
        if ($action === 'save_revision') {
            $meta = json_decode(@file_get_contents("$boardDir/{$input['id']}.json"), true) ?? [];
            $meta['revisions'] = $meta['revisions'] ?? [];
            
            array_unshift($meta['revisions'], [
                'id' => uniqid(),
                'date' => date('c'),
                'text' => $input['text'],
                'user' => $input['user'] ?? 'Unknown'
            ]);

            if (count($meta['revisions']) > 50) $meta['revisions'] = array_slice($meta['revisions'], 0, 50);

            file_put_contents("$boardDir/{$input['id']}.json", json_encode($meta, JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'saved']);
            exit;
        }

        if ($action === 'delete_card') {
            if (file_exists("$boardDir/{$input['id']}.md")) unlink("$boardDir/{$input['id']}.md");
            if (file_exists("$boardDir/{$input['id']}.json")) unlink("$boardDir/{$input['id']}.json");
            echo json_encode(['status' => 'deleted']);
            exit;
        }

        if ($action === 'upload') {
            if (!isset($_FILES['file'])) throw new Exception('No file');
            
            $uploadDir = "$boardDir/uploads";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // 1. Get Clean Name
            $rawName = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $cleanName = preg_replace('/[^a-z0-9-]/i', '-', strtolower($rawName));
            
            // 2. Duplicate Detection (Loop until unique)
            $filename = "$cleanName.$ext";
            $counter = 1;
            while (file_exists("$uploadDir/$filename")) {
                $counter++;
                $filename = "$cleanName-$counter.$ext";
            }
            
            move_uploaded_file($_FILES['file']['tmp_name'], "$uploadDir/$filename");
            
            // Return path including board slug so it's portable
            echo json_encode(['url' => "boards/$boardId/uploads/$filename"]);
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beckon</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dayjs@1/dayjs.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dayjs@1/plugin/relativeTime.js"></script>
    <script>dayjs.extend(window.dayjs_plugin_relativeTime)</script>
    <style>
        .scrolling-wrapper::-webkit-scrollbar { height: 10px; }
        .scrolling-wrapper::-webkit-scrollbar-track { background: #1e293b; }
        .scrolling-wrapper::-webkit-scrollbar-thumb { background: #475569; border-radius: 6px; }
        /* Markdown Compact */
        .markdown-body { line-height: 1.6; color: #333; font-size: 14px; }
        .dark .markdown-body { color: #e2e8f0; }
        .dark .markdown-body h1, .dark .markdown-body h2 { border-bottom-color: #334155; }
        .dark .markdown-body a { color: #60a5fa; }
        .dark .markdown-body pre, .dark .markdown-body code { background: #1e293b; color: #f472b6; }
        .dark .markdown-body blockquote { border-left-color: #334155; color: #94a3b8; }
        .markdown-body p, .markdown-body ul, .markdown-body ol, .markdown-body blockquote, .markdown-body pre { margin-bottom: 1em; }
        .markdown-body h1 { font-size: 2em; font-weight: 800; margin: 1.5em 0 0.5em; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.3em; }
        .markdown-body h2 { font-size: 1.5em; font-weight: 700; margin: 1.5em 0 0.5em; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.3em; }
        .markdown-body h3 { font-size: 1.25em; font-weight: 600; margin: 1em 0 0.5em; }
        .markdown-body ul, .markdown-body ol { padding-left: 1.5rem; list-style-type: disc; }
        .markdown-body ol { list-style-type: decimal; }
        .markdown-body a { color: #2563eb; text-decoration: underline; }
        .markdown-body img { max-width: 100%; border-radius: 4px; margin: 1em 0; }
        .markdown-body pre { background: #f1f5f9; padding: 1em; border-radius: 6px; overflow-x: auto; }
        .markdown-body code { font-family: monospace; background: #f1f5f9; padding: 0.2em 0.4em; border-radius: 4px; font-size: 0.9em; color: #ec4899; }
        .markdown-body pre code { padding: 0; background: transparent; color: inherit; }
        .markdown-body blockquote { border-left: 4px solid #cbd5e1; padding-left: 1em; font-style: italic; color: #64748b; }
        .markdown-body input[type="checkbox"] { margin-right: 8px; cursor: pointer; }
        .animate-fade-in { animation: fadeIn 0.2s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }
    </style>
    <link rel="icon" href="beckon-icon.webp">
</head>
<body class="bg-slate-900 h-screen overflow-hidden text-slate-800 font-sans">
    <div id="app" class="h-full flex flex-col" :class="{ 'dark': darkMode }">
        <header class="bg-slate-950 text-white p-3 flex justify-between items-center shadow-lg shrink-0 border-b border-slate-800 z-20">
            <div class="flex items-center gap-4">
                <div @click="showBoardSelector = true" class="flex items-center gap-2 font-bold text-xl tracking-tight cursor-pointer hover:opacity-80 transition group">
                    <div class="h-8 w-8 bg-yellow-400 group-hover:bg-yellow-300 rounded-md flex items-center justify-center shadow-lg shadow-yellow-500/30 text-slate-900 transition">B</div>
                </div>
                
                <div class="relative z-50">
                    <div class="flex items-center gap-2 mr-4">
                        <button @click="toggleBoardSwitcher" class="flex items-center gap-2 hover:bg-slate-800 rounded py-1 px-2 -ml-2 transition group">
                            <h1 class="text-lg font-bold text-white whitespace-nowrap overflow-hidden text-ellipsis max-w-[400px]" title="Switch Board">
                                {{ boardData.title }}
                            </h1>
                            <svg class="w-4 h-4 text-slate-500 group-hover:text-white transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <button @click="openRenameModal" class="text-slate-500 hover:text-white p-1 rounded hover:bg-slate-800 transition" title="Rename Board">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        </button>
                    </div>

                    <div v-if="isBoardSwitcherOpen" class="absolute top-full left-0 mt-2 w-72 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden animate-fade-in origin-top-left">
                        <div class="p-2 border-b border-slate-100 dark:border-slate-700">
                            <input 
                                ref="boardSearchInput"
                                v-model="boardSearch" 
                                placeholder="Find a board..." 
                                class="w-full bg-slate-100 dark:bg-slate-900 border-none text-slate-800 dark:text-slate-100 px-3 py-2 rounded text-sm outline-none focus:ring-2 focus:ring-blue-500"
                            >
                        </div>
                        <div class="max-h-64 overflow-y-auto py-1">
                            <div class="px-3 py-2 text-xs font-bold text-slate-400 uppercase tracking-wider">Recent</div>
                            <div v-for="b in filteredBoards" :key="b.id" 
                                 @click="selectBoard(b.id)"
                                 class="px-4 py-2 hover:bg-blue-50 dark:hover:bg-slate-700 cursor-pointer text-sm text-slate-700 dark:text-slate-200 flex justify-between items-center group/item">
                                <span class="font-medium truncate">{{ b.name }}</span>
                                <span v-if="b.id === currentBoardId" class="text-xs text-blue-500 font-bold">●</span>
                            </div>
                            <div v-if="filteredBoards.length === 0" class="px-4 py-3 text-sm text-slate-500 text-center italic">
                                No boards found.
                            </div>
                            <div class="border-t border-slate-100 dark:border-slate-700 mt-1 pt-1">
                                <div @click="showCreateBoardModal = true; isBoardSwitcherOpen = false" class="px-4 py-2 hover:bg-blue-50 dark:hover:bg-slate-700 cursor-pointer text-sm text-blue-600 dark:text-blue-400 font-medium flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                    Create new board...
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="isBoardSwitcherOpen" @click="isBoardSwitcherOpen = false" class="fixed inset-0 z-[-1] cursor-default"></div>
                </div>

                <div class="flex items-center gap-2 px-2 py-1 rounded bg-slate-900 border border-slate-800 text-[10px] font-mono uppercase tracking-wider shrink-0">
                    <div class="w-2 h-2 rounded-full" :class="syncStatusColor"></div> {{ syncMessage }}
                </div>
            </div>
            <div class="flex gap-2 items-center">
                <label class="cursor-pointer text-xs bg-slate-800 hover:bg-slate-700 px-3 py-1.5 rounded transition border border-slate-700 flex items-center gap-2">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg> Import
                    <input type="file" @change="importTrello" class="hidden" accept=".json">
                </label>
                <button @click="darkMode = !darkMode" class="text-slate-400 hover:text-white transition p-1.5 rounded hover:bg-slate-800">
                    <svg v-if="darkMode" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                </button>
                <div class="relative group mr-2">
                    <button class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs transition border-2 border-slate-700 hover:border-blue-500"
                            :class="`bg-${currentUser.color}-200 text-${currentUser.color}-700`"
                            title="User Settings">
                        {{ currentUser.initials }}
                    </button>
                    
                    <div class="absolute right-0 top-full pt-2 w-64 hidden group-hover:block hover:block z-50">
                        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-slate-200 dark:border-slate-700 p-4">
                            <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Your Identity</h3>
                            <input v-model="currentUser.name" @input="saveUser" class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded px-2 py-1 text-sm mb-3 text-slate-900 dark:text-slate-100" placeholder="Your Name">
                            
                            <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Avatar Color</h3>
                            <div class="grid grid-cols-5 gap-2">
                                <button v-for="c in labelColors" :key="c" 
                                        @click="currentUser.color = c; saveUser()"
                                        :class="[`bg-${c}-500`, currentUser.color === c ? 'ring-2 ring-offset-1 ring-slate-400' : 'opacity-70 hover:opacity-100']"
                                        class="w-6 h-6 rounded-full"></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="h-4 w-px bg-slate-700 mx-2"></div>
                <button @click="showCreateBoardModal = true" class="text-xs bg-blue-600 hover:bg-blue-500 text-white font-bold px-3 py-1.5 rounded transition shadow-lg shadow-blue-900/50">New Board</button>
            </div>
        </header>

        <main class="flex-1 overflow-x-auto overflow-y-hidden p-6 scrolling-wrapper">
            <div class="flex h-full gap-6 items-start">
                <div v-for="(list, listIndex) in boardData.lists" :key="list.id"
                    class="w-72 bg-slate-100 dark:bg-slate-800/50 rounded-lg shadow-xl flex flex-col shrink-0 max-h-full border-t-4 border-blue-600 transition-colors"
                    @dragover.prevent="onListDragOver($event, listIndex)" @drop="onDrop">
                    
                    <div class="p-3 flex justify-between items-center rounded-t-lg shrink-0">
                        <input v-model="list.title" @change="persistLayout" class="bg-transparent font-bold text-slate-700 dark:text-slate-200 text-sm focus:outline-none w-full">
                        <button @click="deleteList(listIndex)" class="text-slate-400 hover:text-red-500 px-1">×</button>
                    </div>

                    <div class="flex-1 overflow-y-auto p-2 min-h-[50px]">
                        <div v-for="(card, cardIndex) in list.cards" :key="card.id">
                            <div v-if="dragTarget?.l === listIndex && dragTarget?.c === cardIndex && dragTarget?.pos === 'top'" class="h-1 bg-blue-600/50 rounded my-1 animate-pulse"></div>
                            
                            <div draggable="true" 
                                @dragstart.stop="startDrag($event, listIndex, cardIndex)" 
                                @dragover.stop.prevent="onCardDragOver($event, listIndex, cardIndex)"
                                @click="openCardModal(listIndex, cardIndex)"
                                class="bg-white dark:bg-slate-700 p-3 rounded shadow-sm border border-slate-200 dark:border-slate-600 hover:shadow-md cursor-pointer transition group relative hover:border-blue-400 mb-2">
                                <div v-if="card.labels?.length" class="flex gap-1 mb-2 flex-wrap"><span v-for="c in card.labels" :key="c" :class="`bg-${c}-500`" class="h-2 w-8 rounded-full block"></span></div>
                                <div class="text-sm text-slate-800 dark:text-slate-100 mb-2">{{ card.title }}</div>
                                <div class="flex items-center gap-3 text-[10px] text-slate-400 font-mono">
                                    <span v-if="card.description"><svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"></path></svg></span>
                                    <span v-if="getTaskStats(card.description).total > 0" :class="{'text-green-600 dark:text-green-400': getTaskStats(card.description).done === getTaskStats(card.description).total}">
                                        <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> {{ getTaskStats(card.description).done }}/{{ getTaskStats(card.description).total }}
                                    </span>
                                    <span v-if="card.dueDate" class="flex items-center gap-1" :class="getDueDateColor(card.dueDate)">
                                        <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> {{ formatDateShort(card.dueDate) }}
                                    </span>
                                </div>
                            </div>
                            
                            <div v-if="dragTarget?.l === listIndex && dragTarget?.c === cardIndex && dragTarget?.pos === 'bottom'" class="h-1 bg-blue-600/50 rounded my-1 animate-pulse"></div>
                        </div>
                        <div v-if="dragTarget?.l === listIndex && dragTarget?.c === null" class="h-1 bg-blue-600/50 rounded my-1 animate-pulse"></div>
                    </div>
                    <button @click="addCard(listIndex)" class="w-full py-2 text-slate-500 hover:bg-slate-200 dark:text-slate-400 dark:hover:bg-slate-700 text-xs font-bold transition rounded-b-lg shrink-0">+ Add Card</button>
                </div>
                <button @click="addList" class="w-72 h-12 bg-slate-800/50 hover:bg-slate-800 border-2 border-dashed border-slate-600 hover:border-slate-500 text-slate-400 font-bold rounded-lg shrink-0 transition flex items-center justify-center">+ Add List</button>
                <div class="w-4 shrink-0"></div>
            </div>
        </main>

        <div v-if="isModalOpen" class="fixed inset-0 bg-black/75 flex items-center justify-center z-50 p-4 backdrop-blur-sm" @click.self="closeModal">
            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-7xl h-[90vh] flex flex-col overflow-hidden animate-fade-in-up">
                <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-start shrink-0 transition-colors">
                    <div class="flex-1 mr-8">
                        <input v-model="activeCard.data.title" @blur="persistLayout" class="w-full text-2xl font-bold bg-transparent border-none focus:ring-0 text-slate-800 dark:text-slate-100 placeholder-slate-400 px-0" placeholder="Card Title">
                        <div class="text-xs text-slate-500 mt-1">in list <span class="font-bold underline">{{ boardData.lists[activeCard.listIndex].title }}</span></div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="toggleView" class="text-slate-400 hover:text-blue-600 transition p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700" title="Toggle View (Split / Editor / Preview)">
                            <svg v-if="splitPaneRatio === 100" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                            <svg v-else-if="splitPaneRatio === 0" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                            <svg v-else class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        </button>

                        <button @click="isSidebarOpen = !isSidebarOpen" class="text-slate-400 hover:text-blue-600 transition p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m-2 10V9a2 2 0 012-2h2a2 2 0 012 2v10a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                        </button>
                        <button @click="closeModal" class="text-slate-400 hover:text-red-500 transition p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700">
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>

                <div class="flex-1 flex overflow-hidden">
                    <div class="flex-1 flex flex-col min-w-0 overflow-hidden" :class="{'border-r border-slate-200 dark:border-slate-700': isSidebarOpen}">
                        <div class="flex-1 flex min-h-0 relative" ref="splitPaneContainer">
    
                            <div class="flex flex-col border-r border-slate-100 dark:border-slate-700 overflow-hidden" 
                                :style="{ width: splitPaneRatio + '%' }">
                                <div class="bg-slate-50 dark:bg-slate-900 px-4 py-2 border-b dark:border-slate-700 flex justify-between items-center text-xs font-bold text-slate-500 transition-colors shrink-0">
                                    <span>MARKDOWN</span>
                                    <label class="cursor-pointer hover:text-blue-600 flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> Img <input type="file" @change="handleFileInput" class="hidden" accept="image/*">
                                    </label>
                                </div>
                                <textarea 
                                    id="editor-textarea" 
                                    v-model="activeCard.data.description" 
                                    @input="debouncedSaveCard" 
                                    @drop.prevent="handleImageDrop"
                                    @dragover.prevent="isDraggingFile = true"
                                    @dragleave.prevent="isDraggingFile = false"
                                    :class="{'bg-blue-50 dark:!bg-slate-700 ring-2 ring-blue-500': isDraggingFile}"
                                    class="flex-1 p-4 focus:outline-none font-mono text-sm resize-none text-slate-700 bg-white dark:bg-slate-800 dark:text-slate-300 transition-colors w-full" 
                                    placeholder="Description..."
                                ></textarea>
                            </div>

                            <div class="w-1 hover:w-2 -ml-0.5 hover:-ml-1 bg-transparent hover:bg-blue-500/50 cursor-col-resize z-10 transition-all duration-150 flex-shrink-0 select-none"
                                @mousedown.prevent="startResize">
                            </div>

                            <div class="flex flex-col bg-slate-50/50 dark:bg-slate-900/50 overflow-hidden" 
                                :style="{ width: (100 - splitPaneRatio) + '%' }">
                                <div class="bg-slate-50 dark:bg-slate-900 px-4 py-2 border-b dark:border-slate-700 text-xs font-bold text-slate-500 transition-colors flex justify-between shrink-0">
                                    <span>PREVIEW</span>
                                    <span v-if="revisionIndex > -1" class="text-blue-500">PREVIEWING HISTORY</span>
                                </div>
                                <div class="flex-1 p-4 overflow-y-auto markdown-body bg-slate-50 dark:bg-slate-900 transition-colors w-full" v-html="compiledMarkdown" @click="handlePreviewClick"></div>
                            </div>

                        </div>

                        <div class="border-t border-slate-200 dark:border-slate-700 flex flex-col bg-white dark:bg-slate-800 transition-all duration-300 ease-in-out"
                             :class="isActivityOpen ? 'h-1/3' : 'h-10 shrink-0'">
                            
                            <div class="px-4 py-2 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-center text-xs font-bold text-slate-500 transition-colors cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800"
                                 @click="isActivityOpen = !isActivityOpen">
                                
                                <div class="flex gap-4">
                                    <button @click.stop="activityTab='comments'; isActivityOpen=true" 
                                            :class="{'text-blue-600 dark:text-blue-400 border-b-2 border-blue-600 dark:border-blue-400': activityTab==='comments' && isActivityOpen}">
                                        COMMENTS ({{activeCardMeta.comments.length}})
                                    </button>
                                    <button @click.stop="activityTab='history'; isActivityOpen=true" 
                                            :class="{'text-blue-600 dark:text-blue-400 border-b-2 border-blue-600 dark:border-blue-400': activityTab==='history' && isActivityOpen}">
                                        ACTIVITY ({{activeCardMeta.activity.length}})
                                    </button>
                                    <button @click.stop="activityTab='revisions'; isActivityOpen=true" 
                                            :class="{'text-blue-600 dark:text-blue-400 border-b-2 border-blue-600 dark:border-blue-400': activityTab==='revisions' && isActivityOpen}">
                                        REVISIONS ({{activeCardMeta.revisions?.length || 0}})
                                    </button>
                                </div>

                                <button class="text-slate-400 hover:text-blue-500 transition-transform duration-300" 
                                        :class="{'rotate-180': !isActivityOpen}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                            </div>

                            <div v-show="isActivityOpen" class="flex-1 overflow-y-auto p-4 space-y-4">
                                <div v-if="activityTab==='comments'" class="flex flex-col h-full">
                                    <div class="flex-1 space-y-4 overflow-y-auto mb-4">
                                        <div v-if="!activeCardMeta.comments.length" class="text-center text-slate-400 text-sm mt-4">No comments.</div>
                                        <div v-for="c in activeCardMeta.comments" :key="c.id" class="flex gap-3 text-sm group/comment">
                                            <div :class="`bg-${c.user?.color || 'slate'}-200 dark:bg-${c.user?.color || 'slate'}-700 text-${c.user?.color || 'slate'}-600 dark:text-${c.user?.color || 'slate'}-300`" 
                                                class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs shrink-0 ring-2 ring-white dark:ring-slate-800">
                                                {{ c.user?.initials || 'U' }}
                                            </div>
                                            
                                            <div class="flex-1 bg-slate-50 dark:bg-slate-700 p-3 rounded-lg border border-slate-100 dark:border-slate-600 dark:text-slate-200 relative">
                                                <div class="flex justify-between items-baseline mb-2">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-bold text-xs text-slate-700 dark:text-slate-300">{{ c.user?.name || 'Unknown' }}</span>
                                                        <span class="text-[10px] text-slate-400 font-mono uppercase">{{ formatTime(c.date) }} <span v-if="c.editedDate" title="Edited">*</span></span>
                                                    </div>
                                                    
                                                    <div v-if="editingCommentId !== c.id" class="opacity-0 group-hover/comment:opacity-100 transition-opacity flex gap-2">
                                                        <button @click="startEditComment(c)" class="text-xs text-slate-400 hover:text-blue-500">Edit</button>
                                                        <button @click="deleteComment(c.id)" class="text-xs text-slate-400 hover:text-red-500">Delete</button>
                                                    </div>
                                                </div>

                                                <div v-if="editingCommentId !== c.id" 
                                                    class="markdown-body !text-sm !bg-transparent !p-0 [&>p]:mb-0 [&>*:last-child]:mb-0" 
                                                    v-html="renderMarkdown(c.text)">
                                                </div>

                                                <div v-else>
                                                    <textarea v-model="editCommentText" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded p-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none mb-2" rows="3"></textarea>
                                                    <div class="flex justify-end gap-2">
                                                        <button @click="editingCommentId = null" class="text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400">Cancel</button>
                                                        <button @click="saveEditComment" class="text-xs bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-500">Save</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex gap-2 shrink-0">
                                        <input v-model="newComment" @keyup.enter="addComment" class="flex-1 border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Write a comment...">
                                        <button @click="addComment" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-bold hover:bg-blue-700">Send</button>
                                    </div>
                                </div>
                                <div v-if="activityTab==='history'" class="space-y-2">
                                    <div v-if="!activeCardMeta.activity.length" class="text-center text-slate-400 text-sm mt-4">No activity.</div>
                                    <div v-for="log in activeCardMeta.activity" :key="log.date" class="text-xs text-slate-600 dark:text-slate-400 flex gap-3 border-b border-slate-50 dark:border-slate-700 pb-1">
                                        <span class="font-mono text-slate-400 shrink-0 w-24">{{ formatTime(log.date) }}</span>
                                        <span>{{ log.text }}</span>
                                    </div>
                                </div>
                                <div v-if="activityTab==='revisions'" class="flex flex-col h-full">
                                    <div v-if="!activeCardMeta.revisions?.length" class="text-center text-slate-400 text-sm mt-4">
                                        No revision history yet. <br><span class="text-xs">Revisions are created when you change a card and close it.</span>
                                    </div>
                                    <div v-else class="flex flex-col gap-4">
                                        <div class="bg-slate-100 dark:bg-slate-700 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                                            <div class="flex justify-between text-xs font-bold mb-2 text-slate-500 dark:text-slate-300">
                                                <span>OLDEST</span>
                                                <span v-if="revisionIndex === -1" class="text-green-600 dark:text-green-400">CURRENT VERSION</span>
                                                <span v-else class="text-blue-600 dark:text-blue-400">PREVIEWING HISTORY</span>
                                                <span>NEWEST</span>
                                            </div>
                                            
                                            <input type="range" 
                                                v-model.number="revisionIndex" 
                                                :min="-1" 
                                                :max="activeCardMeta.revisions.length - 1" 
                                                step="1"
                                                class="w-full h-2 bg-slate-300 rounded-lg appearance-none cursor-pointer dark:bg-slate-600 accent-blue-600 transform rotate-180">
                                            
                                            <div class="mt-3 flex justify-between items-center h-8">
                                                <div v-if="revisionIndex > -1" class="text-xs">
                                                    <div class="font-bold text-slate-700 dark:text-slate-200">
                                                        {{ formatTime(activeCardMeta.revisions[revisionIndex].date) }}
                                                    </div>
                                                    <div class="text-slate-400">
                                                        by {{ activeCardMeta.revisions[revisionIndex].user }}
                                                    </div>
                                                </div>
                                                <div v-else class="text-xs text-slate-500 italic">
                                                    Viewing Live Version
                                                </div>

                                                <button v-if="revisionIndex > -1" 
                                                        @click="restoreRevision"
                                                        class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-3 py-1.5 rounded transition shadow">
                                                    Restore This
                                                </button>
                                            </div>
                                        </div>
                                        <div class="text-[10px] text-slate-400 text-center">
                                            Drag slider left to see older versions. <br>The preview panel above will update to show the content.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="isSidebarOpen" class="w-64 bg-slate-50 dark:bg-slate-900 p-4 space-y-6 overflow-y-auto shrink-0 border-l border-slate-200 dark:border-slate-700 transition-colors">
                        <div><h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Labels</h3><div class="grid grid-cols-4 gap-2"><button v-for="color in labelColors" :key="color" @click="toggleLabel(color)" :class="[`bg-${color}-500`, activeCard.data.labels?.includes(color) ? 'ring-2 ring-offset-1 ring-slate-400 scale-110' : 'opacity-70 hover:opacity-100']" class="h-6 w-full rounded shadow-sm transition"></button></div></div>
                        <div><h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Due Date</h3><input type="date" v-model="activeCard.data.dueDate" @change="handleDueDateChange" class="w-full border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 rounded px-2 py-1 text-sm text-slate-600 focus:ring-blue-500"></div>
                        <div>
                            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Move Card</h3>
                            <select @change="moveCardToBoard($event)" 
                                    class="w-full border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 rounded px-2 py-1 text-sm text-slate-600 focus:ring-blue-500 outline-none cursor-pointer">
                                <option value="" selected disabled>Select Board...</option>
                                <option v-for="b in availableBoards.filter(x => x.id !== currentBoardId)" 
                                        :key="b.id" 
                                        :value="b.id">
                                    {{ b.name }}
                                </option>
                            </select>
                        </div>
                        <div><h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Actions</h3><button @click="deleteActiveCard" class="w-full bg-red-50 dark:bg-red-900/30 hover:bg-red-100 dark:hover:bg-red-900/50 text-red-600 dark:text-red-400 text-sm py-2 rounded border border-red-200 dark:border-red-900/50 transition text-left px-3">Delete Card</button></div>
                        <div class="text-[10px] text-slate-400 text-center mt-10">Card ID: {{ activeCard.data.id }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="showBoardSelector" class="fixed inset-0 bg-slate-900 z-[60] flex flex-col animate-fade-in">
            <div class="p-6 flex justify-end">
                <button @click="showBoardSelector = false" class="text-slate-400 hover:text-white transition">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-8">
                <div class="max-w-5xl mx-auto">
                    <h1 class="text-4xl font-bold text-white mb-2 text-center">Your Boards</h1>
                    <p class="text-slate-400 text-center mb-12">Select a workspace or create a new one</p>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        <div @click="showCreateBoardModal = true; showBoardSelector=false" class="aspect-video border-2 border-dashed border-slate-700 hover:border-yellow-400 hover:bg-slate-800 rounded-xl flex flex-col items-center justify-center cursor-pointer transition group text-slate-500 hover:text-blue-400">
                            <svg class="w-12 h-12 mb-2 opacity-50 group-hover:scale-110 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                            <span class="font-bold">Create New Board</span>
                        </div>

                        <div v-for="b in availableBoards" :key="b.id" @click="selectBoard(b.id)" 
                            class="aspect-video bg-slate-800 hover:bg-slate-700 rounded-xl p-6 relative cursor-pointer transition shadow-lg hover:shadow-blue-500/20 group border border-slate-700"
                            :class="{'ring-2 ring-blue-500': currentBoardId === b.id}">
                            <div class="font-bold text-lg text-white mb-2 truncate">{{ b.name }}</div>
                            <div class="text-xs text-slate-500 font-mono">{{ b.id }}</div>
                            <div v-if="currentBoardId === b.id" class="absolute top-3 right-3 w-2 h-2 bg-green-500 rounded-full shadow-lg shadow-green-500/50"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-6 text-center shrink-0 flex items-center justify-center gap-3">
                <a href="https://beckon.run/changelog" class="flex items-center gap-2 group relative">
                    <img src="beckon-icon.webp" class="w-6 h-6 rounded-md shadow-sm opacity-90 group-hover:opacity-100 transition" alt="Beckon Icon">
                    <span class="text-slate-600 group-hover:text-slate-400 transition text-xs font-bold tracking-widest uppercase font-mono">v{{ version }}</span>
                    
                    <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-slate-800 text-white text-[10px] font-bold rounded opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none whitespace-nowrap shadow-xl z-10">
                        Changelog
                        <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-[1px] border-4 border-transparent border-t-slate-800"></div>
                    </div>
                </a>
                
                <span class="text-slate-300">—</span> 
                
                <a href="https://github.com/austinginder/beckon" target="_blank" class="text-slate-600 hover:text-slate-400 transition text-xs font-bold tracking-widest uppercase font-mono relative group">
                    Github
                    <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-slate-800 text-white text-[10px] font-bold rounded opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none whitespace-nowrap shadow-xl z-10">
                        Source Code
                        <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-[1px] border-4 border-transparent border-t-slate-800"></div>
                    </div>
                </a>
            </div>
        </div>
        <div v-if="showCreateBoardModal" class="fixed inset-0 bg-black/75 flex items-center justify-center z-[80] p-4 backdrop-blur-sm" @click.self="showCreateBoardModal = false">
            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-md overflow-hidden animate-fade-in-up border border-slate-200 dark:border-slate-700">
                <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-center">
                    <h3 class="font-bold text-slate-700 dark:text-slate-200">Create New Board</h3>
                    <button @click="showCreateBoardModal = false" class="text-slate-400 hover:text-red-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="p-6">
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Board Title</label>
                    <input v-model="newBoardTitle" @keyup.enter="createBoard" placeholder="e.g. Project Alpha" autofocus class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-blue-500 outline-none mb-4">
                    
                    <div class="flex justify-end gap-2">
                        <button @click="showCreateBoardModal = false" class="px-4 py-2 text-sm text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 rounded transition">Cancel</button>
                        <button @click="createBoard" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-500 text-white font-bold rounded transition shadow-lg shadow-blue-900/50">Create Board</button>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="showRenameModal" class="fixed inset-0 bg-black/75 flex items-center justify-center z-[70] p-4 backdrop-blur-sm" @click.self="showRenameModal = false">
            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-md overflow-hidden animate-fade-in-up border border-slate-200 dark:border-slate-700">
                <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-center">
                    <h3 class="font-bold text-slate-700 dark:text-slate-200">Board Settings</h3>
                    <button @click="showRenameModal = false" class="text-slate-400 hover:text-red-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                <div class="p-6 space-y-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Board Name</label>
                        <input v-model="tempBoardTitle" @keyup.enter="saveBoardTitle" class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-blue-500 outline-none mb-2">
                        <div class="flex justify-end">
                            <button @click="saveBoardTitle" class="px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-500 text-white font-bold rounded transition shadow">Save Name</button>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 dark:border-slate-700 my-4"></div>

                    <div>
                        <h4 class="text-xs font-bold text-red-500 uppercase mb-2 flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            Danger Zone
                        </h4>
                        <p class="text-xs text-slate-500 mb-3">Permanently delete this board and all its cards. This action cannot be undone.</p>
                        <button @click="deleteBoard(); showRenameModal = false" class="w-full bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 text-red-600 dark:text-red-400 text-sm font-bold py-2 rounded border border-red-200 dark:border-red-900/50 transition">
                            Delete Board
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
        createApp({
            setup() {
                marked.setOptions({ gfm: true, breaks: true });
                const labelColors = ['red', 'orange', 'yellow', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink', 'slate'];
                const version = '<?php echo BECKON_VERSION; ?>';
                const darkMode = ref(localStorage.getItem('beckon_darkMode') === 'true');
                const currentBoardId = ref(localStorage.getItem('beckon_last_board') || '');
                const availableBoards = ref([]);
                const boardData = ref({ title: 'Loading...', lists: [] });
                const syncState = ref('synced'); 
                const isModalOpen = ref(false);
                const activeCard = ref({ listIndex: null, cardIndex: null, data: {} });
                const activeCardMeta = ref({ comments: [], activity: [], revisions: [] });
                const activityTab = ref('comments');
                const newComment = ref('');
                const editingCommentId = ref(null);
                const editCommentText = ref('');
                const dragSource = ref(null);
                const dragTarget = ref(null);
                const isSidebarOpen = ref(false);
                const isActivityOpen = ref(localStorage.getItem('beckon_activity_open') !== 'false');
                const showBoardSelector = ref(false);
                const splitPaneRatio = ref(50); // Default 50%
                const splitPaneContainer = ref(null);
                const isResizing = ref(false);
                const boardSearch = ref('');
                const boardSearchInput = ref(null); // Ref for the search input
                const isBoardSwitcherOpen = ref(false); // New state for dropdown
                const showRenameModal = ref(false);
                const tempBoardTitle = ref('');
                const showCreateBoardModal = ref(false);
                const newBoardTitle = ref('');

                // Revisions State
                const originalDescription = ref('');
                const revisionIndex = ref(-1); 

                let debounceTimer = null;

                watch(darkMode, (v) => {
                    document.documentElement.classList.toggle('dark', v);
                    localStorage.setItem('beckon_darkMode', v);
                }, { immediate: true });
                watch(isActivityOpen, (v) => localStorage.setItem('beckon_activity_open', v));

                const api = async (action, payload = {}) => {
                    syncState.value = 'saving';
                    try {
                        const res = await fetch(`?action=${action}&board=${currentBoardId.value}`, { 
                            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) 
                        });
                        if (!res.ok) throw new Error();
                        setTimeout(() => syncState.value = 'synced', 500);
                        return await res.json();
                    } catch (e) { syncState.value = 'offline'; throw e; }
                };

                const saveLocal = () => localStorage.setItem(`beckon_${currentBoardId.value}`, JSON.stringify(boardData.value));
                const persistLayout = () => { saveLocal(); api('save_layout', boardData.value).then(() => { const b = availableBoards.value.find(b=>b.id===currentBoardId.value); if(b) b.name=boardData.value.title; }).catch(()=>{}); };
                const persistCardDesc = (c) => { if(c.id) { saveLocal(); api('save_card', {id: c.id, description: c.description}).catch(()=>{}); } };
                const persistMeta = (id, meta) => { if(id) api('save_card_meta', {id, meta}).catch(()=>{}); };

                const loadData = async () => {
                    try { const data = await api('load'); if(data.lists) { boardData.value = data; saveLocal(); return; } } catch(e){}
                    const local = localStorage.getItem(`beckon_${currentBoardId.value}`);
                    boardData.value = local ? JSON.parse(local) : { lists: [{ id: 'l1', title: 'Start', cards: [] }] };
                };

                const currentUser = ref(JSON.parse(localStorage.getItem('beckon_user')) || { 
                    name: 'Guest', initials: 'G', color: 'slate' 
                });

                const saveUser = () => {
                    const parts = currentUser.value.name.trim().split(' ');
                    currentUser.value.initials = parts.length > 1 ? (parts[0][0] + parts[parts.length-1][0]).toUpperCase() : currentUser.value.name.slice(0,2).toUpperCase();
                    localStorage.setItem('beckon_user', JSON.stringify(currentUser.value));
                };
                const fetchBoards = async () => { try { availableBoards.value = (await (await fetch('?action=list_boards')).json()).boards; } catch(e){} };
                const switchBoard = async () => { clearTimeout(debounceTimer); localStorage.setItem('beckon_last_board', currentBoardId.value); await loadData(); };
                const selectBoard = async (id) => { 
                    currentBoardId.value = id; 
                    boardSearch.value = ''; // Clear search on select
                    await switchBoard(); 
                    showBoardSelector.value = false;
                    isBoardSwitcherOpen.value = false;
                };
                const openRenameModal = () => {
                    tempBoardTitle.value = boardData.value.title;
                    showRenameModal.value = true;
                };

                const toggleBoardSwitcher = () => {
                    isBoardSwitcherOpen.value = !isBoardSwitcherOpen.value;
                    if (isBoardSwitcherOpen.value) {
                        nextTick(() => {
                            if (boardSearchInput.value) boardSearchInput.value.focus();
                        });
                    }
                };

                const saveBoardTitle = async () => {
                    if (!tempBoardTitle.value.trim()) return;
                    
                    try {
                        const res = await api('rename_board', { 
                            board: currentBoardId.value, 
                            title: tempBoardTitle.value 
                        });
                        
                        // Update local state
                        boardData.value.title = res.name;
                        
                        // Handle ID change (rename folder)
                        const oldId = currentBoardId.value;
                        if (res.id !== oldId) {
                            currentBoardId.value = res.id;
                            
                            // Update URL params without reload
                            const url = new URL(window.location);
                            url.searchParams.set('board', res.id);
                            window.history.pushState({}, '', url);
                            
                            // Migrate LocalStorage
                            const oldStore = localStorage.getItem(`beckon_${oldId}`);
                            if (oldStore) {
                                localStorage.setItem(`beckon_${res.id}`, oldStore);
                                localStorage.removeItem(`beckon_${oldId}`);
                            }
                        }

                        // Update available boards list
                        const b = availableBoards.value.find(b => b.id === oldId);
                        if(b) { b.id = res.id; b.name = res.name; }
                        
                        showRenameModal.value = false;
                    } catch (e) {
                        alert(e.message);
                    }
                };

                const importTrello = async (e) => {
                    if(!e.target.files[0] || !confirm("Import Trello board?")) return;
                    const fd = new FormData(); fd.append('file', e.target.files[0]);
                    syncState.value = 'saving';
                    try {
                        const json = await (await fetch(`?action=import_trello`, { method:'POST', body:fd })).json();
                        if(json.board) { alert("Import successful!"); await fetchBoards(); currentBoardId.value=json.board; await switchBoard(); }
                        else alert("Import failed.");
                    } catch(err) { alert("Error: " + err.message); } finally { syncState.value='synced'; e.target.value=''; }
                };

                const createBoard = async () => {
                    if (!newBoardTitle.value.trim()) return;
                    
                    const title = newBoardTitle.value;
                    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
                    
                    await api('create_board', {title, slug}); 
                    await fetchBoards(); 
                    
                    currentBoardId.value = slug; 
                    await switchBoard();
                    
                    // Reset and close
                    newBoardTitle.value = '';
                    showCreateBoardModal.value = false;
                    showBoardSelector.value = false;
                };
                const deleteBoard = async () => { 
                    if(confirm("Delete Board?")) { 
                        await api('delete_board', {board: currentBoardId.value}); 
                        await fetchBoards();
                        
                        if (availableBoards.value.length > 0) {
                            // Switch to the first available board
                            currentBoardId.value = availableBoards.value[0].id;
                            await switchBoard();
                        } else {
                            // No boards left, show selector
                            currentBoardId.value = '';
                            boardData.value = { title: 'No Boards', lists: [] };
                            showBoardSelector.value = true;
                        }
                    } 
                };

                const moveCardToBoard = async (e) => {
                    const targetBoardId = e.target.value;
                    const targetBoardName = availableBoards.value.find(b => b.id === targetBoardId)?.name || 'target board';

                    if (!confirm(`Move this card to "${targetBoardName}"?`)) {
                        e.target.value = ""; // Reset dropdown if cancelled
                        return;
                    }

                    const { id } = activeCard.value.data;
                    
                    try {
                        await api('move_card_to_board', { id, target_board: targetBoardId });
                        
                        // Remove card from local UI immediately
                        boardData.value.lists[activeCard.value.listIndex].cards.splice(activeCard.value.cardIndex, 1);
                        
                        // Update local storage so the card doesn't reappear on refresh
                        saveLocal();
                        
                        closeModal();
                    } catch (err) {
                        alert("Failed to move card: " + err.message);
                        e.target.value = ""; // Reset dropdown on failure
                    }
                };
                

                const addList = () => { boardData.value.lists.push({ id: 'l'+Date.now(), title: 'New List', cards: [] }); persistLayout(); };
                const deleteList = (i) => { if(confirm('Delete list?')) { boardData.value.lists.splice(i, 1); persistLayout(); } };
                const addCard = (lIdx) => {
                    const c = { id: Date.now(), title: 'New Card', description: '', labels: [], dueDate: null };
                    boardData.value.lists[lIdx].cards.push(c);
                    persistLayout(); persistCardDesc(c);
                    persistMeta(c.id, { comments: [], activity: [{ text: 'Card created', date: new Date().toISOString() }], revisions: [] });
                };

                const filteredBoards = computed(() => {
                    if (!boardSearch.value) return availableBoards.value;
                    const q = boardSearch.value.toLowerCase();
                    return availableBoards.value.filter(b => b.name.toLowerCase().includes(q) || b.id.toLowerCase().includes(q));
                });

                const openCardModal = async (lIdx, cIdx) => {
                    const card = boardData.value.lists[lIdx].cards[cIdx];
                    activeCard.value = { listIndex: lIdx, cardIndex: cIdx, data: card };
                    originalDescription.value = card.description || ''; 
                    revisionIndex.value = -1;
                    
                    isSidebarOpen.value = false; isModalOpen.value = true; 
                    activeCardMeta.value = { comments: [], activity: [], revisions: [] };
                    
                    if(card.id) try { 
                        const m = await api('load_card_meta', {id: card.id}); 
                        activeCardMeta.value = { 
                            comments: m.comments||[], 
                            activity: m.activity||[],
                            revisions: m.revisions||[]
                        }; 
                    } catch(e){}
                };

                const closeModal = () => { 
                    // Revision check
                    if (activeCard.value.data.description !== originalDescription.value) {
                        api('save_revision', {
                            id: activeCard.value.data.id,
                            text: originalDescription.value,
                            user: currentUser.value.name
                        });
                        activeCardMeta.value.activity.unshift({ text: 'Modified description (Revision saved)', date: new Date().toISOString() });
                        persistMeta(activeCard.value.data.id, activeCardMeta.value);
                    }
                    isModalOpen.value = false; persistLayout(); persistCardDesc(activeCard.value.data); 
                };
                const deleteActiveCard = () => {
                    if(!confirm("Delete?")) return;
                    const { id } = activeCard.value.data;
                    boardData.value.lists[activeCard.value.listIndex].cards.splice(activeCard.value.cardIndex, 1);
                    persistLayout(); api('delete_card', { id }); isModalOpen.value = false;
                };

                const onCardDragOver = (e, l, c) => {
                    if (!dragSource.value || (dragSource.value.l === l && dragSource.value.c === c)) { dragTarget.value = null; return; }
                    const rect = e.currentTarget.getBoundingClientRect();
                    dragTarget.value = { l, c, pos: e.clientY < (rect.top + rect.height/2) ? 'top' : 'bottom' };
                };
                const onListDragOver = (e, l) => { if (dragSource.value && !(dragTarget.value?.l === l && dragTarget.value?.c !== null)) dragTarget.value = { l, c: null, pos: 'bottom' }; };
                
                const startDrag = (e, l, c) => { dragSource.value = { l, c }; e.dataTransfer.effectAllowed = 'move'; };
                const onDrop = () => {
                    if (!dragSource.value || !dragTarget.value) return;
                    const { l: sL, c: sC } = dragSource.value;
                    const { l: tL, c: tC, pos } = dragTarget.value;
                    const card = boardData.value.lists[sL].cards[sC];
                    boardData.value.lists[sL].cards.splice(sC, 1);
                    let idx = tC === null ? boardData.value.lists[tL].cards.length : tC;
                    if (sL === tL && sC < tC) idx--;
                    if (pos === 'bottom' && tC !== null) idx++;
                    boardData.value.lists[tL].cards.splice(idx, 0, card);
                    
                    if (sL !== tL) {
                        api('load_card_meta', {id: card.id}).then(m => {
                            m.activity = m.activity || [];
                            m.activity.unshift({ text: `Moved to ${boardData.value.lists[tL].title}`, date: new Date().toISOString() });
                            persistMeta(card.id, m);
                        });
                    }
                    dragSource.value = null; dragTarget.value = null; persistLayout();
                };

                const toggleLabel = (c) => { const l = activeCard.value.data.labels; const i = l.indexOf(c); i > -1 ? l.splice(i, 1) : l.push(c); persistLayout(); };
                const handleDueDateChange = () => { activeCardMeta.value.activity.unshift({ text: `Due date changed`, date: new Date().toISOString() }); persistMeta(activeCard.value.data.id, activeCardMeta.value); persistLayout(); };

                const renderMarkdown = (text) => marked.parse(text || '').replace(/disabled=""/g, '');
                const deleteComment = (commentId) => {
                    if(!confirm('Delete this comment?')) return;
                    activeCardMeta.value.comments = activeCardMeta.value.comments.filter(c => c.id !== commentId);
                    persistMeta(activeCard.value.data.id, activeCardMeta.value);
                };
                const startEditComment = (c) => { editingCommentId.value = c.id; editCommentText.value = c.text; };
                const saveEditComment = () => {
                    const c = activeCardMeta.value.comments.find(c => c.id === editingCommentId.value);
                    if (c) { c.text = editCommentText.value; c.editedDate = new Date().toISOString(); persistMeta(activeCard.value.data.id, activeCardMeta.value); }
                    editingCommentId.value = null;
                };
                const addComment = () => {
                    if (!newComment.value.trim()) return;
                    activeCardMeta.value.comments.unshift({ id: Date.now(), text: newComment.value, date: new Date().toISOString(), user: { ...currentUser.value } });
                    newComment.value = ''; persistMeta(activeCard.value.data.id, activeCardMeta.value);
                };
                const isDraggingFile = ref(false);

                // Shared upload function
                const uploadFile = (file) => {
                    if (!file) return;
                    // Optimistic UI: Reset drag state immediately
                    isDraggingFile.value = false;
                    
                    const fd = new FormData(); 
                    fd.append('file', file);
                    
                    fetch(`?action=upload&board=${currentBoardId.value}`, {method:'POST', body:fd})
                        .then(r=>r.json())
                        .then(res => {
                            if(res.url) {
                                const el = document.getElementById('editor-textarea');
                                const v = activeCard.value.data.description || '';
                                
                                // Insert at cursor position (or end if unavailable)
                                const start = el ? el.selectionStart : v.length;
                                const end = el ? el.selectionEnd : v.length;
                                
                                activeCard.value.data.description = v.slice(0, start) + `\n![Image](${res.url})\n` + v.slice(end);
                                debouncedSaveCard();
                            }
                        });
                };

                // Handle standard file input selection
                const handleFileInput = (e) => {
                    uploadFile(e.target.files[0]);
                };

                // Handle Drop Zone events
                const handleImageDrop = (e) => {
                    isDraggingFile.value = false;
                    const f = e.dataTransfer.files[0];
                    if (f && f.type.startsWith('image/')) {
                        uploadFile(f);
                    }
                };

                const restoreRevision = () => {
                    if (revisionIndex.value > -1 && confirm("Restore this version?")) {
                        activeCard.value.data.description = activeCardMeta.value.revisions[revisionIndex.value].text;
                        originalDescription.value = activeCard.value.data.description;
                        revisionIndex.value = -1;
                        debouncedSaveCard();
                    }
                };

                const compiledMarkdown = computed(() => {
                    const text = revisionIndex.value > -1 ? activeCardMeta.value.revisions[revisionIndex.value].text : activeCard.value.data.description;
                    return marked.parse(text || '').replace(/disabled=""/g, '').replace(/disabled/g, '');
                });
                const debouncedSaveCard = () => { saveLocal(); syncState.value='saving'; clearTimeout(debounceTimer); debounceTimer = setTimeout(() => { persistCardDesc(activeCard.value.data); syncState.value='synced'; }, 1000); };
                const handlePreviewClick = (e) => {
                    if (revisionIndex.value > -1) return; // Disable checkboxes during preview
                    if (e.target.tagName === 'INPUT' && e.target.type === 'checkbox') {
                        const all = Array.from(document.querySelectorAll('.markdown-body input[type="checkbox"]'));
                        let c = 0; const idx = all.indexOf(e.target);
                        activeCard.value.data.description = activeCard.value.data.description.replace(/^(\s*[-*]\s\[)([ xX])(\])/gm, (m,p,s,sf) => c++ === idx ? p + (s === ' ' ? 'x' : ' ') + sf : m);
                        persistCardDesc(activeCard.value.data);
                    }
                };

                const getTaskStats = (t) => ({ total: (t?.match(/- \[[ xX]\]/g)||[]).length, done: (t?.match(/- \[[xX]\]/g)||[]).length });
                const getDueDateColor = (d) => { if (!d) return ''; const diff = dayjs(d).diff(dayjs(), 'day'); return diff < 0 ? 'bg-red-100 text-red-600' : diff <= 2 ? 'bg-yellow-100 text-yellow-600' : 'bg-slate-200 text-slate-500'; };
                const formatTime = (iso) => dayjs(iso).fromNow();
                const formatDateShort = (iso) => dayjs(iso).format('MMM D');

                const startResize = () => {
                    isResizing.value = true;
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none'; // Prevent text selection while dragging
                    window.addEventListener('mousemove', handleResize);
                    window.addEventListener('mouseup', stopResize);
                };

                const stopResize = () => {
                    isResizing.value = false;
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    window.removeEventListener('mousemove', handleResize);
                    window.removeEventListener('mouseup', stopResize);
                };

                const handleResize = (e) => {
                    if (!splitPaneContainer.value) return;
                    const rect = splitPaneContainer.value.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    
                    // Calculate percentage
                    let newRatio = (x / rect.width) * 100;
                    
                    // Clamp between 0 and 100
                    if (newRatio < 0) newRatio = 0;
                    if (newRatio > 100) newRatio = 100;
                    
                    splitPaneRatio.value = newRatio;
                };

                const toggleView = () => {
                    // Logic:
                    // 1. If currently split 50/50 -> Go to Markdown Only (100)
                    // 2. If currently Markdown Only (100) -> Go to Preview Only (0)
                    // 3. If currently Preview Only (0) OR any random dragged size -> Restore to Middle (50)
                    
                    if (splitPaneRatio.value === 50) {
                        splitPaneRatio.value = 100;
                    } else if (splitPaneRatio.value === 100) {
                        splitPaneRatio.value = 0;
                    } else {
                        splitPaneRatio.value = 50;
                    }
                };

                onMounted(async () => { 
                    await fetchBoards();
                    
                    if (availableBoards.value.length === 0) {
                        showBoardSelector.value = true;
                    } else {
                        if (!currentBoardId.value || !availableBoards.value.find(b => b.id === currentBoardId.value)) {
                            currentBoardId.value = availableBoards.value[0].id;
                        }
                        await switchBoard(); 
                    }

                    window.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape') {
                            if (showCreateBoardModal.value) showCreateBoardModal.value = false;
                            else if (showRenameModal.value) showRenameModal.value = false;
                            else if (isBoardSwitcherOpen.value) isBoardSwitcherOpen.value = false;
                            else if (isModalOpen.value) closeModal();
                            else if (showBoardSelector.value) showBoardSelector.value = false;
                        }
                    });
                });

                return {
                    version, currentUser, saveUser, editingCommentId, editCommentText, renderMarkdown,
                    boardSearch, isBoardSwitcherOpen, toggleBoardSwitcher, boardSearchInput,
                    filteredBoards, showRenameModal, tempBoardTitle, openRenameModal, saveBoardTitle,
                    deleteComment, startEditComment, saveEditComment, toggleView, showCreateBoardModal, newBoardTitle,
                    boardData, currentBoardId, availableBoards, syncState, isModalOpen, activeCard, activeCardMeta, handleImageDrop, isDraggingFile,
                    showBoardSelector, selectBoard, labelColors, activityTab, newComment, dragTarget, isSidebarOpen, isActivityOpen, darkMode,
                    addList, deleteList, addCard, openCardModal, closeModal, deleteActiveCard, splitPaneRatio, splitPaneContainer, startResize,
                    toggleLabel, handleDueDateChange, addComment, importTrello, persistLayout, switchBoard, createBoard, deleteBoard, moveCardToBoard,
                    startDrag, onDrop, onCardDragOver, onListDragOver, handlePreviewClick, debouncedSaveCard, compiledMarkdown, handleFileInput,
                    originalDescription, revisionIndex, restoreRevision,
                    getTaskStats, getDueDateColor, formatTime, formatDateShort,
                    syncStatusColor: computed(() => ({'synced':'bg-green-500','saving':'bg-yellow-500','offline':'bg-red-500'}[syncState.value])),
                    syncMessage: computed(() => syncState.value.toUpperCase())
                };
            }
        }).mount('#app');
    </script>
</body>
</html>