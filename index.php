<?php
/**
 * Beckon - Where Markdown charts the course
 */
namespace Beckon;

use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

define('BECKON_VERSION', '2.0.0');

class App {
    private $baseDir;
    private $boardsDir;
    private $searchIndex;

    public function __construct() {
        $this->baseDir = __DIR__;
        $this->boardsDir = $this->baseDir . '/boards';
        
        // Ensure directories exist
        if (!file_exists($this->boardsDir)) {
            mkdir($this->boardsDir, 0755, true);
        }
        
        // Apache only: never execute or serve scripts, databases or logs from inside boards/.
        $ht = $this->boardsDir . '/.htaccess';
        if (!file_exists($ht)) @file_put_contents($ht, "# Written by Beckon. Uploads are data, never code.\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|sh|htaccess|db|log)$\">\n    Require all denied\n</FilesMatch>\nRedirectMatch 404 /\\.(sync|updates)/\n");

        // Initialize search index
        $this->searchIndex = new SearchIndex($this->boardsDir);
    }

    /** Extensions an upload may keep. Anything a web server could execute or render as a page is missing on purpose. */
    const UPLOAD_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'bmp', 'ico', 'pdf', 'txt', 'md', 'csv', 'json', 'log', 'rtf', 'zip', 'gz', 'tar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'key', 'numbers', 'pages', 'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'mov', 'webm', 'psd', 'ai', 'sketch', 'fig', 'eps', 'msg', 'eml', 'vcf', 'ics', 'bin'];
    const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'bmp', 'ico'];

    /** Returns the lowercased extension if allowed, else throws. */
    private function allowedExt($ext, array $allowed = self::UPLOAD_EXTS) {
        $ext = strtolower(trim((string) $ext));
        if ($ext === 'log') $ext = 'txt';
        if ($ext === '' || !in_array($ext, $allowed, true)) throw new Exception("Files of type ." . ($ext ?: '?') . " can't be uploaded.");
        return $ext;
    }

    /**
     * Writes a board layout with the next revision number. Every writer goes through here, so
     * a browser tab can tell its copy is stale (see actionSaveLayout). Call inside the board lock.
     */
    private function writeLayout($path, array $layout) {
        $cur = json_decode(@file_get_contents($path), true);
        $layout['rev'] = (int) ($cur['rev'] ?? 0) + 1;
        // v1 saved a copy of the members inside layout.json. Keep any that users.json lacks
        // before dropping the copy, so no install loses a member on its first 2.0 save.
        if (!empty($layout['users']) && is_array($layout['users'])) {
            $usersPath = dirname($path) . '/users.json';
            $known = json_decode(@file_get_contents($usersPath), true);
            $known = is_array($known) ? $known : [];
            $missing = array_diff_key($layout['users'], $known);
            if ($missing) $this->atomicWrite($usersPath, (object) ($known + $missing));
        }
        unset($layout['users'], $layout['baseRev']);
        $this->atomicWrite($path, $layout);
        return $layout['rev'];
    }

    /**
     * File name an imported attachment is stored under. Shared by the attachment download and
     * the card cover path, so the two can't drift apart. Unknown types become inert .bin files.
     */
    private function attachmentFilename($rawName, $url, $attachmentId = '') {
        $ext = strtolower(pathinfo((string) $rawName, PATHINFO_EXTENSION) ?: pathinfo((string) parse_url((string) $url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $clean = preg_replace('/[^a-z0-9-]/i', '-', pathinfo((string) $rawName, PATHINFO_FILENAME)) ?: 'file';
        if (!in_array($ext, self::UPLOAD_EXTS, true)) { $clean .= $ext !== '' ? '-' . preg_replace('/[^a-z0-9]/', '', $ext) : ''; $ext = 'bin'; }
        $id = preg_replace('/[^a-z0-9]/i', '', (string) $attachmentId);
        return strtolower($id !== '' ? "$clean-$id.$ext" : "$clean.$ext");
    }

    /** Card ids are file names inside the board folder: dates, uuids, Trello hex ids, old numeric ids. */
    private function isCardId($id) { return is_scalar($id) && preg_match('/^[A-Za-z0-9_-]{1,128}$/', (string) $id); }
    private function cardId($id) { if (!$this->isCardId($id)) throw new Exception("Invalid card id"); return (string) $id; }

    public function run() {
        // Only intercept if this is an API request
        if (isset($_GET['action'])) {
            $this->handleApi($_GET['action']);
        }
    }

    /**
     * True for requests Beckon's own page (or a non-browser client) could have sent.
     * Modern browsers label every request with Sec-Fetch-Site; only same-origin and a typed-in
     * URL ("none") pass. Without that header, a present Origin must match the host the visitor
     * used (a reverse proxy's X-Forwarded-Host counts). Writes also need POST, so an <img> or a
     * link on another site can't trigger anything.
     */
    private function isSameSiteRequest($action) {
        // The device sync API may use GET (the app sends no browser headers, so the checks below pass it).
        if ($action !== 'events' && strpos($action, 'sync_') !== 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return false;
        $site = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($site !== '') return $site === 'same-origin' || $site === 'none';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') return true; // curl, the CLI, scripts
        if ($origin === 'null') return false;
        $norm = function ($host) {
            $host = strtolower(trim(explode(',', (string) $host)[0]));
            return preg_replace(['/:443$/', '/:80$/'], '', $host);
        };
        $originHost = parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
        foreach ([$_SERVER['HTTP_X_FORWARDED_HOST'] ?? '', $_SERVER['HTTP_HOST'] ?? ''] as $h) {
            if ($h !== '' && $norm($originHost) === $norm($h)) return true;
        }
        return false;
    }

    private function handleApi($action) {
        // SSE endpoint gets special handling (no JSON header, streaming)
        if ($action === 'events') {
            $this->handleEvents();
            exit;
        }

        header('Content-Type: application/json');

        // Beckon has no login on purpose, but other web pages must not drive it through the
        // visitor's browser. Browsers label cross-site requests; curl, the CLI and the sync app
        // send neither header and are unaffected.
        if (!$this->isSameSiteRequest($action)) {
            http_response_code(403);
            echo json_encode(['error' => 'Cross-site requests are not allowed.']);
            exit;
        }

        try {
            // parse JSON input
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            // Context setup
            $boardId = $_GET['board'] ?? 'main';
            $boardId = preg_replace('/[^a-z0-9-_]/i', '', $boardId);
            $boardDir = $boardId ? $this->boardsDir . '/' . $boardId : null;

            // Route action to method (e.g., 'list_boards' -> 'actionListBoards')
            $methodName = 'action' . str_replace(' ', '', ucwords(str_replace('_', ' ', $action)));

            if (method_exists($this, $methodName)) {
                $response = $this->$methodName($input, $boardId, $boardDir);
                echo json_encode($response);
            } else {
                throw new Exception("Invalid action");
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit; // Stop execution so HTML doesn't render
    }

    /**
     * SSE endpoint: watches layout.json for external changes and pushes reload events.
     * GET /api?action=events&board=<board-id>
     */
    private function handleEvents() {
        $boardId = $_GET['board'] ?? 'main';
        $boardId = preg_replace('/[^a-z0-9-_]/i', '', $boardId);
        $layoutPath = $this->boardsDir . '/' . $boardId . '/layout.json';

        if (!file_exists($layoutPath)) {
            http_response_code(404);
            echo "Board not found";
            return;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Disable output buffering
        while (ob_get_level()) ob_end_clean();

        // PHP's built-in server answers one request at a time unless
        // PHP_CLI_SERVER_WORKERS says otherwise, and a stream that sleeps would
        // hold that one worker: every other tab and API call stalls until the
        // stream ends. Say there is no live reload here and stop; the client
        // does not reconnect.
        if (php_sapi_name() === 'cli-server' && (int) getenv('PHP_CLI_SERVER_WORKERS') < 2) {
            echo "event: unavailable\ndata: {\"reason\":\"single-worker\"}\n\n";
            flush();
            return;
        }

        $lastMtime = filemtime($layoutPath);
        $lastHash = md5_file($layoutPath);

        // Send initial connection event
        echo "event: connected\ndata: {\"mtime\":{$lastMtime},\"hash\":\"$lastHash\"}\n\n";
        flush();

        // Short streams: each one holds a PHP worker, so hand it back often. The browser reconnects
        // right away and compares the layout hash from 'connected', so nothing is missed between.
        $maxRuntime = 30;
        $start = time();

        while (time() - $start < $maxRuntime) {
            if (connection_aborted()) break;

            clearstatcache(true, $layoutPath);
            $currentMtime = filemtime($layoutPath);
            // mtime has one-second resolution, so two writes in the same second compare content too.
            $currentHash = @md5_file($layoutPath) ?: $lastHash;

            if ($currentMtime !== $lastMtime || $currentHash !== $lastHash) {
                $lastMtime = $currentMtime;
                $lastHash = $currentHash;
                echo "event: board_updated\ndata: {\"mtime\":{$currentMtime},\"hash\":\"$currentHash\"}\n\n";
                flush();
            } elseif ((time() - $start) % 15 === 0) {
                // A comment line is invisible to the client but is a write, and a
                // write is what lets connection_aborted() notice a closed tab.
                echo ": keep-alive\n\n";
                flush();
            }

            sleep(1);
        }

        echo "event: timeout\ndata: {}\n\n";
        flush();
    }

    // --- API Actions ---

    private $updater;
    private function updater() { return $this->updater ?? ($this->updater = new Updater($this->baseDir)); }

    /**
     * {} reads the cached answer only. {"background": true} refreshes when the cache is older
     * than a week (sent once per browser session). {"force": true} always asks GitHub.
     */
    protected function actionCheckUpdates($input) {
        return $this->updater()->check(!empty($input['force']), !empty($input['background']));
    }

    protected function actionPerformUpdate() {
        return $this->updater()->install();
    }

    protected function actionUpdateRollback() {
        return $this->updater()->rollback();
    }

    protected function actionImportTrello($input) {
        if (!isset($_FILES['file'])) throw new Exception("No file");
        $json = json_decode(file_get_contents($_FILES['file']['tmp_name']), true);
        if (!$json) throw new Exception("Invalid JSON");

        // 1. Create Directories
        $slug = $this->slugify($json['name'] ?? 'Imported');
        $slug = substr($slug ?: 'board', 0, 30) . '-' . date('ymd');
        $baseSlug = $slug;
        $counter = 1;
        while(file_exists($this->boardsDir . '/' . $slug)) {
            $slug = $baseSlug . '-' . $counter++;
        }
        $targetDir = $this->boardsDir . '/' . $slug;
        mkdir($targetDir, 0755, true);
        mkdir("$targetDir/uploads", 0755, true);
        mkdir("$targetDir/uploads/avatars", 0755, true);

        // 2. Process Members
        $usersMap = [];
        $avatarDir = "$targetDir/uploads/avatars";
        
        $downloadAvatar = function($id, $hash) use ($avatarDir, $slug) {
            if (!$hash) return null;
            $url = "https://trello-members.s3.amazonaws.com/{$id}/{$hash}/170.png";
            $filename = "{$id}.png";
            $content = @file_get_contents($url);
            if ($content) {
                $this->atomicWrite("$avatarDir/$filename", $content);
                return "boards/$slug/uploads/avatars/$filename";
            }
            return null;
        };

        if (isset($json['members']) && is_array($json['members'])) {
            foreach ($json['members'] as $m) {
                $usersMap[$m['id']] = [
                    'id' => $m['id'],
                    'username' => $m['username'] ?? '',
                    'fullName' => $m['fullName'] ?? 'Unknown',
                    'initials' => $m['initials'] ?? '?',
                    'avatarHash' => $m['avatarHash'] ?? null,
                    'avatarFile' => $downloadAvatar($m['id'], $m['avatarHash'] ?? null)
                ];
            }
        }

        // 3. Process Lists
        $lists = [];
        $listMap = [];
        $closedListIds = [];
        foreach ($json['lists'] as $l) {
            if ($l['closed']) {
                $closedListIds[$l['id']] = true;
                continue;
            }
            $lists[] = ['id' => $l['id'], 'title' => $l['name'], 'cards' => []];
            $listMap[$l['id']] = count($lists) - 1;
        }

        // 4. Checklists & Actions
        $checklists = [];
        foreach ($json['checklists'] ?? [] as $cl) $checklists[$cl['idCard']][] = $cl;

        $cardMeta = [];
        foreach (array_reverse($json['actions'] ?? []) as $act) {
            if (!isset($act['data']['card']['id'])) continue;
            $cid = $act['data']['card']['id'];

            // Import missing users
            if (isset($act['memberCreator']['id']) && !isset($usersMap[$act['memberCreator']['id']])) {
                $m = $act['memberCreator'];
                $usersMap[$m['id']] = [
                    'id' => $m['id'],
                    'fullName' => $m['fullName'] ?? 'Unknown',
                    'initials' => $m['initials'] ?? '?',
                    'avatarFile' => $downloadAvatar($m['id'], $m['avatarHash'] ?? null)
                ];
            }

            if (!isset($cardMeta[$cid])) $cardMeta[$cid] = ['comments' => [], 'activity' => [], 'revisions' => []];

            $actor = $act['memberCreator']['fullName'] ?? 'Someone';
            $date = $act['date'];

            if ($act['type'] === 'commentCard') {
                // Trello JSON exports do not include reaction data, so we initialize as empty.
                $mappedReactions = [];

                $cardMeta[$cid]['comments'][] = [
                    'id' => $act['id'], 
                    'text' => $act['data']['text'], 
                    'date' => $date,
                    'user_id' => $act['memberCreator']['id'] ?? null,
                    'user' => ['name' => $actor, 'initials' => $act['memberCreator']['initials'] ?? '?'],
                    'reactions' => $mappedReactions // Add to comment structure
                ];

            } elseif ($act['type'] === 'createCard') {
                $cardMeta[$cid]['activity'][] = ['text' => "Created by $actor", 'date' => $date];
            } elseif ($act['type'] === 'updateCard') {
                if (isset($act['data']['listAfter'])) {
                    $cardMeta[$cid]['activity'][] = ['text' => "Moved to {$act['data']['listAfter']['name']} by $actor", 'date' => $date];
                }
                if (isset($act['data']['old']['desc'])) {
                    array_unshift($cardMeta[$cid]['revisions'], [
                        'id' => $act['id'], 'date' => $date, 'text' => $act['data']['old']['desc'], 'user' => $actor
                    ]);
                    $cardMeta[$cid]['activity'][] = ['text' => "Updated description by $actor", 'date' => $date];
                }
            }
        }

        // 5. Attachments
        $attachmentMap = [];
        foreach ($json['cards'] as $cTemp) {
            if (isset($cTemp['attachments'])) {
                foreach ($cTemp['attachments'] as $att) {
                    $ext = pathinfo($att['name'], PATHINFO_EXTENSION) ?: pathinfo(parse_url($att['url'], PHP_URL_PATH), PATHINFO_EXTENSION);
                    $attachmentMap[$att['id']] = [
                        'url' => $att['url'], 'name' => $att['name'], 'ext' => $ext, 'id' => $att['id']
                    ];
                }
            }
        }

        // 6. Process Cards
        $archive = [];
        $colorMap = ['green'=>'green', 'yellow'=>'yellow', 'orange'=>'orange', 'red'=>'red', 'purple'=>'purple', 'blue'=>'blue', 'sky'=>'sky', 'lime'=>'lime', 'pink'=>'pink', 'black'=>'slate'];
        
        usort($json['cards'], fn($a, $b) => $a['pos'] <=> $b['pos']);

        foreach ($json['cards'] as $c) {
            $isArchived = $c['closed'] || isset($closedListIds[$c['idList']]) || !isset($listMap[$c['idList']]);
            
            $timestamp = hexdec(substr($c['id'], 0, 8));
            $createdDate = date('c', $timestamp);
            $datePrefix = date('Y-m-d', $timestamp);
            $newId = "{$datePrefix}_{$c['id']}";

            $coverImagePath = null;
            if (!empty($c['idAttachmentCover']) && isset($attachmentMap[$c['idAttachmentCover']])) {
                $att = $attachmentMap[$c['idAttachmentCover']];
                $coverImagePath = "boards/$slug/uploads/" . $this->attachmentFilename($att['name'], $att['url'], $att['id']);
            }

            $cardChecklists = [];
            $checklistStats = ['total' => 0, 'done' => 0];
            if (isset($checklists[$c['id']])) {
                foreach ($checklists[$c['id']] as $cl) {
                    usort($cl['checkItems'], fn($a, $b) => $a['pos'] <=> $b['pos']);
                    $items = [];
                    foreach ($cl['checkItems'] as $item) {
                        $items[] = ['id' => $item['id'], 'name' => $item['name'], 'state' => $item['state']];
                        $checklistStats['total']++;
                        if ($item['state'] === 'complete') $checklistStats['done']++;
                    }
                    $cardChecklists[] = ['id' => $cl['id'], 'name' => $cl['name'], 'items' => $items];
                }
            }

            $mappedLabels = [];
            foreach ($c['labels'] ?? [] as $l) {
                $colorKey = $l['color'] ?? 'black';
                $name = $l['name'];
                if (empty($name) && isset($json['labelNames'][$colorKey])) $name = $json['labelNames'][$colorKey];
                $mappedLabels[] = ['color' => $colorMap[$colorKey] ?? 'slate', 'name' => $name ?: ucfirst($colorKey)];
            }

            $cardData = [
                'id' => $newId,
                'title' => $c['name'],
                'labels' => $mappedLabels,
                'startDate' => (isset($c['start']) && $c['start']) ? substr($c['start'], 0, 10) : null,
                'dueDate' => $c['due'] ? substr($c['due'], 0, 10) : null,
                'assignees' => $c['idMembers'] ?? [],
                'coverImage' => $coverImagePath,
                'created_at' => $createdDate,
                'checklistStats' => $checklistStats['total'] > 0 ? $checklistStats : null
            ];

            if ($isArchived) $archive[] = $cardData;
            else $lists[$listMap[$c['idList']]]['cards'][] = $cardData;

            $this->atomicWrite("$targetDir/{$newId}.md", $c['desc']);
            
            $meta = $cardMeta[$c['id']] ?? ['comments' => [], 'activity' => [], 'revisions' => []];
            $meta['activity'] = array_reverse($meta['activity']);
            $meta['comments'] = array_reverse($meta['comments']);
            $meta['assigned_to'] = $c['idMembers'] ?? [];
            $meta['created_at'] = $createdDate;
            $meta['checklists'] = $cardChecklists;
            $meta['title'] = $c['name'];
            $meta['labels'] = $mappedLabels;

            $this->atomicWrite("$targetDir/{$newId}.json", $meta);
        }

        $this->writeLayout("$targetDir/layout.json", [
            'title' => $json['name'], 'lists' => $lists, 'archive' => $archive
        ]);

        $this->atomicWrite("$targetDir/users.json", (object)$usersMap);

        // Rebuild search index to include imported cards
        $this->searchIndex->reindexAll();

        return ['status' => 'imported', 'board' => $slug];
    }

    protected function actionSaveUsers($input, $boardId, $boardDir) {
        $this->atomicWrite("$boardDir/users.json", $input['users']);
        return ['status' => 'saved'];
    }

    protected function actionUploadAvatar($input, $boardId, $boardDir) {
        if (!isset($_FILES['file'])) throw new Exception('No file');
        $dir = "$boardDir/uploads/avatars";
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $ext = $this->allowedExt(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION), self::IMAGE_EXTS);
        $filename = uniqid('u_') . ".$ext";
        move_uploaded_file($_FILES['file']['tmp_name'], "$dir/$filename");
        
        return ['url' => "boards/$boardId/uploads/avatars/$filename"];
    }

    protected function actionImportAttachment($input) {
        if (!isset($input['board'], $input['card'], $input['url'])) throw new Exception("Missing parameters");
        
        $slug = $this->slugify($input['board']);
        $cardId = $this->slugify($input['card']);
        $boardPath = $this->boardsDir . '/' . $slug;
        $uploadDir = "$boardPath/uploads";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        // Name Generation
        $rawName = $input['name'] ?? 'file';
        $ext = pathinfo($this->attachmentFilename($rawName, $input['url']), PATHINFO_EXTENSION);
        if (!empty($input['attachmentId'])) {
            $filename = $this->attachmentFilename($rawName, $input['url'], $input['attachmentId']);
        } else {
            $filename = $this->attachmentFilename($rawName, $input['url']);
            $stem = pathinfo($filename, PATHINFO_FILENAME);
            $counter = 1;
            while (file_exists("$uploadDir/$filename")) $filename = "$stem-" . $counter++ . ".$ext";
        }

        // Curl Download (http and https only: no file://, gopher:// and friends)
        if (!preg_match('#^https?://#i', (string) $input['url'])) throw new Exception("Only http and https attachments can be imported.");
        $ch = curl_init($input['url']);
        $fp = fopen("$uploadDir/$filename", 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_HEADER => 0, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Beckon-Importer)', CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FAILONERROR => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if (!empty($input['cookies'])) curl_setopt($ch, CURLOPT_COOKIE, $input['cookies']);
        
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($code >= 400) {
            @unlink("$uploadDir/$filename");
            throw new Exception("HTTP Error: $code");
        }

        $mdPath = "$boardPath/$cardId.md";

        // If exact ID.md not found, try to resolve the timestamped version (YYYY-MM-DD_ID.md)
        if (!file_exists($mdPath) && preg_match('/^[a-f0-9]{24}$/', $cardId)) {
            $timestamp = hexdec(substr($cardId, 0, 8));
            $datePrefix = date('Y-m-d', $timestamp);
            $prefixedPath = "$boardPath/{$datePrefix}_{$cardId}.md";
            
            if (file_exists($prefixedPath)) {
                $mdPath = $prefixedPath;
            }
        }

        // Append to Markdown
        if (file_exists($mdPath)) {
            $isImg = in_array(strtolower($ext), ['jpg','jpeg','png','gif','webp']);
            $append = "\n\n" . ($isImg ? '!' : '') . "[$rawName](boards/$slug/uploads/$filename)";
            file_put_contents($mdPath, $append, FILE_APPEND);
        }
        
        return ['status' => 'ok'];
    }

    // --- WordPress Actions ---

    protected function actionWpUpload($input, $boardId, $boardDir) {
        $this->validateWpCreds($input);
        
        $localPath = $input['local_path'];
        $realPath = realpath($this->baseDir . '/' . $localPath);
        
        // Security: Ensure we are only uploading files from inside the 'boards' directory
        if (!$realPath || strpos($realPath, $this->boardsDir) !== 0 || !file_exists($realPath)) {
            throw new Exception("File not found or access denied: $localPath");
        }

        $filename = basename($realPath);
        $mime = mime_content_type($realPath);
        $fileData = file_get_contents($realPath);

        // Upload to WordPress
        $url = rtrim($input['wp_url'], '/') . "/wp-json/wp/v2/media";
        $headers = [
            "Authorization: Basic " . base64_encode($input['wp_user'] . ":" . $input['wp_pass']),
            "Content-Disposition: attachment; filename=\"$filename\"",
            "Content-Type: $mime",
            "User-Agent: Beckon/1.0"
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $fileData,
            CURLOPT_SSL_VERIFYPEER => false, // Dev friendly
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code !== 201) {
            $json = json_decode($res, true);
            $msg = $json['message'] ?? $err ?? "HTTP $code";
            throw new Exception("WP Upload Failed: $msg");
        }

        $data = json_decode($res, true);
        return ['id' => $data['id'], 'url' => $data['source_url']];
    }

    protected function actionWpPost($input, $boardId, $boardDir) {
        $this->validateWpCreds($input);
        $html = $input['html'];

        // --- Block Grammar Transformation ---
        
        // 1. Image Blocks
        // We look for <img> tags that now have 'data-wp-id'. 
        // We assume they are wrapped in <p> by markdown-it.
        $html = preg_replace_callback(
            '/<p[^>]*>\s*(<img\s+[^>]*data-wp-id="\d+"[^>]*>)\s*<\/p>/i',
            function($m) {
                preg_match('/data-wp-id="(\d+)"/i', $m[1], $idm);
                preg_match('/src="([^"]+)"/i', $m[1], $srcm);
                preg_match('/alt="([^"]*)"/i', $m[1], $altm);
                $id = (int)$idm[1];
                return sprintf(
                    '<!-- wp:image {"id":%d,"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="%s" alt="%s" class="wp-image-%d"/></figure><!-- /wp:image -->',
                    $id, $srcm[1] ?? '', $altm[1] ?? '', $id
                );
            },
            $html
        );

        // 2. Standard Blocks
        $transforms = [
            '/<h([1-6])>(.*?)<\/h\1>/' => '<h$1>$2</h$1>',
            '/<ul>(.*?)<\/ul>/s' => '<ul>$1</ul>',
            '/<ol>(.*?)<\/ol>/s' => '<ol>$1</ol>',
            '/<blockquote>(.*?)<\/blockquote>/s' => '<blockquote class="wp-block-quote">$1</blockquote>',
            '/<pre><code[^>]*>(.*?)<\/code><\/pre>/s' => '<pre class="wp-block-code"><code>$1</code></pre>',
            '/<p>(.*?)<\/p>/' => '<p>$1</p>'
        ];
        $html = preg_replace(array_keys($transforms), array_values($transforms), $html);

        // Send Post
        $url = rtrim($input['wp_url'], '/') . "/wp-json/wp/v2/posts";
        $postData = [
            'title' => $input['title'],
            'content' => $html,
            'status' => 'draft',
            'featured_media' => $input['featured_media'] ?? null
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . base64_encode($input['wp_user'] . ":" . $input['wp_pass']),
                "Content-Type: application/json",
                "User-Agent: Beckon/1.0"
            ],
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201) throw new Exception("WP Post Failed: HTTP $code");

        $post = json_decode($res, true);
        return [
            'link' => $post['link'], 
            'edit_link' => rtrim($input['wp_url'], '/') . "/wp-admin/post.php?post={$post['id']}&action=edit"
        ];
    }

    private function validateWpCreds($input) {
        if (empty($input['wp_url']) || empty($input['wp_user']) || empty($input['wp_pass'])) {
            throw new Exception("Missing WordPress credentials");
        }
    }

    protected function actionListBoards() {
        $boards = [];
        foreach (glob($this->boardsDir . '/*', GLOB_ONLYDIR) as $dir) {
            $id = basename($dir);
            $layout = json_decode(@file_get_contents("$dir/layout.json"), true);
            $boards[] = ['id' => $id, 'name' => $layout['title'] ?? $id];
        }
        return ['boards' => $boards];
    }

    // --- Search Actions ---

    protected function actionSearch($input) {
        if (!$this->searchIndex->isAvailable()) {
            return ['error' => 'Search index not available. SQLite/PDO may not be installed.', 'results' => []];
        }
        
        $query = $input['query'] ?? '';
        $boardId = $input['board_id'] ?? null;
        $limit = min((int)($input['limit'] ?? 30), 100);
        
        $results = $this->searchIndex->search($query, $boardId, $limit);
        
        return [
            'results' => $results,
            'query' => $query,
            'count' => count($results)
        ];
    }

    protected function actionReindex() {
        $result = $this->searchIndex->reindexAll();
        $stats = $this->searchIndex->getStats();
        return array_merge($result, ['stats' => $stats]);
    }

    protected function actionSearchStats() {
        return $this->searchIndex->getStats();
    }

    // --- Sync API for Mobile App ---
    
    private function getSyncDir() {
        $dir = $this->boardsDir . '/.sync';
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }
    
    private function getDevicesFile() {
        return $this->getSyncDir() . '/devices.json';
    }
    
    private function getPendingPairFile() {
        return $this->getSyncDir() . '/pending_pair.json';
    }
    
    private function loadDevices() {
        $file = $this->getDevicesFile();
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true) ?? [];
        }
        return [];
    }
    
    private function saveDevices($devices) {
        $this->atomicWrite($this->getDevicesFile(), $devices);
    }
    
    /**
     * Validates sync authorization header.
     * Returns device info if valid, throws exception if not.
     */
    private function validateSyncAuth() {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            throw new Exception("Missing or invalid authorization header", 401);
        }
        
        $token = $matches[1];
        $tokenHash = hash('sha256', $token);
        
        $devices = $this->loadDevices();
        foreach ($devices as $deviceId => $device) {
            if ($device['token_hash'] === $tokenHash) {
                // Update last seen
                $devices[$deviceId]['last_seen'] = date('c');
                $this->saveDevices($devices);
                return $device;
            }
        }
        
        throw new Exception("Invalid or expired token", 401);
    }
    
    /**
     * Request device pairing. Generates a 6-digit PIN and outputs to terminal.
     * POST /api?action=sync_pair_request
     * Body: { "device_name": "Austin's iPhone", "device_id": "uuid" }
     */
    protected function actionSyncPairRequest($input) {
        $deviceName = $input['device_name'] ?? 'Unknown Device';
        $deviceId = $input['device_id'] ?? null;
        
        if (!$deviceId) {
            throw new Exception("device_id is required");
        }
        
        // Generate 6-digit PIN
        $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store pending pairing (expires in 5 minutes)
        $pending = [
            'pin' => $pin,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'requested_at' => time(),
            'expires_at' => time() + 300, // 5 minutes
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        $this->atomicWrite($this->getPendingPairFile(), $pending);
        
        // Output PIN to terminal/error log for the user to see
        $message = "\n" . str_repeat("=", 50) . "\n";
        $message .= "  BECKON PAIRING REQUEST\n";
        $message .= str_repeat("=", 50) . "\n";
        $message .= "  Device: $deviceName\n";
        $message .= "  PIN:    $pin\n";
        $message .= "  Expires in 5 minutes\n";
        $message .= str_repeat("=", 50) . "\n";
        
        error_log($message);
        
        // Also write to a pairing log file that can be tailed
        $logFile = $this->getSyncDir() . '/pairing.log';
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Pairing PIN for $deviceName: $pin\n", FILE_APPEND);
        
        return [
            'status' => 'awaiting_pin',
            'message' => 'Enter the PIN shown on your computer',
            'expires_in' => 300
        ];
    }
    
    /**
     * Confirm pairing with PIN. Returns device token if valid.
     * POST /api?action=sync_pair_confirm
     * Body: { "device_id": "uuid", "pin": "123456" }
     */
    protected function actionSyncPairConfirm($input) {
        $deviceId = $input['device_id'] ?? null;
        $pin = $input['pin'] ?? null;
        
        if (!$deviceId || !$pin) {
            throw new Exception("device_id and pin are required");
        }
        
        $pendingFile = $this->getPendingPairFile();
        if (!file_exists($pendingFile)) {
            throw new Exception("No pending pairing request. Please request a new PIN.");
        }
        
        $pending = json_decode(file_get_contents($pendingFile), true);
        
        // Validate
        if ($pending['expires_at'] < time()) {
            @unlink($pendingFile);
            throw new Exception("PIN has expired. Please request a new one.");
        }
        
        if ($pending['device_id'] !== $deviceId) {
            throw new Exception("Device ID mismatch");
        }
        
        if (!hash_equals((string) $pending['pin'], (string) $pin)) {
            $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
            if ($pending['attempts'] >= 5) { @unlink($pendingFile); throw new Exception("Too many wrong PINs. Please request a new one."); }
            $this->atomicWrite($pendingFile, $pending);
            throw new Exception("Invalid PIN");
        }
        
        // PIN is valid! Generate token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        
        // Store device
        $devices = $this->loadDevices();
        $devices[$deviceId] = [
            'id' => $deviceId,
            'name' => $pending['device_name'],
            'token_hash' => $tokenHash,
            'paired_at' => date('c'),
            'last_seen' => date('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        $this->saveDevices($devices);
        
        // Clean up pending file
        @unlink($pendingFile);
        
        // Log successful pairing
        $logFile = $this->getSyncDir() . '/pairing.log';
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "Device paired: {$pending['device_name']}\n", FILE_APPEND);
        
        return [
            'status' => 'paired',
            'token' => $token,
            'message' => 'Device successfully paired'
        ];
    }
    
    /**
     * List paired devices (for management).
     * GET /api?action=sync_devices
     */
    protected function actionSyncDevices() {
        $devices = $this->loadDevices();
        
        // Remove sensitive data
        $result = [];
        foreach ($devices as $id => $device) {
            $result[] = [
                'id' => $device['id'],
                'name' => $device['name'],
                'paired_at' => $device['paired_at'],
                'last_seen' => $device['last_seen']
            ];
        }
        
        return ['devices' => $result];
    }
    
    /**
     * Revoke a paired device.
     * POST /api?action=sync_device_revoke
     * Body: { "device_id": "uuid" }
     */
    protected function actionSyncDeviceRevoke($input) {
        $deviceId = $input['device_id'] ?? null;
        if (!$deviceId) {
            throw new Exception("device_id is required");
        }
        
        $devices = $this->loadDevices();
        if (isset($devices[$deviceId])) {
            $name = $devices[$deviceId]['name'];
            unset($devices[$deviceId]);
            $this->saveDevices($devices);
            
            return ['status' => 'revoked', 'message' => "Device '$name' has been unpaired"];
        }
        
        throw new Exception("Device not found");
    }
    
    /**
     * Get sync manifest - file hashes and timestamps for all boards.
     * GET /api?action=sync_manifest
     * Requires auth header.
     */
    protected function actionSyncManifest() {
        $this->validateSyncAuth();
        
        $manifest = [
            'server_time' => date('c'),
            'server_timestamp' => time(),
            'boards' => []
        ];
        
        foreach (glob($this->boardsDir . '/*', GLOB_ONLYDIR) as $boardDir) {
            $boardId = basename($boardDir);
            
            // Skip sync directory
            if ($boardId === '.sync') continue;
            
            $boardManifest = [
                'layout' => $this->getFileManifest("$boardDir/layout.json"),
                'users' => $this->getFileManifest("$boardDir/users.json"),
                'cards' => [],
                'uploads' => []
            ];
            
            // Get all card files
            foreach (glob("$boardDir/*.md") as $mdFile) {
                $cardId = basename($mdFile, '.md');
                $jsonFile = "$boardDir/$cardId.json";
                
                $boardManifest['cards'][$cardId] = [
                    'md' => $this->getFileManifest($mdFile),
                    'meta' => $this->getFileManifest($jsonFile)
                ];
            }
            
            // Get uploads
            $uploadsDir = "$boardDir/uploads";
            if (is_dir($uploadsDir)) {
                foreach (glob("$uploadsDir/*") as $file) {
                    if (is_file($file)) {
                        $filename = basename($file);
                        $boardManifest['uploads'][$filename] = $this->getFileManifest($file);
                    }
                }
            }

            // Cast empty arrays to objects for JSON encoding (Swift expects {} not [])
            if (empty($boardManifest['cards'])) {
                $boardManifest['cards'] = (object)[];
            }
            if (empty($boardManifest['uploads'])) {
                $boardManifest['uploads'] = (object)[];
            }

            $manifest['boards'][$boardId] = $boardManifest;
        }
        
        return $manifest;
    }
    
    private function getFileManifest($path) {
        if (!file_exists($path)) {
            return null;
        }
        
        return [
            'hash' => hash_file('sha256', $path),
            'modified' => filemtime($path),
            'size' => filesize($path)
        ];
    }
    
    /**
     * Pull specific files from server.
     * POST /api?action=sync_pull
     * Body: { "files": [{"board": "my-board", "type": "card_md", "id": "card-id"}, ...] }
     * Requires auth header.
     */
    protected function actionSyncPull($input) {
        $this->validateSyncAuth();
        
        $files = $input['files'] ?? [];
        if (empty($files)) {
            throw new Exception("No files specified");
        }
        
        $results = [];
        
        foreach ($files as $fileReq) {
            $boardId = $fileReq['board'] ?? null;
            $type = $fileReq['type'] ?? null;
            $id = $fileReq['id'] ?? null;
            
            if (!$boardId || !$type) {
                continue;
            }
            
            $boardDir = $this->boardsDir . '/' . $this->slugify($boardId);
            if (!is_dir($boardDir)) {
                continue;
            }
            
            $content = null;
            $path = null;
            
            switch ($type) {
                case 'layout':
                    $path = "$boardDir/layout.json";
                    break;
                case 'users':
                    $path = "$boardDir/users.json";
                    break;
                case 'card_md':
                    if ($this->isCardId($id)) $path = "$boardDir/$id.md";
                    break;
                case 'card_meta':
                    if ($this->isCardId($id)) $path = "$boardDir/$id.json";
                    break;
                case 'upload':
                    if ($id && $id === basename($id) && $id[0] !== '.') $path = "$boardDir/uploads/$id";
                    break;
            }
            
            if ($path && file_exists($path)) {
                $isText = in_array($type, ['layout', 'users', 'card_md', 'card_meta']);
                
                $results[] = [
                    'board' => $boardId,
                    'type' => $type,
                    'id' => $id,
                    'content' => $isText ? file_get_contents($path) : base64_encode(file_get_contents($path)),
                    'encoding' => $isText ? 'text' : 'base64',
                    'hash' => hash_file('sha256', $path),
                    'modified' => filemtime($path)
                ];
            }
        }
        
        return ['files' => $results];
    }
    
    /**
     * Push changes to server.
     * POST /api?action=sync_push
     * Body: { "changes": [{"board": "my-board", "type": "card_md", "id": "card-id", "content": "...", "client_modified": 12345}] }
     * Requires auth header.
     */
    protected function actionSyncPush($input) {
        $device = $this->validateSyncAuth();
        
        $changes = $input['changes'] ?? [];
        if (empty($changes)) {
            throw new Exception("No changes specified");
        }
        
        $results = [];
        
        foreach ($changes as $change) {
            $boardId = $change['board'] ?? null;
            $type = $change['type'] ?? null;
            $id = $change['id'] ?? null;
            $content = $change['content'] ?? null;
            $clientModified = $change['client_modified'] ?? 0;
            $encoding = $change['encoding'] ?? 'text';
            
            if (!$boardId || !$type) {
                $results[] = ['status' => 'error', 'message' => 'Missing board or type'];
                continue;
            }
            
            $boardDir = $this->boardsDir . '/' . $this->slugify($boardId);
            
            // Create board directory if needed
            if (!is_dir($boardDir)) {
                mkdir($boardDir, 0755, true);
                mkdir("$boardDir/uploads", 0755, true);
            }
            
            $path = null;
            
            switch ($type) {
                case 'layout':
                    $path = "$boardDir/layout.json";
                    break;
                case 'users':
                    $path = "$boardDir/users.json";
                    break;
                case 'card_md':
                    if ($this->isCardId($id)) $path = "$boardDir/$id.md";
                    break;
                case 'card_meta':
                    if ($this->isCardId($id)) $path = "$boardDir/$id.json";
                    break;
                case 'upload':
                    if ($id && $id === basename($id) && $id[0] !== '.' && in_array(strtolower(pathinfo($id, PATHINFO_EXTENSION)), self::UPLOAD_EXTS, true)) {
                        if (!is_dir("$boardDir/uploads")) mkdir("$boardDir/uploads", 0755, true);
                        $path = "$boardDir/uploads/$id";
                    }
                    break;
                case 'delete_card':
                    if ($this->isCardId($id)) {
                        $this->withBoardLock($this->slugify($boardId), function () use ($boardDir, $id) {
                            @unlink("$boardDir/$id.md");
                            @unlink("$boardDir/$id.json");
                        });
                        $results[] = ['board' => $boardId, 'type' => $type, 'id' => $id, 'status' => 'deleted'];
                        continue 2;
                    }
                    break;
            }
            
            if (!$path) {
                $results[] = ['status' => 'error', 'message' => 'Invalid file specification'];
                continue;
            }
            
            // Last-write-wins conflict resolution
            $serverModified = file_exists($path) ? filemtime($path) : 0;
            
            if ($clientModified >= $serverModified) {
                // Client is newer or same age, accept the change
                $data = ($encoding === 'base64') ? base64_decode($content) : $content;
                $this->withBoardLock($this->slugify($boardId), function () use ($type, $data, $path) {
                    if ($type === 'layout' || $type === 'users' || $type === 'card_meta') {
                        // Parse and re-encode JSON for consistency
                        $jsonData = json_decode($data, true);
                        if (is_array($jsonData) && $type === 'layout') {
                            $this->writeLayout($path, $jsonData);
                        } elseif (is_array($jsonData) && $type === 'card_meta') {
                            $this->atomicWrite($path, $this->mergeCardMeta($path, $jsonData));
                        } elseif ($jsonData !== null) {
                            $this->atomicWrite($path, $jsonData);
                        } else {
                            $this->atomicWrite($path, $data);
                        }
                    } else {
                        $this->atomicWrite($path, $data);
                    }
                });
                
                // Update search index for cards
                if ($type === 'card_md' || $type === 'card_meta') {
                    $this->reindexCard($boardId, $boardDir, $id);
                }
                
                $results[] = [
                    'board' => $boardId,
                    'type' => $type,
                    'id' => $id,
                    'status' => 'accepted',
                    'hash' => hash_file('sha256', $path),
                    'modified' => filemtime($path)
                ];
            } else {
                // Server is newer, reject the change
                $results[] = [
                    'board' => $boardId,
                    'type' => $type,
                    'id' => $id,
                    'status' => 'conflict',
                    'message' => 'Server version is newer',
                    'server_modified' => $serverModified,
                    'client_modified' => $clientModified
                ];
            }
        }
        
        return [
            'results' => $results,
            'server_time' => date('c')
        ];
    }
    
    /**
     * Quick status check for sync connection.
     * GET /api?action=sync_status
     * Requires auth header.
     */
    protected function actionSyncStatus() {
        $device = $this->validateSyncAuth();
        
        return [
            'status' => 'connected',
            'server_time' => date('c'),
            'device' => [
                'id' => $device['id'],
                'name' => $device['name'],
                'paired_at' => $device['paired_at']
            ],
            'beckon_version' => BECKON_VERSION
        ];
    }

    protected function actionCreateBoard($input) {
        $title = $input['title'] ?? 'New Board';
        $slug = $input['slug'] ?: $this->slugify($title);
        if (!$slug) $slug = 'board-' . date('ymd');

        $baseSlug = $slug;
        $counter = 1;
        while(file_exists($this->getBoardPath($slug))) $slug = $baseSlug . '-' . $counter++;

        $p = $this->getBoardPath($slug);
        mkdir($p, 0755, true);
        mkdir("$p/uploads", 0755, true);
        
        $this->writeLayout("$p/layout.json", ['version' => 1, 'title' => $title, 'lists' => []]);
        $this->atomicWrite("$p/users.json", new \stdClass());
        
        return ['status' => 'ok', 'id' => $slug];
    }

    protected function actionDeleteBoard($input) {
        $p = $this->getBoardPath($input['board']);
        if (is_dir($p) && !is_link($p)) {
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
        $this->searchIndex->removeBoard($this->slugify($input['board']));
        return ['status' => 'ok'];
    }

    protected function actionRenameBoard($input, $boardId, $boardDir) {
        $newTitle = trim((string) ($input['title'] ?? ''));
        $newSlug = $this->slugify($newTitle);
        if (!$newSlug) throw new Exception("Invalid title");
        if (!is_file("$boardDir/layout.json")) throw new Exception("Board not found");
        $newPath = $this->boardsDir . '/' . $newSlug;
        if ($boardId !== $newSlug && file_exists($newPath)) throw new Exception("A board named \"$newSlug\" already exists");

        return $this->withBoardLock($boardId, function () use ($boardId, $boardDir, $newTitle, $newSlug, $newPath) {
            $layout = json_decode(file_get_contents("$boardDir/layout.json"), true) ?? [];
            $layout['title'] = $newTitle;
            if ($boardId === $newSlug) {
                $this->writeLayout("$boardDir/layout.json", $layout);
                $this->searchIndex->updateBoard($boardId, $boardId, $newTitle);
                return ['status' => 'updated', 'id' => $boardId, 'name' => $newTitle];
            }
            // Upload links live in card text, card covers and member avatars.
            $old = "boards/$boardId/uploads/"; $new = "boards/$newSlug/uploads/";
            $layout = json_decode(str_replace($old, $new, json_encode($layout, JSON_UNESCAPED_SLASHES)), true);
            $this->writeLayout("$boardDir/layout.json", $layout);
            foreach (array_merge(glob("$boardDir/*.md") ?: [], glob("$boardDir/*.json") ?: []) as $file) {
                $c = file_get_contents($file);
                $newC = str_replace([$old, str_replace('/', '\\/', $old)], [$new, str_replace('/', '\\/', $new)], $c);
                if ($c !== $newC) $this->atomicWrite($file, $newC);
            }
            @unlink("$boardDir/lock");
            rename($boardDir, $newPath);
            $this->searchIndex->updateBoard($boardId, $newSlug, $newTitle);
            return ['status' => 'renamed', 'id' => $newSlug, 'name' => $newTitle];
        });
    }

    protected function actionLoad($input, $boardId, $boardDir) {
        $layoutPath = "$boardDir/layout.json";
        if (!$boardDir || !is_file($layoutPath)) throw new Exception("Board not found");
        
        // Use a shared lock for reading to prevent reading while a write is happening
        $fp = fopen($layoutPath, 'r');
        if (flock($fp, LOCK_SH)) {
            $data = json_decode(stream_get_contents($fp), true) ?? [];
            flock($fp, LOCK_UN);
        } else {
            // Fallback if lock fails (rare)
            $data = json_decode(file_get_contents($layoutPath), true) ?? [];
        }
        fclose($fp);

        $users = json_decode(@file_get_contents("$boardDir/users.json"), true) ?? [];
        
        // --- Migration Check ---
        if (!isset($data['version']) || $data['version'] < 2) {
            // Upgrade lock
            $data = $this->withBoardLock($boardId, function() use ($boardId, $boardDir, $layoutPath) {
                $lockedData = json_decode(@file_get_contents($layoutPath), true) ?? [];
                if ($this->migrateBoard($boardId, $boardDir, $lockedData)) {
                    $lockedData['rev'] = $this->writeLayout($layoutPath, $lockedData);
                }
                return $lockedData;
            });
        }

        // Ensure defaults
        if (!isset($data['lists'])) $data['lists'] = [['id' => 'l1', 'title' => 'Start', 'cards' => []]];
        if (!isset($data['archive'])) $data['archive'] = [];
        if (!isset($data['title'])) $data['title'] = ucfirst(basename($boardDir));
        $data['rev'] = (int) ($data['rev'] ?? 0);

        $data['users'] = $users;
        return $data;
    }

    protected function actionGetCard($input, $boardId, $boardDir) {
        $id = $this->cardId($input['id'] ?? '');

        $description = @file_get_contents("$boardDir/$id.md") ?: '';
        $meta = json_decode(@file_get_contents("$boardDir/$id.json"), true) ?? [];
        
        // Merge defaults
        $meta = array_merge([
            'comments' => [], 
            'activity' => [], 
            'revisions' => [], 
            'assigned_to' => [],
            'checklists' => []
        ], $meta);

        return [
            'description' => $description,
            'meta' => $meta
        ];
    }

    protected function actionLoadCardMeta($input, $boardId, $boardDir) {
        $id = $this->cardId($input['id'] ?? '');
        $meta = json_decode(@file_get_contents("$boardDir/$id.json"), true) ?? [];
        return array_merge(['comments' => [], 'activity' => [], 'revisions' => [], 'assigned_to' => []], $meta);
    }

    protected function actionSaveLayout($input, $boardId, $boardDir) {
        if (!$boardDir || !is_file("$boardDir/layout.json")) throw new Exception("Board not found");
        return $this->withBoardLock($boardId, function() use ($input, $boardDir) {
            // Refuse a save built on an older copy: the tab merges its changes into this one and retries.
            $current = json_decode(@file_get_contents("$boardDir/layout.json"), true) ?? [];
            $currentRev = (int) ($current['rev'] ?? 0);
            if (array_key_exists('baseRev', $input) && (int) $input['baseRev'] !== $currentRev) {
                http_response_code(409);
                $current['rev'] = $currentRev;
                unset($current['users']);
                return ['error' => 'conflict', 'rev' => $currentRev, 'layout' => $current];
            }

            // 1. Schema: Ensure version exists
            $input['version'] = $input['version'] ?? 1;

            // Strip descriptions (keep layout lightweight)
            foreach ($input['lists'] as &$list) {
                foreach ($list['cards'] as &$card) {
                    unset($card['description']);
                    // Resilience: In a heavier app, we would write to ID.json here,
                    // but for v1 performance, we rely on the lock to protect layout.json.
                }
            }
            if (isset($input['archive'])) {
                foreach ($input['archive'] as &$card) unset($card['description']);
            }
            
            // 2. Write safely inside the lock
            $rev = $this->writeLayout("$boardDir/layout.json", $input);
            return ['status' => 'saved', 'version' => 1, 'rev' => $rev, 'hash' => md5_file("$boardDir/layout.json")];
        });
    }

    protected function actionSaveCard($input, $boardId, $boardDir) {
        $this->cardId($input['id'] ?? '');
        if (!is_dir($boardDir)) throw new Exception("Board not found");
        $this->atomicWrite("$boardDir/{$input['id']}.md", $input['description'] ?? '');
        
        // Update search index
        $this->reindexCard($boardId, $boardDir, $input['id']);
        
        return ['status' => 'saved'];
    }

    protected function actionSaveCardMeta($input, $boardId, $boardDir) {
        $id = $this->cardId($input['id'] ?? '');
        if (!is_dir($boardDir)) throw new Exception("Board not found");
        $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];

        if (isset($input['title'])) $meta['title'] = $input['title'];
        if (isset($input['labels'])) $meta['labels'] = $input['labels'];

        $this->withBoardLock($boardId, function () use ($boardDir, $id, &$meta) {
            $path = "$boardDir/$id.json";
            $meta = $this->mergeCardMeta($path, $meta);
            $this->atomicWrite($path, $meta);
        });

        // Update search index
        $this->reindexCard($boardId, $boardDir, $id);

        return ['status' => 'saved', 'comments' => $meta['comments'] ?? []];
    }

    /**
     * A card's JSON as saved by a client, reconciled with what is on disk. Comments change only
     * through the comment endpoints, so a client saving its older copy can't drop someone else's
     * comment. Activity and revisions are logs: entries from both copies are kept.
     */
    private function mergeCardMeta($path, array $meta) {
        $cur = is_file($path) ? (json_decode(file_get_contents($path), true) ?? []) : null;
        if (!is_array($cur)) return $meta;
        if (array_key_exists('comments', $cur)) $meta['comments'] = $cur['comments'];
        $meta['activity'] = $this->mergeLog($cur['activity'] ?? [], $meta['activity'] ?? [], fn($e) => ($e['date'] ?? '') . '|' . ($e['text'] ?? ''));
        $meta['revisions'] = array_slice($this->mergeLog($cur['revisions'] ?? [], $meta['revisions'] ?? [], fn($e) => $e['id'] ?? (($e['date'] ?? '') . '|' . md5($e['text'] ?? ''))), 0, 50);
        return $meta;
    }

    /** Union of two logs by key, newest first. */
    private function mergeLog(array $a, array $b, callable $key) {
        $all = [];
        foreach (array_merge($a, $b) as $e) { if (is_array($e)) $all[$key($e)] = $e; }
        $all = array_values($all);
        usort($all, fn($x, $y) => strcmp((string) ($y['date'] ?? ''), (string) ($x['date'] ?? '')));
        return $all;
    }

    /** Read-modify-write of a card's comments under the board lock. */
    private function withComments($boardId, $boardDir, $cardId, callable $fn) {
        $cardId = $this->cardId($cardId);
        if (!is_dir($boardDir)) throw new Exception("Board not found");
        return $this->withBoardLock($boardId, function () use ($boardDir, $cardId, $fn, $boardId) {
            $path = "$boardDir/$cardId.json";
            $meta = is_file($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
            $meta['comments'] = $fn($meta['comments'] ?? []);
            $this->atomicWrite($path, $meta);
            $this->reindexCard($boardId, $boardDir, $cardId);
            return ['status' => 'saved', 'comments' => $meta['comments']];
        });
    }

    protected function actionCommentAdd($input, $boardId, $boardDir) {
        $c = $input['comment'] ?? null;
        if (!is_array($c) || trim((string) ($c['text'] ?? '')) === '') throw new Exception("Empty comment");
        $comment = [
            'id' => preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string) ($c['id'] ?? '')) ? $c['id'] : date('Y-m-d') . '_' . bin2hex(random_bytes(8)),
            'text' => (string) $c['text'],
            'date' => $c['date'] ?? date('c'),
            'user_id' => $c['user_id'] ?? null,
            'user' => is_array($c['user'] ?? null) ? $c['user'] : null,
            'reactions' => [],
        ];
        return $this->withComments($boardId, $boardDir, $input['id'] ?? '', function ($comments) use ($comment) {
            foreach ($comments as $x) if (($x['id'] ?? null) === $comment['id']) return $comments;
            array_unshift($comments, $comment);
            return $comments;
        });
    }

    protected function actionCommentEdit($input, $boardId, $boardDir) {
        $cid = $input['comment_id'] ?? ''; $text = (string) ($input['text'] ?? '');
        return $this->withComments($boardId, $boardDir, $input['id'] ?? '', function ($comments) use ($cid, $text) {
            foreach ($comments as &$x) if (($x['id'] ?? null) === $cid) { $x['text'] = $text; $x['editedDate'] = date('c'); }
            return $comments;
        });
    }

    protected function actionCommentDelete($input, $boardId, $boardDir) {
        $cid = $input['comment_id'] ?? '';
        return $this->withComments($boardId, $boardDir, $input['id'] ?? '', fn($comments) => array_values(array_filter($comments, fn($x) => ($x['id'] ?? null) !== $cid)));
    }

    protected function actionToggleReaction($input, $boardId, $boardDir) {
        $commentId = $input['comment_id'] ?? null;
        $emoji = $input['emoji'] ?? null;
        $userId = $input['user_id'] ?? null;
        if (empty($input['card_id']) || !$commentId || !$emoji || !$userId) throw new Exception("Missing parameters");
        $found = false;
        $r = $this->withComments($boardId, $boardDir, $input['card_id'], function ($comments) use ($commentId, $emoji, $userId, &$found) {
            foreach ($comments as &$comment) {
                if (($comment['id'] ?? null) !== $commentId) continue;
                $found = true;
                $reactions = $comment['reactions'] ?? [];
                $i = array_search($emoji, array_column($reactions, 'emoji'), true);
                if ($i === false) { $reactions[] = ['emoji' => $emoji, 'users' => [$userId]]; }
                else {
                    $users = $reactions[$i]['users'] ?? [];
                    $u = array_search($userId, $users, true);
                    if ($u === false) $users[] = $userId; else array_splice($users, $u, 1);
                    if ($users) $reactions[$i]['users'] = array_values($users); else array_splice($reactions, $i, 1);
                }
                $comment['reactions'] = array_values($reactions);
            }
            return $comments;
        });
        if (!$found) throw new Exception("Comment not found");
        return $r;
    }

    protected function actionGetBoardLists($input) {
        $targetBoardId = $input['board_id'] ?? null;
        if (!$targetBoardId) throw new Exception("No board ID provided");

        $targetBoardPath = $this->getBoardPath($targetBoardId);
        if (!file_exists($targetBoardPath)) throw new Exception("Target board not found");

        $layout = json_decode(@file_get_contents("$targetBoardPath/layout.json"), true) ?? ['lists' => []];
        
        $lists = array_map(function($list) {
            return ['id' => $list['id'], 'title' => $list['title']];
        }, $layout['lists'] ?? []);

        return ['lists' => $lists];
    }

    protected function actionMoveCardToBoard($input, $boardId, $boardDir) {
        $targetId = $this->slugify($input['target_board'] ?? '');
        if (!$targetId || !is_dir($this->getBoardPath($targetId))) throw new Exception("Target board not found");
        if ($targetId === $boardId) throw new Exception("The card is already on that board");
        $this->cardId($input['id'] ?? '');

        // Canonical locking order, so two opposite moves can't wait on each other forever.
        $firstLock  = strcmp($boardId, $targetId) < 0 ? $boardId : $targetId;
        $secondLock = $firstLock === $boardId ? $targetId : $boardId;

        return $this->withBoardLock($firstLock, function() use ($input, $boardId, $boardDir, $targetId, $secondLock) {
            return $this->withBoardLock($secondLock, function() use ($input, $boardId, $boardDir, $targetId) {
                
                // --- Start Transaction Logic ---
                $cardId = $input['id'];
                $targetListId = $input['target_list_id'] ?? null;
                $targetPath = $this->getBoardPath($targetId);

                // 1. Read Source (Safe read due to lock)
                $sourceLayout = json_decode(file_get_contents("$boardDir/layout.json"), true);
                $cardData = null;
                
                // Find and remove from source
                foreach ($sourceLayout['lists'] as &$list) {
                    foreach ($list['cards'] as $key => $card) {
                        if ($card['id'] == $cardId) {
                            $cardData = $card;
                            array_splice($list['cards'], $key, 1);
                            break 2;
                        }
                    }
                }
                
                // Check archive if not found in lists
                if (!$cardData && isset($sourceLayout['archive'])) {
                    foreach ($sourceLayout['archive'] as $key => $card) {
                        if ($card['id'] == $cardId) {
                            $cardData = $card;
                            array_splice($sourceLayout['archive'], $key, 1);
                            break;
                        }
                    }
                }

                if (!$cardData) throw new Exception("Card not found");

                // 2. Read Target (Safe read due to lock)
                $targetLayout = json_decode(@file_get_contents("$targetPath/layout.json"), true) ?? [];
                if (empty($targetLayout['lists'])) $targetLayout['lists'] = [['id' => 'l1', 'title' => 'Inbox', 'cards' => []]];
                
                // Add to target
                $listFound = false;
                if ($targetListId) {
                    foreach ($targetLayout['lists'] as &$list) {
                        if ($list['id'] == $targetListId) {
                            array_unshift($list['cards'], $cardData);
                            $listFound = true;
                            break;
                        }
                    }
                }
                if (!$listFound) array_unshift($targetLayout['lists'][0]['cards'], $cardData);

                // 3. Move Physical Files
                foreach (["$cardId.md", "$cardId.json"] as $f) {
                    if (file_exists("$boardDir/$f")) rename("$boardDir/$f", "$targetPath/$f");
                }

                // 4. Update Activity Log
                $metaFile = "$targetPath/$cardId.json";
                $meta = json_decode(@file_get_contents($metaFile), true) ?? [];
                $meta['activity'] = $meta['activity'] ?? [];
                $targetTitle = $targetLayout['title'] ?? $targetId;
                array_unshift($meta['activity'], ['text' => "Moved from board '{$sourceLayout['title']}' to '{$targetTitle}'", 'date' => date('c')]);
                
                // Resilience: Ensure ID.json has the card title/labels backup
                $meta['title'] = $cardData['title'];
                $meta['labels'] = $cardData['labels'] ?? [];
                $this->atomicWrite($metaFile, $meta);

                // 5. Commit Writes
                $this->writeLayout("$boardDir/layout.json", $sourceLayout);
                $this->writeLayout("$targetPath/layout.json", $targetLayout);

                // 6. Update search index (card moved to new board)
                $this->reindexCard($targetId, $targetPath, $cardId);

                return ['status' => 'moved'];
            });
        });
    }

    protected function actionListUploads($input, $boardId, $boardDir) {
        $files = glob("$boardDir/uploads/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}", GLOB_BRACE);
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        return ['files' => array_map(fn($f) => "boards/$boardId/uploads/" . basename($f), $files)];
    }

    protected function actionDeleteCard($input, $boardId, $boardDir) {
        $id = $this->cardId($input['id'] ?? '');
        if (file_exists("$boardDir/$id.md")) unlink("$boardDir/$id.md");
        if (file_exists("$boardDir/$id.json")) unlink("$boardDir/$id.json");
        
        // Remove from search index
        $this->searchIndex->removeCard($id);
        
        return ['status' => 'deleted'];
    }

    protected function actionUpload($input, $boardId, $boardDir) {
        if (!isset($_FILES['file'])) throw new Exception('No file');
        $dir = "$boardDir/uploads";
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $name = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
        $ext = $this->allowedExt(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $clean = $this->slugify($name) ?: 'file';
        
        $filename = "$clean.$ext";
        $counter = 1;
        while (file_exists("$dir/$filename")) $filename = "$clean-" . $counter++ . ".$ext";
        
        move_uploaded_file($_FILES['file']['tmp_name'], "$dir/$filename");
        return ['url' => "boards/$boardId/uploads/$filename"];
    }

    // --- Helpers ---

    private function getBoardPath($id) {
        $clean = $this->slugify($id);
        if (!$clean || $clean === '.' || $clean === '..') throw new Exception("Invalid ID");
        return $this->boardsDir . '/' . $clean;
    }

    private function slugify($text) {
        $text = preg_replace('/[^a-z0-9-_]/i', '-', strtolower($text));
        return trim(preg_replace('/-+/', '-', $text), '-');
    }

    // --- Safe File Operations ---

    /**
     * Executes a callback within an exclusive lock on the board directory.
     * Prevents race conditions when multiple users move cards simultaneously.
     */
    private function withBoardLock($boardId, callable $callback) {
        if (!$boardId) throw new Exception("No board specified for locking");
        
        $lockFile = $this->boardsDir . '/' . $boardId . '/lock';
        $fp = fopen($lockFile, 'c+'); // Create if not exists, don't truncate
        if (!$fp) throw new Exception("Could not open lock file");

        // Acquire exclusive lock (this will wait/block until available)
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new Exception("Could not acquire lock");
        }

        try {
            return $callback();
        } finally {
            flock($fp, LOCK_UN); // Release lock
            fclose($fp);
        }
    }

    /**
     * Writes data to a file atomically with exclusive locking.
     * Handles arrays (auto-JSON) and strings.
     */
    private function atomicWrite($filepath, $data) {
        $content = (is_array($data) || is_object($data)) 
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) 
            : $data;

        $tempPath = $filepath . '.tmp.' . uniqid(); // Unique temp file
        
        $fp = fopen($tempPath, 'w');
        if (!$fp) throw new Exception("Could not open temp file: $tempPath");

        if (!flock($fp, LOCK_EX)) { // Acquire exclusive lock
            fclose($fp);
            throw new Exception("Could not lock file: $tempPath");
        }

        $written = fwrite($fp, $content);
        fflush($fp);            // Flush output before releasing lock
        flock($fp, LOCK_UN);    // Release lock
        fclose($fp);

        if ($written === false) {
            @unlink($tempPath);
            throw new Exception("Failed to write data to $tempPath");
        }

        if (DIRECTORY_SEPARATOR === '\\' && file_exists($filepath)) {
            $deleted = @unlink($filepath);
            if (!$deleted) {
                @unlink($tempPath);
                throw new Exception("Could not overwrite existing file on Windows: $filepath");
            }
        }

        if (!rename($tempPath, $filepath)) {
            @unlink($tempPath);
            throw new Exception("Failed to move temp file to $filepath");
        }

        @chmod($filepath, 0644);
        
        return true;
    }

    /**
     * Handles schema migrations and data integrity checks.
     * Upgrades older board data structures to the current version.
     */
    private function migrateBoard($boardId, $boardDir, &$data) {
        $modified = false;
        // Start at current version, default to 0
        $currentVersion = $data['version'] ?? 0;
        
        // Target version (match your constant BECKON_VERSION major version)
        $targetVersion = 2; 

        // Migration Loop: Sequential Updates
        while ($currentVersion < $targetVersion) {
            
            // --- v0 -> v1: Basic Hygiene ---
            if ($currentVersion < 1) {
                if (!isset($data['archive'])) {
                    $data['archive'] = [];
                    $modified = true;
                }
                // (Existing logic: ensure meta files have title/labels)
                $allCards = $this->getAllCardsFlat($data);
                foreach ($allCards as $card) {
                    $metaPath = "$boardDir/{$card['id']}.json";
                    if (file_exists($metaPath)) {
                        $meta = json_decode(file_get_contents($metaPath), true);
                        if (!isset($meta['title']) || !isset($meta['labels'])) {
                            $meta['title'] = $card['title'] ?? 'Untitled';
                            $meta['labels'] = $card['labels'] ?? [];
                            $this->atomicWrite($metaPath, $meta);
                        }
                    }
                }
                $currentVersion = 1;
                $modified = true;
            }

            // --- v1 -> v2: Performance Backfill ---
            if ($currentVersion < 2) {
                $processList = function(&$cards) use ($boardDir) {
                    foreach ($cards as &$card) {
                        // 1. Backfill Comment Count
                        if (!isset($card['commentCount'])) {
                            $meta = json_decode(@file_get_contents("$boardDir/{$card['id']}.json"), true);
                            $card['commentCount'] = isset($meta['comments']) ? count($meta['comments']) : 0;
                        }

                        // Read Markdown content
                        $md = @file_get_contents("$boardDir/{$card['id']}.md") ?: '';

                        // 2. Backfill "Has Description" / "Has Attachment"
                        if (!isset($card['hasDesc']) || !isset($card['hasAtt'])) {
                            $card['hasDesc'] = !empty(trim($md));
                            $card['hasAtt'] = (strpos($md, '/uploads/') !== false);
                        }

                        // 3. Backfill Markdown Checkbox Stats (NEW)
                        // Only strictly necessary if they don't have UI checklists, 
                        // but good to calculate anyway.
                        if (!isset($card['descStats'])) {
                            $total = preg_match_all('/- \[[ xX]\]/', $md); // Matches - [ ] and - [x]
                            $done = preg_match_all('/- \[[xX]\]/', $md);   // Matches - [x]
                            
                            // Only save if there are actually tasks
                            if ($total > 0) {
                                $card['descStats'] = ['total' => $total, 'done' => $done];
                            }
                        }
                    }
                };

                foreach ($data['lists'] as &$list) $processList($list['cards']);
                if (isset($data['archive'])) $processList($data['archive']);
                
                $currentVersion = 2;
                $modified = true;
            }
        }

        $data['version'] = $currentVersion;
        return $modified;
    }

    // Helper for migration
    private function getAllCardsFlat($data) {
        $all = $data['archive'] ?? [];
        foreach ($data['lists'] as $list) {
            $all = array_merge($all, $list['cards'] ?? []);
        }
        return $all;
    }

    // Helper to reindex a single card
    private function reindexCard($boardId, $boardDir, $cardId) {
        if (!$this->searchIndex || !$this->searchIndex->isAvailable()) return;
        
        // Get board name
        $layout = json_decode(@file_get_contents("$boardDir/layout.json"), true);
        $boardName = $layout['title'] ?? $boardId;
        
        // Find card in layout
        $cardData = null;
        foreach ($layout['lists'] ?? [] as $list) {
            foreach ($list['cards'] ?? [] as $card) {
                if ($card['id'] === $cardId) { $cardData = $card; break 2; }
            }
        }
        if (!$cardData) {
            foreach ($layout['archive'] ?? [] as $card) {
                if ($card['id'] === $cardId) { $cardData = $card; break; }
            }
        }
        
        if (!$cardData) {
            $this->searchIndex->removeCard($cardId);
            return;
        }
        
        // Read full content
        $description = @file_get_contents("$boardDir/$cardId.md") ?: '';
        $meta = json_decode(@file_get_contents("$boardDir/$cardId.json"), true) ?? [];
        
        $this->searchIndex->indexCard(
            $boardId,
            $boardName,
            $cardId,
            $cardData['title'] ?? '',
            $description,
            $meta['comments'] ?? [],
            $cardData['labels'] ?? []
        );
    }
}

// ========================================
// SEARCH INDEX (SQLite FTS5)
// ========================================

class SearchIndex {
    private $db;
    private $boardsDir;
    
    public function __construct($boardsDir) {
        $this->boardsDir = $boardsDir;
        $dbPath = "$boardsDir/search.db";
        
        try {
            $this->db = new \PDO("sqlite:$dbPath");
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->ensureSchema();
        } catch (\Exception $e) {
            $this->db = null; // Graceful fallback
        }
    }
    
    public function isAvailable() {
        return $this->db !== null;
    }
    
    private function ensureSchema() {
        // Check if tables exist
        $result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='cards_fts'");
        if ($result->fetch()) return;
        
        // Create FTS5 virtual table
        $this->db->exec("
            CREATE VIRTUAL TABLE cards_fts USING fts5(
                board_id,
                card_id,
                title,
                description,
                comments,
                labels,
                tokenize='porter unicode61'
            )
        ");
        
        // Metadata tracking table
        $this->db->exec("
            CREATE TABLE card_index (
                card_id TEXT PRIMARY KEY,
                board_id TEXT NOT NULL,
                board_name TEXT,
                updated_at INTEGER NOT NULL,
                title TEXT,
                has_description INTEGER,
                label_json TEXT
            )
        ");
        
        // Schema version
        $this->db->exec("
            CREATE TABLE meta (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ");
        $this->db->exec("INSERT INTO meta (key, value) VALUES ('version', '1'), ('last_reindex', '0')");
    }
    
    public function indexCard($boardId, $boardName, $cardId, $title, $description, $comments, $labels) {
        if (!$this->db) return;
        
        // Remove existing entry
        $this->removeCard($cardId);
        
        // Prepare text fields
        $labelText = is_array($labels) ? implode(' ', array_column($labels, 'name')) : '';
        $commentText = is_array($comments) ? implode("\n", array_column($comments, 'text')) : '';
        
        // Insert into FTS
        $stmt = $this->db->prepare(
            "INSERT INTO cards_fts (board_id, card_id, title, description, comments, labels) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$boardId, $cardId, $title, $description ?? '', $commentText, $labelText]);
        
        // Track metadata
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
    
    public function removeBoard($boardId) {
        if (!$this->db) return;
        $this->db->prepare("DELETE FROM cards_fts WHERE board_id = ?")->execute([$boardId]);
        $this->db->prepare("DELETE FROM card_index WHERE board_id = ?")->execute([$boardId]);
    }

    public function updateBoard($oldId, $newId, $newName) {
        if (!$this->db) return;
        $this->db->prepare("UPDATE card_index SET board_id = ?, board_name = ? WHERE board_id = ?")->execute([$newId, $newName, $oldId]);
        $this->db->prepare("UPDATE cards_fts SET board_id = ? WHERE board_id = ?")->execute([$newId, $oldId]);
    }

    public function updateBoardName($boardId, $newName) {
        if (!$this->db) return;
        $this->db->prepare("UPDATE card_index SET board_name = ? WHERE board_id = ?")->execute([$newName, $boardId]);
    }
    
    public function search($query, $boardId = null, $limit = 50) {
        if (!$this->db || empty(trim($query))) return [];
        
        $ftsQuery = $this->buildFtsQuery($query);
        
        $sql = "SELECT 
                    f.card_id, 
                    f.board_id,
                    c.board_name,
                    c.title,
                    c.has_description,
                    c.label_json,
                    snippet(cards_fts, 3, '<mark>', '</mark>', '...', 48) as snippet,
                    bm25(cards_fts, 0, 0, 10.0, 5.0, 2.0, 3.0) as rank
                FROM cards_fts f
                JOIN card_index c ON f.card_id = c.card_id
                WHERE cards_fts MATCH ?";
        
        $params = [$ftsQuery];
        
        if ($boardId) {
            $sql .= " AND f.board_id = ?";
            $params[] = $boardId;
        }
        
        $sql .= " ORDER BY rank LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Parse label JSON
        foreach ($results as &$row) {
            $row['labels'] = json_decode($row['label_json'], true) ?? [];
            unset($row['label_json']);
        }
        
        return $results;
    }
    
    private function buildFtsQuery($query) {
        $query = trim($query);
        
        // If user uses quotes, pass through for phrase search
        if (strpos($query, '"') !== false) {
            return $query;
        }
        
        // Otherwise, make each term a prefix search for partial matching
        $terms = preg_split('/\s+/', $query);
        $terms = array_filter($terms, fn($t) => strlen($t) > 0);
        
        // Escape FTS5 special characters
        $escaped = array_map(function($term) {
            $term = preg_replace('/["\'\(\)\*\:\-]/', '', $term);
            return $term . '*';
        }, $terms);
        
        return implode(' ', $escaped);
    }
    
    public function reindexAll() {
        if (!$this->db) return ['status' => 'error', 'message' => 'SQLite not available'];
        
        // Clear existing data
        $this->db->exec("DELETE FROM cards_fts");
        $this->db->exec("DELETE FROM card_index");
        
        $indexed = 0;
        $boards = glob($this->boardsDir . '/*', GLOB_ONLYDIR);
        
        foreach ($boards as $boardDir) {
            $boardId = basename($boardDir);
            $layoutPath = "$boardDir/layout.json";
            
            if (!file_exists($layoutPath)) continue;
            
            $layout = json_decode(file_get_contents($layoutPath), true);
            if (!$layout) continue;
            
            $boardName = $layout['title'] ?? $boardId;
            
            // Get all cards (lists + archive)
            $allCards = [];
            foreach ($layout['lists'] ?? [] as $list) {
                foreach ($list['cards'] ?? [] as $card) {
                    $allCards[] = $card;
                }
            }
            foreach ($layout['archive'] ?? [] as $card) {
                $allCards[] = $card;
            }
            
            foreach ($allCards as $card) {
                $cardId = $card['id'];
                $title = $card['title'] ?? '';
                $labels = $card['labels'] ?? [];
                
                // Read description
                $description = @file_get_contents("$boardDir/$cardId.md") ?: '';
                
                // Read comments
                $meta = json_decode(@file_get_contents("$boardDir/$cardId.json"), true) ?? [];
                $comments = $meta['comments'] ?? [];
                
                $this->indexCard($boardId, $boardName, $cardId, $title, $description, $comments, $labels);
                $indexed++;
            }
        }
        
        // Update last reindex timestamp
        $this->db->prepare("UPDATE meta SET value = ? WHERE key = 'last_reindex'")->execute([time()]);
        
        return ['status' => 'ok', 'indexed' => $indexed];
    }
    
    public function getStats() {
        if (!$this->db) return ['available' => false];
        
        $count = $this->db->query("SELECT COUNT(*) FROM card_index")->fetchColumn();
        $lastReindex = $this->db->query("SELECT value FROM meta WHERE key = 'last_reindex'")->fetchColumn();
        
        return [
            'available' => true,
            'card_count' => (int)$count,
            'last_reindex' => (int)$lastReindex
        ];
    }
}

// ========================================
// UPDATER (GitHub releases, verified downloads)
// ========================================

class Updater {
    const REPO = 'austinginder/beckon';
    const FILES = ['index.php', 'beckon-cli.php'];
    const KEEP_BACKUPS = 3;

    private $baseDir;
    private $stateFile;
    private $backupDir;
    private $api;

    public function __construct($baseDir) {
        $this->baseDir = rtrim($baseDir, '/');
        $this->stateFile = $this->baseDir . '/boards/update_state.json';
        $this->backupDir = $this->baseDir . '/boards/.updates';
        $this->api = rtrim(defined('BECKON_UPDATE_API') ? BECKON_UPDATE_API : (getenv('BECKON_UPDATE_API') ?: 'https://api.github.com/repos/' . self::REPO), '/');
    }

    public function isGitCheckout() {
        return is_dir($this->baseDir . '/.git');
    }

    const CACHE_TTL = 604800;   // a week between background checks
    const RETRY_TTL = 86400;    // a day before retrying a failed check

    /**
     * Returns the update summary. GitHub is contacted only when $force is set, or when
     * $refresh is set and the cached answer is older than a week (a failed check waits a
     * day). Callers that only want the cached state pass neither, so an idle install never
     * talks to GitHub: the browser asks for a refresh once per session, the CLI forces.
     */
    public function check($force = false, $refresh = false) {
        $this->adoptLegacyBackup();
        $state = $this->readState();
        $age = time() - ($state['last_check'] ?? 0);
        $stale = $force || ($refresh && (empty($state['latest_version']) || $age > self::CACHE_TTL || (!empty($state['error']) && $age > self::RETRY_TTL)));
        if ($stale) {
            try {
                $release = $this->fetchLatest();
                $state = array_merge($release, ['last_check' => time(), 'error' => null]);
            } catch (Exception $e) {
                $state['last_check'] = time();
                $state['error'] = $e->getMessage();
            }
            $this->writeState($state);
        }
        return $this->summary($state);
    }

    /**
     * Installs the latest release. Takes no target version on purpose: the server picks
     * the newest release and only ever moves forward. $preCheck($tmpPath, $name) may throw
     * to veto a file (the CLI runs php -l there).
     */
    public function install(?callable $preCheck = null) {
        if ($this->isGitCheckout()) throw new Exception("This is a git checkout. Update it with git pull instead.");
        $summary = $this->check(true);
        if (!empty($summary['error']) && empty($summary['latest'])) throw new Exception("Update check failed: " . $summary['error']);
        if (!$summary['update_available']) throw new Exception("Already up to date (v" . BECKON_VERSION . ").");
        $state = $this->readState();
        $version = $state['latest_version'];
        $assets = $state['assets'] ?? [];
        if (empty($assets['index.php']['url'])) throw new Exception("Release v$version has no index.php asset.");

        $targets = ['index.php'];
        if (file_exists($this->baseDir . '/beckon-cli.php') && !empty($assets['beckon-cli.php']['url'])) $targets[] = 'beckon-cli.php';

        $staged = []; $tmp = null;
        try {
            foreach ($targets as $name) {
                $asset = $assets[$name];
                if (empty($asset['sha256'])) throw new Exception("Release v$version publishes no checksum for $name. Refusing to install an unverified file.");
                $path = $this->baseDir . '/' . $name;
                if (!is_writable($path) || !is_writable($this->baseDir)) throw new Exception("$name is not writable by this process. Run: php beckon-cli.php update");
                $tmp = "$path.update-" . uniqid() . ".tmp";
                $this->download($asset['url'], $tmp);
                $actual = hash_file('sha256', $tmp);
                if (!hash_equals(strtolower($asset['sha256']), $actual)) throw new Exception("Checksum mismatch for $name. Expected {$asset['sha256']}, got $actual.");
                $head = file_get_contents($tmp, false, null, 0, 200);
                if (strpos($head, '<?php') === false) throw new Exception("Downloaded $name does not look like a PHP file.");
                if ($name === 'index.php' && strpos(file_get_contents($tmp), "define('BECKON_VERSION', '$version')") === false) throw new Exception("Downloaded index.php does not declare version $version.");
                if ($preCheck) $preCheck($tmp, $name);
                @chmod($tmp, fileperms($path) & 0777);
                $staged[$name] = $tmp;
            }
        } catch (Exception $e) {
            if ($tmp) @unlink($tmp);
            foreach ($staged as $t) @unlink($t);
            throw $e;
        }

        $this->ensureBackupDir();
        foreach ($staged as $name => $tmp) {
            $path = $this->baseDir . '/' . $name;
            $backup = $this->backupDir . "/$name." . $this->versionOf($path, $name) . '.' . time();
            if (!copy($path, $backup)) { foreach ($staged as $t) @unlink($t); throw new Exception("Could not back up $name."); }
            if (DIRECTORY_SEPARATOR === '\\' && file_exists($path)) @unlink($path);
            if (!rename($tmp, $path)) throw new Exception("Could not replace $name. The previous copy is at $backup.");
        }
        $this->pruneBackups();
        if (function_exists('opcache_reset')) @opcache_reset();
        $state['last_check'] = 0;
        $this->writeState($state);
        return ['status' => 'updated', 'from' => BECKON_VERSION, 'to' => $version, 'files' => array_keys($staged)];
    }

    /** Restores the most recent backup of each file taken by install(). */
    public function rollback() {
        $restored = [];
        foreach (self::FILES as $name) {
            $backup = $this->latestBackup($name);
            if (!$backup) continue;
            $path = $this->baseDir . '/' . $name;
            if (!is_writable($path) || !is_writable($this->baseDir)) throw new Exception("$name is not writable by this process.");
            $this->ensureBackupDir();
            $keep = $this->backupDir . "/$name." . $this->versionOf($path, $name) . '.' . time() . '.replaced';
            copy($path, $keep);
            if (DIRECTORY_SEPARATOR === '\\') @unlink($path);
            if (!rename($backup, $path)) throw new Exception("Could not restore $name from $backup.");
            $restored[$name] = $this->versionOf($path, $name);
        }
        if (!$restored) throw new Exception("No backup to restore.");
        if (function_exists('opcache_reset')) @opcache_reset();
        $state = $this->readState(); $state['last_check'] = 0; $this->writeState($state);
        return ['status' => 'restored', 'versions' => $restored];
    }

    public function summary($state = null) {
        $state = $state ?? $this->readState();
        $latest = isset($state['latest_version']) ? ltrim($state['latest_version'], 'v') : null;
        $prev = $this->latestBackup('index.php');
        return [
            'current' => BECKON_VERSION,
            'latest' => $latest,
            'update_available' => $latest && version_compare($latest, BECKON_VERSION, '>'),
            'verified' => !empty($state['assets']['index.php']['sha256']),
            'notes' => $state['notes'] ?? '',
            'published_at' => $state['published_at'] ?? null,
            'html_url' => $state['html_url'] ?? null,
            'git_checkout' => $this->isGitCheckout(),
            'writable' => is_writable($this->baseDir . '/index.php') && is_writable($this->baseDir),
            'can_rollback' => (bool) $prev,
            'previous_version' => $prev ? $this->versionOf($prev, 'index.php') : null,
            'last_check' => $state['last_check'] ?? 0,
            'error' => $state['error'] ?? null,
        ];
    }

    // --- internals ---

    private function fetchLatest() {
        $json = json_decode($this->download($this->api . '/releases/latest'), true);
        if (!is_array($json) || empty($json['tag_name'])) throw new Exception("Unexpected response from GitHub.");
        $version = ltrim($json['tag_name'], 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new Exception("Unrecognized release tag {$json['tag_name']}.");
        $assets = [];
        $sums = null;
        foreach ($json['assets'] ?? [] as $a) {
            $name = $a['name'] ?? '';
            if ($name === 'SHA256SUMS') { $sums = $a['browser_download_url'] ?? null; continue; }
            if (!in_array($name, self::FILES, true)) continue;
            $digest = $a['digest'] ?? '';
            $assets[$name] = ['url' => $a['browser_download_url'] ?? '', 'sha256' => preg_match('/^sha256:([0-9a-f]{64})$/i', $digest, $m) ? strtolower($m[1]) : null];
        }
        $missing = array_filter($assets, fn($a) => empty($a['sha256']));
        if ($missing && $sums) {
            foreach (explode("\n", $this->download($sums)) as $line) {
                if (preg_match('/^([0-9a-f]{64})\s+\*?(\S+)$/i', trim($line), $m) && isset($assets[$m[2]]) && empty($assets[$m[2]]['sha256'])) $assets[$m[2]]['sha256'] = strtolower($m[1]);
            }
        }
        return [
            'latest_version' => $version,
            'tag' => $json['tag_name'],
            'notes' => (string) ($json['body'] ?? ''),
            'published_at' => $json['published_at'] ?? null,
            'html_url' => $json['html_url'] ?? null,
            'assets' => $assets,
        ];
    }

    private function download($url, $toFile = null) {
        $headers = ['User-Agent: Beckon-Updater/' . BECKON_VERSION, 'Accept: application/vnd.github+json, */*'];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fp = $toFile ? fopen($toFile, 'wb') : null;
            if ($toFile && !$fp) throw new Exception("Could not open $toFile for writing.");
            curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_FAILONERROR => false] + ($fp ? [CURLOPT_FILE => $fp] : [CURLOPT_RETURNTRANSFER => true]));
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($fp) fclose($fp);
            if ($body === false || $code !== 200) { if ($toFile) @unlink($toFile); throw new Exception("Download failed (" . ($err ?: "HTTP $code") . "): $url"); }
            return $toFile ? true : $body;
        }
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 120, 'follow_location' => 1]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) throw new Exception("Download failed: $url");
        if ($toFile) { file_put_contents($toFile, $body); return true; }
        return $body;
    }

    private function versionOf($path, $name) {
        $head = @file_get_contents($path, false, null, 0, 4000) ?: '';
        if (preg_match("/define\('" . ($name === 'beckon-cli.php' ? 'CLI_VERSION' : 'BECKON_VERSION') . "', '([^']+)'\)/", $head, $m)) return $m[1];
        return 'unknown';
    }

    private function latestBackup($name) {
        $files = glob($this->backupDir . "/$name.*");
        $files = array_filter($files ?: [], fn($f) => substr($f, -9) !== '.replaced' && substr($f, -4) !== '.tmp');
        if (!$files) return null;
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }

    private function pruneBackups() {
        foreach (self::FILES as $name) {
            $files = glob($this->backupDir . "/$name.*") ?: [];
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            foreach (array_slice($files, self::KEEP_BACKUPS) as $old) @unlink($old);
        }
    }

    private function ensureBackupDir() { if (!is_dir($this->backupDir)) mkdir($this->backupDir, 0755, true); }

    // 1.0's updater left index.php.bak beside the app, where a web server serves
    // it as plain text. Move it under boards/.updates/ with the name the Restore
    // link understands, so it is out of the web root and still restorable.
    private function adoptLegacyBackup() {
        $bak = $this->baseDir . '/index.php.bak';
        if (!file_exists($bak) || !is_file($bak)) return;
        $this->ensureBackupDir();
        $dest = $this->backupDir . '/index.php.' . $this->versionOf($bak, 'index.php') . '.' . (filemtime($bak) ?: time());
        @rename($bak, $dest);
    }
    private function readState() { $s = @json_decode(@file_get_contents($this->stateFile), true); return is_array($s) ? $s : []; }
    private function writeState($state) { if (!is_dir(dirname($this->stateFile))) mkdir(dirname($this->stateFile), 0755, true); $tmp = $this->stateFile . '.tmp.' . uniqid(); file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); rename($tmp, $this->stateFile); }
}

// Included by beckon-cli.php (and tests) for the shared classes only: stop before running the app or emitting HTML.
if (defined('BECKON_NO_RUN')) return;

// Instantiate and run
(new App())->run();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="color-scheme" content="light dark">
    <title>Beckon</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23f2b134'/%3E%3Cpath d='M13 24h6l-1.2-9h-3.6z' fill='%231a1f2b'/%3E%3Cpath d='M12.5 13.5h7' stroke='%231a1f2b' stroke-width='2' stroke-linecap='round'/%3E%3Ccircle cx='16' cy='10.5' r='2.2' fill='%231a1f2b'/%3E%3Cpath d='M9.5 9l2.4 1.2M22.5 9l-2.4 1.2M8.2 13.2l2.6-.4M23.8 13.2l-2.6-.4' stroke='%231a1f2b' stroke-width='1.6' stroke-linecap='round'/%3E%3Cpath d='M10 25.5h12' stroke='%231a1f2b' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E">
    <script>
        (function () {
            try {
                var t = localStorage.getItem('beckon_theme');
                if (!t && localStorage.getItem('beckon_darkMode') === 'true') t = 'dark';
                if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
    <style>
/* ---------- Tokens ---------- */
:root {
    --font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    --mono: ui-monospace, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
    --emoji: "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji", sans-serif;

    --bg: #eef0f4;
    --bg-deep: #e4e7ec;
    --surface: #ffffff;
    --surface-2: #f6f7f9;
    --surface-3: #eceef2;
    --text: #151a24;
    --text-2: #4b5563;
    --muted: #7b8494;
    --line: #dfe3e9;
    --line-strong: #c9cfd8;
    --accent: #3556f5;
    --accent-2: #2440d6;
    --accent-soft: #e6eaff;
    --accent-ink: #1f38b8;
    --brand: #f2b134;
    --brand-ink: #1a1f2b;
    --ok: #16a34a;
    --ok-soft: #dcfce7;
    --warn: #d97706;
    --warn-soft: #fef3c7;
    --danger: #dc2626;
    --danger-soft: #fee2e2;
    --shadow-1: 0 1px 2px rgba(16, 24, 40, .06), 0 1px 1px rgba(16, 24, 40, .04);
    --shadow-2: 0 4px 12px rgba(16, 24, 40, .10), 0 1px 2px rgba(16, 24, 40, .06);
    --shadow-3: 0 24px 60px rgba(16, 24, 40, .22), 0 4px 12px rgba(16, 24, 40, .10);
    --backdrop: rgba(20, 24, 33, .55);
    --r-sm: 6px;
    --r: 10px;
    --r-lg: 14px;
    --topbar-h: 54px;
    --col-w: 284px;

    --c-red: #ef4444; --c-orange: #f97316; --c-yellow: #eab308; --c-lime: #84cc16; --c-green: #22c55e;
    --c-teal: #14b8a6; --c-sky: #0ea5e9; --c-blue: #3b82f6; --c-indigo: #6366f1; --c-purple: #a855f7;
    --c-pink: #ec4899; --c-slate: #64748b;
    color-scheme: light;
}
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --bg: #0f1218; --bg-deep: #0a0d12; --surface: #171b23; --surface-2: #1d222c; --surface-3: #252b36;
        --text: #e8ebf0; --text-2: #b6bcc7; --muted: #8b93a1; --line: #2a303b; --line-strong: #3a4150;
        --accent: #6b83ff; --accent-2: #8597ff; --accent-soft: #232a4a; --accent-ink: #b9c4ff;
        --ok: #4ade80; --ok-soft: #14301f; --warn: #fbbf24; --warn-soft: #3a2a08; --danger: #f87171; --danger-soft: #3b1414;
        --shadow-1: 0 1px 2px rgba(0,0,0,.4); --shadow-2: 0 4px 14px rgba(0,0,0,.45); --shadow-3: 0 24px 60px rgba(0,0,0,.6);
        --backdrop: rgba(4, 6, 10, .7);
        color-scheme: dark;
    }
}
:root[data-theme="dark"] {
    --bg: #0f1218; --bg-deep: #0a0d12; --surface: #171b23; --surface-2: #1d222c; --surface-3: #252b36;
    --text: #e8ebf0; --text-2: #b6bcc7; --muted: #8b93a1; --line: #2a303b; --line-strong: #3a4150;
    --accent: #6b83ff; --accent-2: #8597ff; --accent-soft: #232a4a; --accent-ink: #b9c4ff;
    --ok: #4ade80; --ok-soft: #14301f; --warn: #fbbf24; --warn-soft: #3a2a08; --danger: #f87171; --danger-soft: #3b1414;
    --shadow-1: 0 1px 2px rgba(0,0,0,.4); --shadow-2: 0 4px 14px rgba(0,0,0,.45); --shadow-3: 0 24px 60px rgba(0,0,0,.6);
    --backdrop: rgba(4, 6, 10, .7);
    color-scheme: dark;
}

/* ---------- Base ---------- */
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; }
body {
    margin: 0; background: var(--bg); color: var(--text); font-family: var(--font); font-size: 14px; line-height: 1.45;
    -webkit-font-smoothing: antialiased; overflow: hidden; overscroll-behavior: none;
}
button, input, textarea, select { font: inherit; color: inherit; }
button { background: none; border: 0; padding: 0; cursor: pointer; }
button:disabled { opacity: .5; cursor: not-allowed; }
a { color: var(--accent); }
::selection { background: var(--accent-soft); }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }
input, textarea, select { outline: none; }
kbd { font-family: var(--mono); font-size: 10.5px; padding: 1px 6px; border-radius: 5px; background: var(--surface-3); color: var(--muted); border: 1px solid var(--line); }
.hidden { display: none !important; }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
svg.i { width: 18px; height: 18px; flex: none; }
svg.i.sm { width: 14px; height: 14px; }
svg.i.xs { width: 12px; height: 12px; }
svg.i.lg { width: 22px; height: 22px; }
.em { font-family: var(--emoji); }

#app { height: 100%; display: flex; flex-direction: column; }
.scroll::-webkit-scrollbar { width: 10px; height: 10px; }
.scroll::-webkit-scrollbar-thumb { background: var(--line-strong); border-radius: 8px; border: 3px solid transparent; background-clip: padding-box; }
.scroll::-webkit-scrollbar-track { background: transparent; }

/* ---------- Controls ---------- */
.btn {
    display: inline-flex; align-items: center; gap: 7px; height: 32px; padding: 0 11px; border-radius: 8px;
    background: var(--surface); border: 1px solid var(--line); color: var(--text-2); font-weight: 500; font-size: 13px;
    box-shadow: var(--shadow-1); transition: background .12s, border-color .12s, color .12s, transform .06s; white-space: nowrap;
}
.btn:hover { background: var(--surface-2); color: var(--text); border-color: var(--line-strong); }
.btn:active { transform: translateY(1px); }
.btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.btn.primary:hover { background: var(--accent-2); border-color: var(--accent-2); color: #fff; }
.btn.danger { color: var(--danger); }
.btn.danger:hover { background: var(--danger-soft); border-color: var(--danger); }
.btn.ghost { background: transparent; border-color: transparent; box-shadow: none; }
.btn.ghost:hover { background: var(--surface-3); }
.btn.sm { height: 28px; padding: 0 9px; font-size: 12px; border-radius: 7px; }
.btn.lg { height: 38px; padding: 0 16px; font-size: 14px; }
.btn .count { font-size: 11px; background: var(--surface-3); color: var(--muted); border-radius: 999px; padding: 0 6px; min-width: 18px; text-align: center; line-height: 18px; }
.btn.primary .count { background: rgba(255,255,255,.22); color: #fff; }
.ibtn {
    display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px;
    color: var(--muted); transition: background .12s, color .12s;
}
.ibtn:hover { background: var(--surface-3); color: var(--text); }
.ibtn.danger:hover { background: var(--danger-soft); color: var(--danger); }
.ibtn.sm { width: 26px; height: 26px; border-radius: 6px; }
.field {
    width: 100%; height: 36px; padding: 0 11px; border-radius: 8px; background: var(--surface-2); border: 1px solid var(--line);
    color: var(--text); transition: border-color .12s, box-shadow .12s;
}
.field:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); background: var(--surface); }
.field::placeholder { color: var(--muted); }
textarea.field { height: auto; padding: 9px 11px; resize: vertical; line-height: 1.5; }
select.field { appearance: none; -webkit-appearance: none; padding-right: 30px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%237b8494' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 9px center; }
input[type="date"].field::-webkit-calendar-picker-indicator { opacity: .6; cursor: pointer; }
.label { display: block; font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
.seg { display: inline-flex; background: var(--surface-3); border-radius: 8px; padding: 2px; gap: 2px; }
.seg button { height: 26px; padding: 0 9px; border-radius: 6px; font-size: 12px; font-weight: 600; color: var(--muted); display: inline-flex; align-items: center; gap: 5px; }
.seg button.on { background: var(--surface); color: var(--text); box-shadow: var(--shadow-1); }
.switch-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.dot { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); flex: none; }
.dot.ok { background: var(--ok); box-shadow: 0 0 0 3px color-mix(in srgb, var(--ok) 20%, transparent); }
.dot.warn { background: var(--warn); }
.dot.bad { background: var(--danger); }
.pill { display: inline-flex; align-items: center; gap: 5px; height: 20px; padding: 0 8px; border-radius: 999px; font-size: 11px; font-weight: 600; background: var(--surface-3); color: var(--text-2); }
.pill.warn { background: var(--warn-soft); color: var(--warn); }
.pill.ok { background: var(--ok-soft); color: var(--ok); }
.pill.accent { background: var(--accent-soft); color: var(--accent-ink); }
.avatar {
    width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    background: var(--surface-3); color: var(--text-2); font-size: 10px; font-weight: 700; overflow: hidden; flex: none; letter-spacing: .02em;
}
.avatar img { width: 100%; height: 100%; object-fit: cover; }
.avatar.lg { width: 40px; height: 40px; font-size: 14px; }
.avatar.xl { width: 88px; height: 88px; font-size: 28px; }
.avatar.xs { width: 22px; height: 22px; font-size: 8.5px; letter-spacing: 0; }
.avatar[data-color] { color: #fff; }
.bg-red { background: var(--c-red) !important; } .bg-orange { background: var(--c-orange) !important; } .bg-yellow { background: var(--c-yellow) !important; }
.bg-lime { background: var(--c-lime) !important; } .bg-green { background: var(--c-green) !important; } .bg-teal { background: var(--c-teal) !important; }
.bg-sky { background: var(--c-sky) !important; } .bg-blue { background: var(--c-blue) !important; } .bg-indigo { background: var(--c-indigo) !important; }
.bg-purple { background: var(--c-purple) !important; } .bg-pink { background: var(--c-pink) !important; } .bg-slate { background: var(--c-slate) !important; }
.progress { height: 5px; background: var(--surface-3); border-radius: 999px; overflow: hidden; }
.progress > i { display: block; height: 100%; background: var(--accent); border-radius: 999px; transition: width .25s; }
.progress.done > i { background: var(--ok); }
.spinner { width: 16px; height: 16px; border: 2px solid var(--line-strong); border-top-color: var(--accent); border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes fade { from { opacity: 0; transform: translateY(4px) scale(.985); } to { opacity: 1; transform: none; } }
@keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .45; } }
.fade { animation: fade .16s ease-out; }
.rise { animation: rise .2s ease-out; }
.empty { text-align: center; color: var(--muted); padding: 22px 12px; font-size: 13px; }
.empty svg { width: 46px; height: 46px; opacity: .5; margin-bottom: 8px; }
.divider { height: 1px; background: var(--line); margin: 6px 0; }
.help { font-size: 11.5px; color: var(--muted); }

/* ---------- Top bar ---------- */
.topbar {
    height: var(--topbar-h); flex: none; display: flex; align-items: center; gap: 8px; padding: 0 14px;
    background: var(--surface); border-bottom: 1px solid var(--line); position: relative; z-index: 30;
}
.brand { display: flex; align-items: center; gap: 9px; cursor: pointer; padding: 4px 6px 4px 2px; border-radius: 8px; flex: none; }
.brand:hover { background: var(--surface-3); }
.brand .mark { width: 28px; height: 28px; border-radius: 7px; display: block; }
.brand span { font-weight: 800; letter-spacing: -.01em; font-size: 15px; }
.tb-sep { width: 1px; height: 22px; background: var(--line); margin: 0 4px; flex: none; }
.board-btn { display: flex; align-items: center; gap: 6px; height: 34px; padding: 0 10px; border-radius: 8px; min-width: 0; max-width: 42vw; }
.board-btn:hover { background: var(--surface-3); }
.board-btn h1 { margin: 0; font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: -.01em; }
.board-btn svg { color: var(--muted); }
.sync { display: inline-flex; align-items: center; gap: 7px; font-family: var(--mono); font-size: 10px; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); padding: 0 9px; height: 24px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--line); flex: none; }
.tb-right { margin-left: auto; display: flex; align-items: center; gap: 6px; }
.tb-mobile { display: none; }
.identity-btn { border: 2px solid var(--line); }
.identity-btn:hover { border-color: var(--accent); }

/* Popover */
.pop {
    position: absolute; top: calc(100% + 6px); left: 0; width: 300px; max-width: calc(100vw - 24px); background: var(--surface); border: 1px solid var(--line);
    border-radius: var(--r-lg); box-shadow: var(--shadow-3); z-index: 40; overflow: hidden; animation: fade .14s ease-out;
}
.pop.right { left: auto; right: 0; }
.pop-search { padding: 8px; border-bottom: 1px solid var(--line); }
.pop-list { max-height: 320px; overflow-y: auto; padding: 4px; }
.pop-head { padding: 8px 10px 4px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
.pop-item { display: flex; align-items: center; gap: 9px; width: 100%; padding: 8px 10px; border-radius: 8px; text-align: left; color: var(--text); font-size: 13px; }
.pop-item:hover, .pop-item.sel { background: var(--surface-3); }
.pop-item .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pop-item.accent { color: var(--accent-ink); font-weight: 600; }
.pop-item .sub { display: block; font-size: 11.5px; color: var(--muted); font-weight: 400; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pop-foot { border-top: 1px solid var(--line); padding: 4px; }
.pop-panel { padding: 14px; }
.click-shield { position: fixed; inset: 0; z-index: 35; }
.color-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 7px; }
.swatch { width: 100%; aspect-ratio: 1; border-radius: 8px; opacity: .8; transition: transform .1s, opacity .1s; position: relative; }
.swatch:hover { opacity: 1; transform: scale(1.06); }
.swatch.on { opacity: 1; box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text); }

/* ---------- Board ---------- */
.board-wrap { flex: 1; min-height: 0; overflow-x: auto; overflow-y: hidden; padding: 18px 18px 12px; -webkit-overflow-scrolling: touch; }
.board { display: flex; align-items: flex-start; gap: 14px; height: 100%; }
.col {
    width: var(--col-w); flex: none; max-height: 100%; display: flex; flex-direction: column; background: var(--surface-2);
    border: 1px solid var(--line); border-radius: var(--r-lg); box-shadow: var(--shadow-1); transition: opacity .15s, transform .15s;
}
.col.dragging { opacity: .35; }
.col.drop-before { box-shadow: -4px 0 0 0 var(--accent), var(--shadow-1); }
.col.drop-after { box-shadow: 4px 0 0 0 var(--accent), var(--shadow-1); }
.col-head { display: flex; align-items: center; gap: 6px; padding: 10px 8px 6px 12px; cursor: grab; }
.col-head:active { cursor: grabbing; }
.col-head input { flex: 1; min-width: 0; background: transparent; border: 0; font-weight: 700; font-size: 13.5px; padding: 4px 6px; margin-left: -6px; border-radius: 6px; cursor: text; }
.col-head input:hover { background: var(--surface-3); }
.col-head input:focus { background: var(--surface); box-shadow: 0 0 0 2px var(--accent); }
.col-count { font-size: 11px; font-weight: 600; color: var(--muted); background: var(--surface-3); border-radius: 999px; padding: 1px 7px; }
.col-body { flex: 1; min-height: 30px; overflow-y: auto; padding: 4px 8px 8px; display: flex; flex-direction: column; gap: 8px; }
.col-body > * { flex: none; }
.col-foot { padding: 4px 8px 8px; }
.add-card { width: 100%; display: flex; align-items: center; gap: 7px; height: 32px; padding: 0 10px; border-radius: 8px; color: var(--muted); font-weight: 600; font-size: 13px; }
.add-card:hover { background: var(--surface-3); color: var(--text); }
.composer textarea { width: 100%; border: 1px solid var(--accent); border-radius: 9px; background: var(--surface); padding: 9px 10px; resize: none; font-size: 13.5px; box-shadow: 0 0 0 3px var(--accent-soft); line-height: 1.4; }
.composer .row { display: flex; gap: 6px; margin-top: 6px; align-items: center; }
.add-list { width: var(--col-w); flex: none; height: 44px; border: 1.5px dashed var(--line-strong); border-radius: var(--r-lg); color: var(--muted); font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 7px; }
.add-list:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
.drop-line { height: 3px; border-radius: 3px; background: var(--accent); margin: -4px 2px; flex: none; animation: pulse 1s infinite; }

.card {
    background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); box-shadow: var(--shadow-1); cursor: pointer;
    position: relative; overflow: hidden; transition: box-shadow .12s, border-color .12s, transform .12s;
}
.card:hover, .card.hover { border-color: var(--line-strong); box-shadow: var(--shadow-2); }
.card.hover { box-shadow: 0 0 0 2px var(--accent), var(--shadow-2); border-color: transparent; }
.card.dragging { opacity: .4; }
.card.pressing { transform: scale(.98); }
.card.lifted, .col.lifted { transform: scale(1.03); box-shadow: var(--shadow-3); transition: transform .12s, box-shadow .12s; }
.touch-ghost { position: fixed; z-index: 500; pointer-events: none; transform: rotate(2deg); box-shadow: var(--shadow-3); opacity: .96; margin: 0; }
.board-wrap.touch-dragging { scroll-snap-type: none !important; }
@media (hover: none) { .card, .col-head { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; } }
.card .cover { height: 120px; background: var(--surface-3); }
.card .cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.card-in { padding: 10px 11px 9px; }
.labels { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 7px; }
.lbl { display: inline-flex; align-items: center; height: 18px; padding: 0 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; color: #fff; letter-spacing: .01em; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.card-title { font-size: 13.5px; line-height: 1.4; color: var(--text); word-wrap: break-word; }
.card-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 8px; min-height: 18px; }
.meta-items { display: flex; align-items: center; gap: 9px; color: var(--muted); font-size: 11px; font-weight: 600; flex-wrap: wrap; }
.meta-items span { display: inline-flex; align-items: center; gap: 3px; }
.meta-items .done { color: var(--ok); }
.chip-date { padding: 1px 6px; border-radius: 5px; background: var(--surface-3); color: var(--text-2); }
.chip-date.overdue { background: var(--danger-soft); color: var(--danger); }
.chip-date.soon { background: var(--warn-soft); color: var(--warn); }
.assignees { display: flex; }
.assignees .avatar { margin-left: -3px; box-shadow: 0 0 0 2px var(--surface); }
.assignees .avatar:first-child { margin-left: 0; }
.card-more { position: absolute; top: 6px; right: 6px; display: none; }
.mini-progress { width: 36px; height: 4px; background: var(--surface-3); border-radius: 999px; overflow: hidden; display: inline-block; vertical-align: middle; }
.mini-progress i { display: block; height: 100%; background: var(--accent); }
.done .mini-progress i { background: var(--ok); }
.board-empty { width: 100%; display: flex; align-items: center; justify-content: center; height: 100%; color: var(--muted); flex-direction: column; gap: 10px; }
.board-empty svg { width: 120px; height: 80px; }

/* ---------- Layers: modals, dialogs ---------- */
.layer { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; padding: 20px; background: var(--backdrop); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); animation: fade .15s ease-out; }
.layer.top { align-items: flex-start; padding-top: 12vh; }
.win { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-lg); box-shadow: var(--shadow-3); width: 100%; max-width: 440px; display: flex; flex-direction: column; max-height: calc(100vh - 40px); overflow: hidden; animation: rise .18s ease-out; }
.win.md { max-width: 620px; } .win.lg { max-width: 760px; }
.win-head { display: flex; align-items: center; gap: 10px; padding: 14px 14px 12px 18px; border-bottom: 1px solid var(--line); }
.win-head h3 { margin: 0; font-size: 15px; font-weight: 700; flex: 1; letter-spacing: -.01em; }
.win-body { padding: 18px; overflow-y: auto; }
.win-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px 16px; }
.form-row { margin-bottom: 14px; }
.grid-2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.grid-2 .field { min-width: 0; width: 100%; }
.grid-2 input[type="date"].field { padding-left: 8px; padding-right: 6px; font-size: 13px; }
.danger-zone { border: 1px solid var(--danger-soft); background: color-mix(in srgb, var(--danger-soft) 40%, transparent); border-radius: var(--r); padding: 12px 14px; }
.danger-zone h4 { margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--danger); display: flex; align-items: center; gap: 6px; }
.danger-zone p { margin: 0 0 10px; font-size: 12.5px; color: var(--text-2); }
.stat-box { background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--r); padding: 14px; text-align: center; }
.stat-box b { display: block; font-size: 24px; font-weight: 800; letter-spacing: -.02em; }
.stat-box span { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 700; }
.list-rows { display: flex; flex-direction: column; gap: 6px; }
.row-item { display: flex; align-items: center; gap: 10px; padding: 9px 11px; border: 1px solid var(--line); border-radius: var(--r); background: var(--surface-2); }
.row-item .grow { flex: 1; min-width: 0; }
.row-item b { display: block; font-size: 13px; }
.row-item small { display: block; color: var(--muted); font-size: 11.5px; font-family: var(--mono); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dash-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px; border: 1.5px dashed var(--line-strong); border-radius: var(--r); color: var(--muted); font-weight: 600; }
.dash-btn:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
.dropzone { position: relative; }
.dropzone.over::after { content: "Drop to upload"; position: absolute; inset: 6px; border: 2px dashed var(--accent); border-radius: var(--r); background: color-mix(in srgb, var(--accent-soft) 70%, transparent); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--accent-ink); pointer-events: none; }
.cover-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
.cover-tile { aspect-ratio: 1; border-radius: var(--r); overflow: hidden; border: 2px solid transparent; background: var(--surface-3); position: relative; cursor: pointer; }
.cover-tile:hover { border-color: var(--accent); }
.cover-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cover-tile .tick { position: absolute; top: 6px; right: 6px; width: 22px; height: 22px; border-radius: 50%; background: var(--ok); color: #fff; display: flex; align-items: center; justify-content: center; }

/* Dialog (confirm / prompt) */
.dialog { max-width: 400px; }
.dialog .win-body { padding: 22px 22px 6px; }
.dialog p { margin: 0 0 6px; font-size: 14px; }
.dialog .icon-wrap { width: 40px; height: 40px; border-radius: 12px; background: var(--warn-soft); color: var(--warn); display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.dialog.danger .icon-wrap { background: var(--danger-soft); color: var(--danger); }
.dialog.info .icon-wrap { background: var(--accent-soft); color: var(--accent-ink); }

/* Context menu */
.ctx { position: fixed; z-index: 200; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); box-shadow: var(--shadow-3); padding: 4px; width: 200px; animation: fade .12s ease-out; }
.ctx button { display: flex; align-items: center; gap: 9px; width: 100%; padding: 8px 10px; border-radius: 7px; text-align: left; font-size: 13px; color: var(--text); }
.ctx button svg { color: var(--muted); }
.ctx button:hover { background: var(--surface-3); }
.ctx button.danger { color: var(--danger); } .ctx button.danger svg { color: var(--danger); }
.ctx button.danger:hover { background: var(--danger-soft); }

/* Toasts */
.toasts { position: fixed; right: 18px; bottom: 18px; z-index: 300; display: flex; flex-direction: column; gap: 8px; }
.toast { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 10px; background: var(--text); color: var(--bg); font-weight: 600; font-size: 13px; box-shadow: var(--shadow-3); animation: rise .2s ease-out; cursor: pointer; max-width: 360px; }
.toast.ok svg { color: var(--ok); } .toast.err svg { color: var(--danger); } .toast.info svg { color: var(--accent-2); }

/* ---------- Search palette ---------- */
.palette { max-width: 640px; }
.palette-in { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line); }
.palette-in input { flex: 1; background: transparent; border: 0; font-size: 17px; min-width: 0; }
.palette-filters { display: flex; gap: 6px; padding: 8px 12px; border-bottom: 1px solid var(--line); overflow-x: auto; }
.chip { height: 24px; padding: 0 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--surface-2); border: 1px solid var(--line); color: var(--text-2); white-space: nowrap; flex: none; }
.chip.on { background: var(--accent-soft); border-color: transparent; color: var(--accent-ink); }
.palette-list { max-height: 56vh; overflow-y: auto; padding: 6px; }
.result { display: flex; gap: 12px; align-items: flex-start; padding: 10px 12px; border-radius: 9px; cursor: pointer; }
.result:hover, .result.sel { background: var(--surface-3); }
.result .grow { flex: 1; min-width: 0; }
.result b { display: block; font-size: 13.5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.result .snip { font-size: 12.5px; color: var(--muted); margin-top: 2px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.result mark { background: var(--warn-soft); color: var(--text); border-radius: 3px; padding: 0 2px; }
.result .side { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex: none; }
.result .dots { display: flex; gap: 3px; } .result .dots i { width: 8px; height: 8px; border-radius: 50%; display: block; }
.palette-foot { display: flex; justify-content: space-between; align-items: center; padding: 8px 14px; border-top: 1px solid var(--line); font-size: 11.5px; color: var(--muted); }
.palette-foot .keys { display: flex; gap: 12px; }
.palette-foot button { color: var(--muted); font-size: 11.5px; } .palette-foot button:hover { color: var(--accent); }

/* ---------- Boards overview ---------- */
.overview { position: fixed; inset: 0; z-index: 90; background: var(--bg); display: flex; flex-direction: column; animation: fade .18s ease-out; }
.overview-head { display: flex; justify-content: flex-end; padding: 16px 20px 0; }
.overview-body { flex: 1; overflow-y: auto; padding: 10px 28px 40px; }
.overview-inner { max-width: 1080px; margin: 0 auto; }
.overview h1 { text-align: center; font-size: 30px; letter-spacing: -.03em; margin: 8px 0 4px; }
.overview .lead { text-align: center; color: var(--muted); margin: 0 0 30px; }
.ov-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
.ov-card { aspect-ratio: 16/10; border-radius: var(--r-lg); padding: 18px; background: var(--surface); border: 1px solid var(--line); box-shadow: var(--shadow-1); display: flex; flex-direction: column; justify-content: space-between; cursor: pointer; transition: transform .12s, box-shadow .12s, border-color .12s; text-align: left; position: relative; }
.ov-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-2); border-color: var(--line-strong); }
.ov-card.on { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
.ov-card b { font-size: 17px; letter-spacing: -.01em; }
.ov-card small { font-family: var(--mono); color: var(--muted); font-size: 11px; }
.ov-card.new { border-style: dashed; border-width: 1.5px; border-color: var(--line-strong); background: transparent; align-items: center; justify-content: center; color: var(--muted); font-weight: 700; gap: 8px; box-shadow: none; }
.ov-card.new:hover { color: var(--accent); border-color: var(--accent); background: var(--accent-soft); }
.ov-card .ov-art { position: absolute; right: 14px; top: 14px; opacity: .14; width: 28px; height: 28px; }
.ov-card > b { padding-right: 40px; }
.overview-foot { display: flex; align-items: center; justify-content: center; gap: 14px; padding: 16px; font-family: var(--mono); font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: var(--muted); border-top: 1px solid var(--line); }
.overview-foot a { color: var(--muted); text-decoration: none; display: inline-flex; align-items: center; gap: 8px; } .overview-foot a:hover { color: var(--text); }
.overview-foot .update { color: var(--warn); background: var(--warn-soft); padding: 4px 10px; border-radius: 999px; display: inline-flex; align-items: center; gap: 6px; }

/* ---------- Card window ---------- */
.cw { width: min(1260px, 100%); height: min(92vh, 100%); max-width: none; }
.cw-head { display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px 12px 22px; border-bottom: 1px solid var(--line); background: var(--surface); }
.cw-title { flex: 1; min-width: 0; }
.cw-title input { width: 100%; background: transparent; border: 0; font-size: 22px; font-weight: 800; letter-spacing: -.02em; padding: 2px 0; line-height: 1.25; }
.cw-title input::placeholder { color: var(--muted); }
.cw-sub { display: flex; align-items: center; gap: 8px; margin-top: 3px; font-size: 12.5px; color: var(--muted); flex-wrap: wrap; }
.cw-sub b { color: var(--text-2); font-weight: 600; }
.cw-actions { display: flex; align-items: center; gap: 4px; flex: none; }
.cw-body { flex: 1; min-height: 0; display: flex; }
.cw-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.cw-panes { flex: 1; min-height: 0; display: flex; position: relative; }
.pane { display: flex; flex-direction: column; min-width: 0; overflow: hidden; }
.pane-head { height: 34px; flex: none; display: flex; align-items: center; gap: 10px; padding: 0 14px; font-size: 10.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); background: var(--surface-2); border-bottom: 1px solid var(--line); }
.pane-head .stat { font-family: var(--mono); font-weight: 500; letter-spacing: 0; text-transform: none; font-size: 11px; padding-left: 10px; border-left: 1px solid var(--line-strong); }
.pane-head .spacer { flex: 1; }
.pane-head label { cursor: pointer; display: inline-flex; align-items: center; gap: 5px; letter-spacing: .02em; text-transform: none; font-size: 11.5px; }
.pane-head label:hover { color: var(--accent); }
.pane-head .badge { color: var(--accent-ink); background: var(--accent-soft); padding: 2px 7px; border-radius: 999px; letter-spacing: .04em; }
.pane-editor { border-right: 1px solid var(--line); }
.pane-editor textarea { flex: 1; width: 100%; border: 0; resize: none; padding: 16px 18px; font-family: var(--mono); font-size: 13px; line-height: 1.6; background: var(--surface); color: var(--text); tab-size: 2; }
.pane-editor textarea.over { background: var(--accent-soft); }
.pane-preview { background: var(--surface-2); }
.pane-preview .md { flex: 1; overflow-y: auto; padding: 18px 22px; }
.pane-divider { width: 6px; margin: 0 -3px; cursor: col-resize; z-index: 2; flex: none; position: relative; }
.pane-divider::after { content: ""; position: absolute; inset: 0 2px; border-radius: 2px; background: transparent; transition: background .12s; }
.pane-divider:hover::after, .pane-divider.on::after { background: var(--accent); }
.cw-activity { flex: none; border-top: 1px solid var(--line); display: flex; flex-direction: column; background: var(--surface); height: 40px; transition: height .2s ease; overflow: hidden; }
.cw-activity.open { height: 46%; }
.cw-activity.max { flex: 1; height: auto; }
.act-head { height: 40px; flex: none; display: flex; align-items: center; padding: 0 8px 0 14px; gap: 2px; background: var(--surface-2); border-bottom: 1px solid var(--line); cursor: pointer; }
.act-tab { height: 40px; padding: 0 10px; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); border-bottom: 2px solid transparent; display: inline-flex; align-items: center; gap: 6px; }
.act-tab .n { font-family: var(--mono); font-weight: 500; color: var(--muted); background: var(--surface-3); padding: 0 6px; border-radius: 999px; font-size: 10px; letter-spacing: 0; }
.act-tab.on { color: var(--accent-ink); border-bottom-color: var(--accent); }
.act-head .spacer { flex: 1; }
.act-body { flex: 1; min-height: 0; overflow-y: auto; padding: 14px 16px; }
.comment { display: flex; gap: 10px; margin-bottom: 14px; }
.comment .grow { flex: 1; min-width: 0; }
.comment-box { background: var(--surface-2); border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; }
.comment-top { display: flex; align-items: baseline; gap: 8px; margin-bottom: 4px; }
.comment-top b { font-size: 12.5px; }
.comment-top time { font-size: 11px; color: var(--muted); font-family: var(--mono); }
.comment-top .tools { margin-left: auto; display: flex; gap: 2px; opacity: 0; transition: opacity .12s; }
.comment:hover .comment-top .tools { opacity: 1; }
.comment .md { font-size: 13.5px; } .comment .md > :last-child { margin-bottom: 0; }
.reactions { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; align-items: center; position: relative; }
.react { display: inline-flex; align-items: center; gap: 4px; height: 24px; padding: 0 8px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); font-size: 12px; }
.react .n { font-size: 10.5px; font-weight: 700; color: var(--muted); }
.react.mine { background: var(--accent-soft); border-color: transparent; color: var(--accent-ink); } .react.mine .n { color: var(--accent-ink); }
.react.add { color: var(--muted); }
.composer-row { display: flex; gap: 8px; padding-top: 6px; position: sticky; bottom: -14px; background: var(--surface); margin: 0 -2px -14px; padding-bottom: 14px; }
.log-row { display: flex; gap: 12px; padding: 6px 0; border-bottom: 1px solid var(--line); font-size: 12.5px; color: var(--text-2); }
.log-row time { font-family: var(--mono); color: var(--muted); flex: none; width: 90px; font-size: 11px; padding-top: 2px; }
.rev-box { background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--r); padding: 14px 16px; }
.rev-box .ends { display: flex; justify-content: space-between; font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
.rev-box .ends .cur { color: var(--ok); } .rev-box .ends .prev { color: var(--accent-ink); }
.rev-box input[type=range] { width: 100%; accent-color: var(--accent); direction: rtl; }
.rev-box .bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; min-height: 32px; font-size: 12.5px; }
.rev-box .bottom small { color: var(--muted); display: block; }

/* Sidebar */
.cw-side { width: 290px; flex: none; border-left: 1px solid var(--line); background: var(--surface-2); overflow-y: auto; display: flex; flex-direction: column; }
.side-mobile-head { display: none; }
.side-in { padding: 16px; display: flex; flex-direction: column; gap: 20px; }
.side-sec h4 { margin: 0 0 8px; font-size: 10.5px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); display: flex; align-items: center; justify-content: space-between; }
.side-meta { font-family: var(--mono); font-size: 11px; color: var(--muted); line-height: 1.7; }
.side-meta b { color: var(--text-2); font-weight: 600; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.uchip { display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 8px 0 3px; border-radius: 999px; background: var(--surface); border: 1px solid var(--line); font-size: 12px; font-weight: 600; }
.uchip button { color: var(--muted); display: inline-flex; } .uchip button:hover { color: var(--danger); }
.label-grid { display: flex; flex-wrap: wrap; gap: 6px; }
.lbl-toggle { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 9px 0 6px; border-radius: 999px; background: var(--surface); border: 1px solid var(--line); font-size: 12px; font-weight: 600; color: var(--text-2); }
.lbl-toggle i { width: 10px; height: 10px; border-radius: 50%; display: block; }
.lbl-toggle:hover { border-color: var(--line-strong); }
.lbl-toggle.on { color: #fff; border-color: transparent; }
.checklist { margin-bottom: 14px; }
.checklist .cl-head { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.checklist .cl-head b { font-size: 12.5px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.checklist .cl-head small { font-family: var(--mono); font-size: 10.5px; color: var(--muted); }
.checklist .cl-head .ibtn { opacity: 0; } .checklist:hover .cl-head .ibtn { opacity: 1; }
.cl-item { display: flex; align-items: flex-start; gap: 8px; padding: 5px 6px; margin: 0 -6px; border-radius: 6px; font-size: 12.5px; cursor: pointer; }
.cl-item:hover { background: var(--surface-3); }
.cl-item .box { width: 15px; height: 15px; border-radius: 4px; border: 1.5px solid var(--line-strong); flex: none; margin-top: 2px; display: flex; align-items: center; justify-content: center; color: #fff; background: var(--surface); }
.cl-item.done .box { background: var(--accent); border-color: var(--accent); }
.cl-item.done span { text-decoration: line-through; color: var(--muted); }
.cl-item span { flex: 1; word-break: break-word; }
.cl-item .ibtn { opacity: 0; } .cl-item:hover .ibtn { opacity: 1; }
.cl-add { width: 100%; background: transparent; border: 0; border-bottom: 1px solid transparent; padding: 5px 0; font-size: 12.5px; margin-left: 23px; width: calc(100% - 23px); }
.cl-add:hover { border-bottom-color: var(--line-strong); } .cl-add:focus { border-bottom-color: var(--accent); }
.side-actions { display: flex; flex-direction: column; gap: 6px; }
.side-actions .btn { justify-content: flex-start; width: 100%; height: 34px; }
.side-actions .btn.wp { color: var(--accent-ink); background: var(--accent-soft); border-color: transparent; }

/* Markdown */
.md { line-height: 1.65; font-size: 14px; color: var(--text); cursor: text; word-wrap: break-word; }
.md > :first-child { margin-top: 0; }
.md p, .md ul, .md ol, .md blockquote, .md pre, .md table { margin: 0 0 1em; }
.md h1, .md h2, .md h3, .md h4, .md h5, .md h6 { margin: 1.4em 0 .5em; line-height: 1.25; letter-spacing: -.01em; }
.md h1 { font-size: 1.85em; font-weight: 800; border-bottom: 1px solid var(--line); padding-bottom: .25em; }
.md h2 { font-size: 1.45em; font-weight: 700; border-bottom: 1px solid var(--line); padding-bottom: .2em; }
.md h3 { font-size: 1.2em; font-weight: 700; } .md h4 { font-size: 1.05em; font-weight: 700; } .md h5, .md h6 { font-size: 1em; font-weight: 700; }
.md ul, .md ol { padding-left: 1.6em; } .md ul { list-style: disc; } .md ol { list-style: decimal; }
.md li { margin: .15em 0; } .md li > ul, .md li > ol { margin: .2em 0; }
.md li.task { list-style: none; margin-left: -1.4em; }
.md li.task input { margin: 0 .55em 0 0; cursor: pointer; accent-color: var(--accent); vertical-align: -1px; }
.md li.task.done > span { color: var(--muted); text-decoration: line-through; }
.md a { color: var(--accent); text-decoration: underline; text-underline-offset: 2px; }
.md img { max-width: 100%; border-radius: 8px; margin: .4em 0; display: block; }
.md pre { background: var(--surface-3); padding: 1em; border-radius: 8px; overflow-x: auto; font-size: .9em; }
.md code { font-family: var(--mono); background: var(--surface-3); padding: .15em .4em; border-radius: 5px; font-size: .88em; color: var(--accent-ink); }
.md pre code { padding: 0; background: transparent; color: inherit; }
.md blockquote { border-left: 3px solid var(--line-strong); padding: .2em 0 .2em 1em; color: var(--muted); }
.md blockquote > :last-child { margin-bottom: 0; }
.md hr { border: 0; border-top: 1px solid var(--line); margin: 1.5em 0; }
.md table { border-collapse: collapse; width: 100%; font-size: .93em; }
.md th, .md td { border: 1px solid var(--line); padding: 6px 10px; text-align: left; } .md th { background: var(--surface-3); font-weight: 700; }
.md del { color: var(--muted); }
.md .emoji { font-family: var(--emoji); }

/* Combobox (replaces native selects) */
.combo { position: relative; }
.combo .field { padding-right: 32px; cursor: text; }
.combo .combo-caret { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; color: var(--muted); border-radius: 5px; pointer-events: none; }
.combo.open .combo-caret { transform: translateY(-50%) rotate(180deg); }
.combo-list { position: fixed; z-index: 400; max-height: 260px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; box-shadow: var(--shadow-3); padding: 4px; animation: fade .12s ease-out; }
.combo-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 10px; border-radius: 7px; text-align: left; font-size: 13px; color: var(--text); }
.combo-item .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.combo-item small { color: var(--muted); font-size: 11.5px; }
.combo-item.sel, .combo-item:hover { background: var(--surface-3); }
.combo-item.cur { color: var(--accent-ink); font-weight: 600; }
.combo-item.cur svg { color: var(--accent); }
.combo-empty { padding: 8px 10px; font-size: 12.5px; color: var(--muted); }

/* Emoji autocomplete + picker */
.ac { position: fixed; z-index: 400; width: 240px; max-height: 220px; overflow-y: auto; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; box-shadow: var(--shadow-3); padding: 4px; }
.ac button { display: flex; align-items: center; gap: 9px; width: 100%; padding: 6px 9px; border-radius: 6px; text-align: left; font-size: 13px; }
.ac button .em { font-size: 17px; width: 22px; text-align: center; }
.ac button.sel, .ac button:hover { background: var(--surface-3); }
.ac button small { color: var(--muted); font-family: var(--mono); font-size: 11px; }
.picker { position: absolute; z-index: 60; width: 270px; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow-3); padding: 8px; animation: fade .12s ease-out; }
.picker input { margin-bottom: 6px; height: 32px; }
.picker-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; max-height: 180px; overflow-y: auto; }
.picker-grid button { font-size: 19px; height: 30px; border-radius: 6px; font-family: var(--emoji); }
.picker-grid button:hover { background: var(--surface-3); }

/* Presentation */
.present { position: fixed; inset: 0; z-index: 250; background: var(--surface); display: flex; flex-direction: column; animation: fade .2s ease-out; }
.present-tools { position: absolute; top: 18px; right: 22px; display: flex; gap: 8px; z-index: 2; opacity: 0; transition: opacity .3s; }
.present:hover .present-tools { opacity: 1; }
.present-tools button { width: 44px; height: 44px; border-radius: 50%; background: var(--surface-3); color: var(--text-2); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-2); }
.present-tools button:hover { background: var(--accent); color: #fff; }
.present-scroll { flex: 1; overflow-y: auto; padding: 72px 40px; scroll-behavior: smooth; }
.present-in { max-width: 920px; margin: 0 auto; }
.present h1.pt { font-size: 52px; letter-spacing: -.03em; line-height: 1.1; margin: 0 0 18px; padding-bottom: 20px; border-bottom: 4px solid var(--brand); }
.present .pm { display: flex; gap: 12px; align-items: center; font-family: var(--mono); color: var(--muted); font-size: 15px; margin-bottom: 56px; }
.present .pm span { background: var(--surface-3); padding: 4px 12px; border-radius: 6px; }
.present .md { font-size: 22px; line-height: 1.8; }
.present .md h1 { font-size: 2.3em; } .present .md h2 { font-size: 1.8em; } .present .md h3 { font-size: 1.4em; }
.present .md img { margin: 1.5em auto; max-height: 78vh; }
.present .md li.task input { transform: scale(1.4); margin-right: .8em; }
.present .end { text-align: center; color: var(--line-strong); font-size: 34px; padding: 60px 0 20px; letter-spacing: .4em; }

/* WordPress publish progress */
.pub-progress { background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--r); padding: 12px; margin-bottom: 14px; }
.pub-progress .txt { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--muted); font-weight: 600; margin-bottom: 8px; font-family: var(--mono); }

/* Users */
.users-body { display: flex; min-height: 380px; }
.users-list { flex: 1; padding: 14px; overflow-y: auto; }
.users-edit { flex: 1; padding: 22px; }
.avatar-pick { position: relative; width: 88px; height: 88px; margin: 0 auto 18px; cursor: pointer; border-radius: 50%; }
.avatar-pick .veil { position: absolute; inset: 0; border-radius: 50%; background: rgba(0,0,0,.5); color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .12s; }
.avatar-pick:hover .veil { opacity: 1; }

/* Import */
.curl-box { font-family: var(--mono); font-size: 10.5px; height: 84px; }

/* ---------- Responsive ---------- */
@media (max-width: 860px) {
    .cw-side { position: absolute; right: 0; top: 0; bottom: 0; width: min(100%, 340px); z-index: 5; box-shadow: var(--shadow-3); }
    .side-mobile-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 8px 12px 16px; border-bottom: 1px solid var(--line); font-weight: 700; background: var(--surface); }
    .cw-body { position: relative; }
    .side-shield { position: absolute; inset: 0; background: var(--backdrop); z-index: 4; }
}
@media (max-width: 768px) {
    .topbar { padding: 0 10px; gap: 6px; }
    .brand span { display: none; }
    .tb-desktop { display: none !important; }
    .tb-mobile { display: flex; align-items: center; gap: 4px; margin-left: auto; }
    .sync { display: none; }
    .board-btn { max-width: 48vw; }
    .board-wrap { padding: 12px 12px 8px; scroll-snap-type: x mandatory; }
    .col { width: 86vw; scroll-snap-align: center; }
    .add-list { width: 86vw; scroll-snap-align: center; }
    .card-more { display: block; }
    .card-title { padding-right: 26px; }
    .layer { padding: 0; }
    .layer.top { padding-top: 0; }
    .win { max-height: 100vh; border-radius: 0; border: 0; max-width: none; height: 100%; }
    .win.dialog, .win.ctxwin { height: auto; border-radius: var(--r-lg); margin: 16px; max-height: calc(100vh - 32px); }
    .cw { height: 100%; }
    .cw-head { padding: 12px 10px 10px 16px; }
    .cw-title input { font-size: 18px; }
    .seg button span { display: none; }
    .cw-actions { gap: 2px; }
    .pane-divider { display: none; }
    .cw-activity.open { height: 55%; }
    .present-scroll { padding: 60px 20px; }
    .present h1.pt { font-size: 32px; }
    .present .md { font-size: 17px; }
    .mobile-menu { position: absolute; top: 100%; left: 0; right: 0; background: var(--surface); border-bottom: 1px solid var(--line); box-shadow: var(--shadow-3); padding: 8px; z-index: 40; animation: fade .15s ease-out; }
    .mobile-menu button, .mobile-menu label { display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px; border-radius: 9px; font-size: 14px; font-weight: 500; text-align: left; cursor: pointer; }
    .mobile-menu button:hover, .mobile-menu label:hover { background: var(--surface-3); }
    .mobile-menu svg { color: var(--muted); }
    .mobile-menu .count { margin-left: auto; }
    .toasts { left: 12px; right: 12px; bottom: 12px; }
    .toast { max-width: none; }
    .users-body { flex-direction: column; }
    .overview-body { padding: 10px 16px 30px; }
    .ov-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
    .ctx { left: 50% !important; top: 50% !important; transform: translate(-50%, -50%); width: 240px; }
    .ctx button { padding: 11px 12px; font-size: 14px; }
    .md { font-size: 15px; }
}
@media (hover: none) {
    .comment-top .tools, .checklist .cl-head .ibtn, .cl-item .ibtn { opacity: 1; }
}

    </style>
</head>
<body>
    <div id="app">
        <header class="topbar" id="topbar"></header>
        <main class="board-wrap" id="board-wrap"><div class="board" id="board"></div></main>
    </div>
    <div id="layers"></div>
    <div id="toasts" class="toasts"></div>
    <script>
'use strict';
/* ==========================================================================
   Beckon UI - no frameworks, no build step, no network requests.
   Sections: helpers · icons · emoji · markdown · dates · layers/dialogs ·
   toasts · API · state · topbar · board · drag and drop · card window ·
   sidebar · activity · modals · search · users · WordPress · import ·
   presentation · keyboard · boot
   ========================================================================== */

const VERSION = '<?php echo BECKON_VERSION; ?>';
const LABEL_COLORS = ['red', 'orange', 'yellow', 'lime', 'green', 'teal', 'sky', 'blue', 'indigo', 'purple', 'pink', 'slate'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/* ---------- Helpers ---------- */
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const lsGet = (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } };
const lsSet = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };
const lsRaw = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
const clamp = (n, a, b) => Math.max(a, Math.min(b, n));
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const isMobile = () => window.innerWidth < 768;
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
const uid = () => `${todayISO()}_${uuid()}`;
const initialsOf = (name) => { const p = (name || '').trim().split(/\s+/).filter(Boolean); if (!p.length) return '?'; return p.length > 1 ? (p[0][0] + p[p.length - 1][0]).toUpperCase() : p[0].slice(0, 2).toUpperCase(); };
const colorOf = (l) => (l && (l.color || l)) || 'slate';
const nameOf = (l) => (l && l.name) || (typeof l === 'string' ? l.charAt(0).toUpperCase() + l.slice(1) : '');
const stop = (e) => { e.preventDefault(); e.stopPropagation(); };
function on(root, evt, sel, fn) { root.addEventListener(evt, (e) => { const t = e.target.closest(sel); if (t && root.contains(t)) fn(e, t); }); }
function setTitle() { document.title = S.board && S.board.title && S.boardId ? `${S.board.title} · Beckon` : 'Beckon'; }

/* ---------- Icons (hand drawn, 24 grid, stroke) ---------- */
const ICONS = {
    close: 'M6 6l12 12M18 6L6 18',
    'chev-down': 'M6 9.5l6 6 6-6',
    'chev-right': 'M9.5 6l6 6-6 6',
    'chev-up': 'M6 14.5l6-6 6 6',
    plus: 'M12 5v14M5 12h14',
    check: 'M5 12.5l4.5 4.5L19 7',
    trash: 'M4 7h16M9.5 7V4.5h5V7M6.5 7l.8 12.2A1.5 1.5 0 008.8 20.5h6.4a1.5 1.5 0 001.5-1.3L17.5 7M10 11v6M14 11v6',
    pencil: 'M4 20h4l10.5-10.5a2.1 2.1 0 00-3-3L5 17v3zM13.5 6.5l3 3',
    warning: 'M12 4l9 16H3l9-16zM12 10v4M12 17.5v.5',
    upload: 'M12 16V5M7.5 9.5L12 5l4.5 4.5M4 16.5v2A1.5 1.5 0 005.5 20h13a1.5 1.5 0 001.5-1.5v-2',
    download: 'M12 4v11M7.5 10.5L12 15l4.5-4.5M4 16.5v2A1.5 1.5 0 005.5 20h13a1.5 1.5 0 001.5-1.5v-2',
    archive: 'M4 5.5h16v4H4zM5.5 9.5v9A1.5 1.5 0 007 20h10a1.5 1.5 0 001.5-1.5v-9M10 13.5h4',
    duplicate: 'M8 8V5.5A1.5 1.5 0 019.5 4h9A1.5 1.5 0 0120 5.5v9a1.5 1.5 0 01-1.5 1.5H16M4 9.5A1.5 1.5 0 015.5 8h9A1.5 1.5 0 0116 9.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 014 18.5v-9z',
    move: 'M4 12h16M14 6l6 6-6 6',
    undo: 'M9 14L4 9.5 9 5M4.5 9.5H15a5 5 0 010 10h-3',
    users: 'M15.5 19.5v-1.7a3.3 3.3 0 00-3.3-3.3H6.3A3.3 3.3 0 003 17.8v1.7M12.5 7.8a3.3 3.3 0 11-6.6 0 3.3 3.3 0 016.6 0zM21 19.5v-1.7a3.3 3.3 0 00-2.5-3.2M15.5 4.6a3.3 3.3 0 010 6.4',
    user: 'M19 20v-1.8A3.7 3.7 0 0015.3 14.5H8.7A3.7 3.7 0 005 18.2V20M16 7.5a4 4 0 11-8 0 4 4 0 018 0z',
    text: 'M4 6.5h16M4 12h16M4 17.5h9',
    paperclip: 'M20 11.5l-8 8a5 5 0 01-7-7l8.5-8.5a3.2 3.2 0 014.5 4.5L9.5 17a1.4 1.4 0 01-2-2l7.5-7.5',
    chat: 'M20 12.5c0 3.6-3.6 6.5-8 6.5-1.3 0-2.6-.3-3.7-.7L4 20l1.1-3.3C4.4 15.5 4 14 4 12.5 4 8.9 7.6 6 12 6s8 2.9 8 6.5z',
    'check-circle': 'M12 21a9 9 0 100-18 9 9 0 000 18zM8.5 12.3l2.3 2.3 4.7-4.8',
    clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7.5V12l3 2',
    calendar: 'M5 7.5A1.5 1.5 0 016.5 6h11A1.5 1.5 0 0119 7.5v11a1.5 1.5 0 01-1.5 1.5h-11A1.5 1.5 0 015 18.5v-11zM5 11h14M8.5 4v4M15.5 4v4',
    image: 'M4 6.5A1.5 1.5 0 015.5 5h13A1.5 1.5 0 0120 6.5v11a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-11zM4.5 16l4.5-4.5 3.5 3.5 2.5-2.5 4.5 4.5M15.5 9.5h.5',
    sun: 'M12 16.5a4.5 4.5 0 100-9 4.5 4.5 0 000 9zM12 3v1.8M12 19.2V21M3 12h1.8M19.2 12H21M5.6 5.6l1.3 1.3M17.1 17.1l1.3 1.3M5.6 18.4l1.3-1.3M17.1 6.9l1.3-1.3',
    moon: 'M20 14.2A8 8 0 019.8 4a8 8 0 1010.2 10.2z',
    smile: 'M12 21a9 9 0 100-18 9 9 0 000 18zM8.5 14.2a4.5 4.5 0 007 0M9 9.8h.01M15 9.8h.01',
    sidebar: 'M4 6.5A1.5 1.5 0 015.5 5h13A1.5 1.5 0 0120 6.5v11a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-11zM15 5v14',
    eye: 'M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6zM14.8 12a2.8 2.8 0 11-5.6 0 2.8 2.8 0 015.6 0z',
    code: 'M8.5 8L4 12l4.5 4M15.5 8l4.5 4-4.5 4M13.5 5.5l-3 13',
    columns: 'M4 6.5A1.5 1.5 0 015.5 5h13A1.5 1.5 0 0120 6.5v11a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-11zM12 5v14',
    minimize: 'M5 14.5l7-7 7 7',
    maximize: 'M19 9.5l-7 7-7-7',
    presentation: 'M4 5.5h16v10H4zM12 15.5V19M8.5 19h7M9 12l2.5-2.5 2 2L16 8',
    search: 'M16.5 16.5L21 21M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z',
    menu: 'M4 7h16M4 12h16M4 17h16',
    dots: 'M12 6.5h.01M12 12h.01M12 17.5h.01',
    'dots-h': 'M6.5 12h.01M12 12h.01M17.5 12h.01',
    external: 'M14 4h6v6M20 4l-9 9M18 13.5v5A1.5 1.5 0 0116.5 20h-11A1.5 1.5 0 014 18.5v-11A1.5 1.5 0 015.5 6h5',
    refresh: 'M20 12a8 8 0 01-14.5 4.6M4 12a8 8 0 0114.5-4.6M18.5 3.5v4h-4M5.5 20.5v-4h4',
    board: 'M4 5.5A1.5 1.5 0 015.5 4h13A1.5 1.5 0 0120 5.5v13a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5v-13zM9.5 4v16M14.5 4v16',
    tag: 'M4 12.5V5.5A1.5 1.5 0 015.5 4h7l8 8-8.5 8.5L4 12.5zM8.5 8.5h.01',
    send: 'M4 12l16-8-4 16-4-6-8-2z',
    globe: 'M12 21a9 9 0 100-18 9 9 0 000 18zM3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z',
    grip: 'M9 6.5h.01M15 6.5h.01M9 12h.01M15 12h.01M9 17.5h.01M15 17.5h.01',
    info: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 11v5M12 8v.5',
    back: 'M20 12H4M10 6l-6 6 6 6',
    save: 'M5 4h11l3 3v12.5A1.5 1.5 0 0117.5 21h-11A1.5 1.5 0 015 19.5V4zM8 4v5h7V4M8 21v-6h8v6',
    sparkle: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3zM5 18l.7 2 2 .7-2 .7L5 23l-.7-1.6-2-.7 2-.7L5 18z',
    lock: 'M6 11h12v9H6zM8.5 11V8a3.5 3.5 0 017 0v3',
    history: 'M4 12a8 8 0 108-8 8 8 0 00-6.3 3.1M4 4v4h4M12 8v4l2.5 2',
    layout: 'M4 5.5A1.5 1.5 0 015.5 4h13A1.5 1.5 0 0120 5.5v13a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5v-13zM4 10h16M10 10v10',
    inbox: 'M4 13l2.2-7.4A1.5 1.5 0 017.6 4.5h8.8a1.5 1.5 0 011.4 1.1L20 13v5.5a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 18.5V13zM4 13h4.5l1.5 2.5h4l1.5-2.5H20',
    edit: 'M12 20h8M16.5 3.5a2.1 2.1 0 013 3L8 18l-4 1 1-4L16.5 3.5z',
    split: 'M4 6.5A1.5 1.5 0 015.5 5h13A1.5 1.5 0 0120 6.5v11a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5v-11zM12 5v14M7.5 9h2M7.5 12h2M14.5 9h2M14.5 12h2M14.5 15h2',
    bolt: 'M13 3L5 13.5h6L10 21l9-11h-6l0-7z',
    bell: 'M6 16.5V11a6 6 0 0112 0v5.5l1.5 2h-15l1.5-2zM10 20.5a2 2 0 004 0',
    at: 'M16 12a4 4 0 11-8 0 4 4 0 018 0zM16 12v1.5a2.5 2.5 0 005 0V12a9 9 0 10-3.5 7.1',
    monitor: 'M4 5.5A1.5 1.5 0 015.5 4h13A1.5 1.5 0 0120 5.5v9a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 14.5v-9zM12 15v4M8.5 19h7',
};
const icon = (name, cls = '') => `<svg class="i ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${ICONS[name] || ''}"/></svg>`;
const LOGO = `<svg class="mark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#f2b134"/><path d="M13 24h6l-1.2-9h-3.6z" fill="#1a1f2b"/><path d="M12.5 13.5h7" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/><circle cx="16" cy="10.5" r="2.2" fill="#1a1f2b"/><path d="M9.5 9l2.4 1.2M22.5 9l-2.4 1.2M8.2 13.2l2.6-.4M23.8 13.2l-2.6-.4" stroke="#1a1f2b" stroke-width="1.6" stroke-linecap="round"/><path d="M10 25.5h12" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/></svg>`;
const ART = {
    waves: `<svg viewBox="0 0 120 80" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 52c8-8 16-8 24 0s16 8 24 0 16-8 24 0 16 8 24 0" opacity=".5"/><path d="M8 64c8-8 16-8 24 0s16 8 24 0 16-8 24 0 16 8 24 0" opacity=".3"/><path d="M60 40V14M60 14l16 6-16 6" /><path d="M44 42h32l-4 8H48z"/></svg>`,
    compass: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="32" cy="32" r="24"/><path d="M40 24l-4.5 12.5L23 41l4.5-12.5z"/><circle cx="32" cy="32" r="2" fill="currentColor"/><path d="M32 6v4M32 54v4M6 32h4M54 32h4"/></svg>`,
    inbox: `<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 36l6-20h32l6 20v14a3 3 0 01-3 3H13a3 3 0 01-3-3V36z"/><path d="M10 36h13l3 6h12l3-6h13"/><path d="M26 26h12M24 20h16" opacity=".5"/></svg>`,
};

/* ---------- Emoji ---------- */
const EMOJI = (() => { const t = `grinning 😀 smiley 😃 smile 😄 grin 😁 laughing 😆 satisfied 😆 sweat_smile 😅 rofl 🤣 joy 😂 slightly_smiling_face 🙂 upside_down_face 🙃 melting_face 🫠 wink 😉 blush 😊 innocent 😇 smiling_face_with_three_hearts 🥰 heart_eyes 😍 star_struck 🤩 kissing_heart 😘 kissing 😗 relaxed ☺️ kissing_closed_eyes 😚 kissing_smiling_eyes 😙 smiling_face_with_tear 🥲 yum 😋 stuck_out_tongue 😛 stuck_out_tongue_winking_eye 😜 zany_face 🤪 stuck_out_tongue_closed_eyes 😝 money_mouth_face 🤑 hugs 🤗 hand_over_mouth 🤭 face_with_open_eyes_and_hand_over_mouth 🫢 face_with_peeking_eye 🫣 shushing_face 🤫 thinking 🤔 saluting_face 🫡 zipper_mouth_face 🤐 raised_eyebrow 🤨 neutral_face 😐 expressionless 😑 no_mouth 😶 dotted_line_face 🫥 smirk 😏 unamused 😒 roll_eyes 🙄 grimacing 😬 lying_face 🤥 shaking_face 🫨 relieved 😌 pensive 😔 sleepy 😪 drooling_face 🤤 sleeping 😴 mask 😷 face_with_thermometer 🤒 face_with_head_bandage 🤕 nauseated_face 🤢 vomiting_face 🤮 sneezing_face 🤧 hot_face 🥵 cold_face 🥶 woozy_face 🥴 dizzy_face 😵 exploding_head 🤯 cowboy_hat_face 🤠 partying_face 🥳 disguised_face 🥸 sunglasses 😎 nerd_face 🤓 monocle_face 🧐 confused 😕 face_with_diagonal_mouth 🫤 worried 😟 slightly_frowning_face 🙁 frowning_face ☹️ open_mouth 😮 hushed 😯 astonished 😲 flushed 😳 pleading_face 🥺 face_holding_back_tears 🥹 frowning 😦 anguished 😧 fearful 😨 cold_sweat 😰 disappointed_relieved 😥 cry 😢 sob 😭 scream 😱 confounded 😖 persevere 😣 disappointed 😞 sweat 😓 weary 😩 tired_face 😫 yawning_face 🥱 triumph 😤 rage 😡 pout 😡 angry 😠 cursing_face 🤬 smiling_imp 😈 imp 👿 skull 💀 skull_and_crossbones ☠️ hankey 💩 poop 💩 shit 💩 clown_face 🤡 japanese_ogre 👹 japanese_goblin 👺 ghost 👻 alien 👽 space_invader 👾 robot 🤖 smiley_cat 😺 smile_cat 😸 joy_cat 😹 heart_eyes_cat 😻 smirk_cat 😼 kissing_cat 😽 scream_cat 🙀 crying_cat_face 😿 pouting_cat 😾 see_no_evil 🙈 hear_no_evil 🙉 speak_no_evil 🙊 love_letter 💌 cupid 💘 gift_heart 💝 sparkling_heart 💖 heartpulse 💗 heartbeat 💓 revolving_hearts 💞 two_hearts 💕 heart_decoration 💟 heavy_heart_exclamation ❣️ broken_heart 💔 heart ❤️ pink_heart 🩷 orange_heart 🧡 yellow_heart 💛 green_heart 💚 blue_heart 💙 light_blue_heart 🩵 purple_heart 💜 brown_heart 🤎 black_heart 🖤 grey_heart 🩶 white_heart 🤍 kiss 💋 100 💯 anger 💢 boom 💥 collision 💥 dizzy 💫 sweat_drops 💦 dash 💨 hole 🕳️ speech_balloon 💬 left_speech_bubble 🗨️ right_anger_bubble 🗯️ thought_balloon 💭 zzz 💤 wave 👋 raised_back_of_hand 🤚 raised_hand_with_fingers_splayed 🖐️ hand ✋ raised_hand ✋ vulcan_salute 🖖 rightwards_hand 🫱 leftwards_hand 🫲 palm_down_hand 🫳 palm_up_hand 🫴 leftwards_pushing_hand 🫷 rightwards_pushing_hand 🫸 ok_hand 👌 pinched_fingers 🤌 pinching_hand 🤏 v ✌️ crossed_fingers 🤞 hand_with_index_finger_and_thumb_crossed 🫰 love_you_gesture 🤟 metal 🤘 call_me_hand 🤙 point_left 👈 point_right 👉 point_up_2 👆 middle_finger 🖕 fu 🖕 point_down 👇 point_up ☝️ index_pointing_at_the_viewer 🫵 +1 👍 thumbsup 👍 -1 👎 thumbsdown 👎 fist_raised ✊ fist ✊ fist_oncoming 👊 facepunch 👊 punch 👊 fist_left 🤛 fist_right 🤜 clap 👏 raised_hands 🙌 heart_hands 🫶 open_hands 👐 palms_up_together 🤲 handshake 🤝 pray 🙏 writing_hand ✍️ nail_care 💅 selfie 🤳 muscle 💪 mechanical_arm 🦾 mechanical_leg 🦿 leg 🦵 foot 🦶 ear 👂 ear_with_hearing_aid 🦻 nose 👃 brain 🧠 anatomical_heart 🫀 lungs 🫁 tooth 🦷 bone 🦴 eyes 👀 eye 👁️ tongue 👅 lips 👄 biting_lip 🫦 baby 👶 child 🧒 boy 👦 girl 👧 adult 🧑 blond_haired_person 👱 man 👨 bearded_person 🧔 woman 👩 older_adult 🧓 older_man 👴 older_woman 👵 frowning_person 🙍 pouting_face 🙎 no_good 🙅 ok_person 🙆 tipping_hand_person 💁 information_desk_person 💁 raising_hand 🙋 deaf_person 🧏 bow 🙇 facepalm 🤦 shrug 🤷 police_officer 👮 cop 👮 detective 🕵️ guard 💂 ninja 🥷 construction_worker 👷 person_with_crown 🫅 prince 🤴 princess 👸 person_with_turban 👳 man_with_gua_pi_mao 👲 woman_with_headscarf 🧕 person_in_tuxedo 🤵 person_with_veil 👰 pregnant_woman 🤰 pregnant_man 🫃 pregnant_person 🫄 breast_feeding 🤱 angel 👼 santa 🎅 mrs_claus 🤶 superhero 🦸 supervillain 🦹 mage 🧙 fairy 🧚 vampire 🧛 merperson 🧜 elf 🧝 genie 🧞 zombie 🧟 troll 🧌 massage 💆 haircut 💇 walking 🚶 standing_person 🧍 kneeling_person 🧎 runner 🏃 running 🏃 woman_dancing 💃 dancer 💃 man_dancing 🕺 business_suit_levitating 🕴️ dancers 👯 sauna_person 🧖 climbing 🧗 person_fencing 🤺 horse_racing 🏇 skier ⛷️ snowboarder 🏂 golfing 🏌️ surfer 🏄 rowboat 🚣 swimmer 🏊 bouncing_ball_person ⛹️ weight_lifting 🏋️ bicyclist 🚴 mountain_bicyclist 🚵 cartwheeling 🤸 wrestling 🤼 water_polo 🤽 handball_person 🤾 juggling_person 🤹 lotus_position 🧘 bath 🛀 sleeping_bed 🛌 two_women_holding_hands 👭 two_men_holding_hands 👬 speaking_head 🗣️ bust_in_silhouette 👤 busts_in_silhouette 👥 people_hugging 🫂 footprints 👣 monkey_face 🐵 monkey 🐒 gorilla 🦍 orangutan 🦧 dog 🐶 dog2 🐕 guide_dog 🦮 poodle 🐩 wolf 🐺 fox_face 🦊 raccoon 🦝 cat 🐱 cat2 🐈 lion 🦁 tiger 🐯 tiger2 🐅 leopard 🐆 horse 🐴 moose 🫎 donkey 🫏 racehorse 🐎 unicorn 🦄 zebra 🦓 deer 🦌 bison 🦬 cow 🐮 ox 🐂 water_buffalo 🐃 cow2 🐄 pig 🐷 pig2 🐖 boar 🐗 pig_nose 🐽 ram 🐏 sheep 🐑 goat 🐐 dromedary_camel 🐪 camel 🐫 llama 🦙 giraffe 🦒 elephant 🐘 mammoth 🦣 rhinoceros 🦏 hippopotamus 🦛 mouse 🐭 mouse2 🐁 rat 🐀 hamster 🐹 rabbit 🐰 rabbit2 🐇 chipmunk 🐿️ beaver 🦫 hedgehog 🦔 bat 🦇 bear 🐻 koala 🐨 panda_face 🐼 sloth 🦥 otter 🦦 skunk 🦨 kangaroo 🦘 badger 🦡 feet 🐾 paw_prints 🐾 turkey 🦃 chicken 🐔 rooster 🐓 hatching_chick 🐣 baby_chick 🐤 hatched_chick 🐥 bird 🐦 penguin 🐧 dove 🕊️ eagle 🦅 duck 🦆 swan 🦢 owl 🦉 dodo 🦤 feather 🪶 flamingo 🦩 peacock 🦚 parrot 🦜 wing 🪽 goose 🪿 frog 🐸 crocodile 🐊 turtle 🐢 lizard 🦎 snake 🐍 dragon_face 🐲 dragon 🐉 sauropod 🦕 t-rex 🦖 whale 🐳 whale2 🐋 dolphin 🐬 flipper 🐬 seal 🦭 fish 🐟 tropical_fish 🐠 blowfish 🐡 shark 🦈 octopus 🐙 shell 🐚 coral 🪸 jellyfish 🪼 snail 🐌 butterfly 🦋 bug 🐛 ant 🐜 bee 🐝 honeybee 🐝 beetle 🪲 lady_beetle 🐞 cricket 🦗 cockroach 🪳 spider 🕷️ spider_web 🕸️ scorpion 🦂 mosquito 🦟 fly 🪰 worm 🪱 microbe 🦠 bouquet 💐 cherry_blossom 🌸 white_flower 💮 lotus 🪷 rosette 🏵️ rose 🌹 wilted_flower 🥀 hibiscus 🌺 sunflower 🌻 blossom 🌼 tulip 🌷 hyacinth 🪻 seedling 🌱 potted_plant 🪴 evergreen_tree 🌲 deciduous_tree 🌳 palm_tree 🌴 cactus 🌵 ear_of_rice 🌾 herb 🌿 shamrock ☘️ four_leaf_clover 🍀 maple_leaf 🍁 fallen_leaf 🍂 leaves 🍃 empty_nest 🪹 nest_with_eggs 🪺 mushroom 🍄 grapes 🍇 melon 🍈 watermelon 🍉 tangerine 🍊 orange 🍊 mandarin 🍊 lemon 🍋 banana 🍌 pineapple 🍍 mango 🥭 apple 🍎 green_apple 🍏 pear 🍐 peach 🍑 cherries 🍒 strawberry 🍓 blueberries 🫐 kiwi_fruit 🥝 tomato 🍅 olive 🫒 coconut 🥥 avocado 🥑 eggplant 🍆 potato 🥔 carrot 🥕 corn 🌽 hot_pepper 🌶️ bell_pepper 🫑 cucumber 🥒 leafy_green 🥬 broccoli 🥦 garlic 🧄 onion 🧅 peanuts 🥜 beans 🫘 chestnut 🌰 ginger_root 🫚 pea_pod 🫛 bread 🍞 croissant 🥐 baguette_bread 🥖 flatbread 🫓 pretzel 🥨 bagel 🥯 pancakes 🥞 waffle 🧇 cheese 🧀 meat_on_bone 🍖 poultry_leg 🍗 cut_of_meat 🥩 bacon 🥓 hamburger 🍔 fries 🍟 pizza 🍕 hotdog 🌭 sandwich 🥪 taco 🌮 burrito 🌯 tamale 🫔 stuffed_flatbread 🥙 falafel 🧆 egg 🥚 fried_egg 🍳 shallow_pan_of_food 🥘 stew 🍲 fondue 🫕 bowl_with_spoon 🥣 green_salad 🥗 popcorn 🍿 butter 🧈 salt 🧂 canned_food 🥫 bento 🍱 rice_cracker 🍘 rice_ball 🍙 rice 🍚 curry 🍛 ramen 🍜 spaghetti 🍝 sweet_potato 🍠 oden 🍢 sushi 🍣 fried_shrimp 🍤 fish_cake 🍥 moon_cake 🥮 dango 🍡 dumpling 🥟 fortune_cookie 🥠 takeout_box 🥡 crab 🦀 lobster 🦞 shrimp 🦐 squid 🦑 oyster 🦪 icecream 🍦 shaved_ice 🍧 ice_cream 🍨 doughnut 🍩 cookie 🍪 birthday 🎂 cake 🍰 cupcake 🧁 pie 🥧 chocolate_bar 🍫 candy 🍬 lollipop 🍭 custard 🍮 honey_pot 🍯 baby_bottle 🍼 milk_glass 🥛 coffee ☕ teapot 🫖 tea 🍵 sake 🍶 champagne 🍾 wine_glass 🍷 cocktail 🍸 tropical_drink 🍹 beer 🍺 beers 🍻 clinking_glasses 🥂 tumbler_glass 🥃 pouring_liquid 🫗 cup_with_straw 🥤 bubble_tea 🧋 beverage_box 🧃 mate 🧉 ice_cube 🧊 chopsticks 🥢 plate_with_cutlery 🍽️ fork_and_knife 🍴 spoon 🥄 hocho 🔪 knife 🔪 jar 🫙 amphora 🏺 earth_africa 🌍 earth_americas 🌎 earth_asia 🌏 globe_with_meridians 🌐 world_map 🗺️ japan 🗾 compass 🧭 mountain_snow 🏔️ mountain ⛰️ volcano 🌋 mount_fuji 🗻 camping 🏕️ beach_umbrella 🏖️ desert 🏜️ desert_island 🏝️ national_park 🏞️ stadium 🏟️ classical_building 🏛️ building_construction 🏗️ bricks 🧱 rock 🪨 wood 🪵 hut 🛖 houses 🏘️ derelict_house 🏚️ house 🏠 house_with_garden 🏡 office 🏢 post_office 🏣 european_post_office 🏤 hospital 🏥 bank 🏦 hotel 🏨 love_hotel 🏩 convenience_store 🏪 school 🏫 department_store 🏬 factory 🏭 japanese_castle 🏯 european_castle 🏰 wedding 💒 tokyo_tower 🗼 statue_of_liberty 🗽 church ⛪ mosque 🕌 hindu_temple 🛕 synagogue 🕍 shinto_shrine ⛩️ kaaba 🕋 fountain ⛲ tent ⛺ foggy 🌁 night_with_stars 🌃 cityscape 🏙️ sunrise_over_mountains 🌄 sunrise 🌅 city_sunset 🌆 city_sunrise 🌇 bridge_at_night 🌉 hotsprings ♨️ carousel_horse 🎠 playground_slide 🛝 ferris_wheel 🎡 roller_coaster 🎢 barber 💈 circus_tent 🎪 steam_locomotive 🚂 railway_car 🚃 bullettrain_side 🚄 bullettrain_front 🚅 train2 🚆 metro 🚇 light_rail 🚈 station 🚉 tram 🚊 monorail 🚝 mountain_railway 🚞 train 🚋 bus 🚌 oncoming_bus 🚍 trolleybus 🚎 minibus 🚐 ambulance 🚑 fire_engine 🚒 police_car 🚓 oncoming_police_car 🚔 taxi 🚕 oncoming_taxi 🚖 car 🚗 red_car 🚗 oncoming_automobile 🚘 blue_car 🚙 pickup_truck 🛻 truck 🚚 articulated_lorry 🚛 tractor 🚜 racing_car 🏎️ motorcycle 🏍️ motor_scooter 🛵 manual_wheelchair 🦽 motorized_wheelchair 🦼 auto_rickshaw 🛺 bike 🚲 kick_scooter 🛴 skateboard 🛹 roller_skate 🛼 busstop 🚏 motorway 🛣️ railway_track 🛤️ oil_drum 🛢️ fuelpump ⛽ wheel 🛞 rotating_light 🚨 traffic_light 🚥 vertical_traffic_light 🚦 stop_sign 🛑 construction 🚧 anchor ⚓ ring_buoy 🛟 boat ⛵ sailboat ⛵ canoe 🛶 speedboat 🚤 passenger_ship 🛳️ ferry ⛴️ motor_boat 🛥️ ship 🚢 airplane ✈️ small_airplane 🛩️ flight_departure 🛫 flight_arrival 🛬 parachute 🪂 seat 💺 helicopter 🚁 suspension_railway 🚟 mountain_cableway 🚠 aerial_tramway 🚡 artificial_satellite 🛰️ rocket 🚀 flying_saucer 🛸 bellhop_bell 🛎️ luggage 🧳 hourglass ⌛ hourglass_flowing_sand ⏳ watch ⌚ alarm_clock ⏰ stopwatch ⏱️ timer_clock ⏲️ mantelpiece_clock 🕰️ clock12 🕛 clock1230 🕧 clock1 🕐 clock130 🕜 clock2 🕑 clock230 🕝 clock3 🕒 clock330 🕞 clock4 🕓 clock430 🕟 clock5 🕔 clock530 🕠 clock6 🕕 clock630 🕡 clock7 🕖 clock730 🕢 clock8 🕗 clock830 🕣 clock9 🕘 clock930 🕤 clock10 🕙 clock1030 🕥 clock11 🕚 clock1130 🕦 new_moon 🌑 waxing_crescent_moon 🌒 first_quarter_moon 🌓 moon 🌔 waxing_gibbous_moon 🌔 full_moon 🌕 waning_gibbous_moon 🌖 last_quarter_moon 🌗 waning_crescent_moon 🌘 crescent_moon 🌙 new_moon_with_face 🌚 first_quarter_moon_with_face 🌛 last_quarter_moon_with_face 🌜 thermometer 🌡️ sunny ☀️ full_moon_with_face 🌝 sun_with_face 🌞 ringed_planet 🪐 star ⭐ star2 🌟 stars 🌠 milky_way 🌌 cloud ☁️ partly_sunny ⛅ cloud_with_lightning_and_rain ⛈️ sun_behind_small_cloud 🌤️ sun_behind_large_cloud 🌥️ sun_behind_rain_cloud 🌦️ cloud_with_rain 🌧️ cloud_with_snow 🌨️ cloud_with_lightning 🌩️ tornado 🌪️ fog 🌫️ wind_face 🌬️ cyclone 🌀 rainbow 🌈 closed_umbrella 🌂 open_umbrella ☂️ umbrella ☔ parasol_on_ground ⛱️ zap ⚡ snowflake ❄️ snowman_with_snow ☃️ snowman ⛄ comet ☄️ fire 🔥 droplet 💧 ocean 🌊 jack_o_lantern 🎃 christmas_tree 🎄 fireworks 🎆 sparkler 🎇 firecracker 🧨 sparkles ✨ balloon 🎈 tada 🎉 confetti_ball 🎊 tanabata_tree 🎋 bamboo 🎍 dolls 🎎 flags 🎏 wind_chime 🎐 rice_scene 🎑 red_envelope 🧧 ribbon 🎀 gift 🎁 reminder_ribbon 🎗️ tickets 🎟️ ticket 🎫 medal_military 🎖️ trophy 🏆 medal_sports 🏅 1st_place_medal 🥇 2nd_place_medal 🥈 3rd_place_medal 🥉 soccer ⚽ baseball ⚾ softball 🥎 basketball 🏀 volleyball 🏐 football 🏈 rugby_football 🏉 tennis 🎾 flying_disc 🥏 bowling 🎳 cricket_game 🏏 field_hockey 🏑 ice_hockey 🏒 lacrosse 🥍 ping_pong 🏓 badminton 🏸 boxing_glove 🥊 martial_arts_uniform 🥋 goal_net 🥅 golf ⛳ ice_skate ⛸️ fishing_pole_and_fish 🎣 diving_mask 🤿 running_shirt_with_sash 🎽 ski 🎿 sled 🛷 curling_stone 🥌 dart 🎯 yo_yo 🪀 kite 🪁 gun 🔫 8ball 🎱 crystal_ball 🔮 magic_wand 🪄 video_game 🎮 joystick 🕹️ slot_machine 🎰 game_die 🎲 jigsaw 🧩 teddy_bear 🧸 pinata 🪅 mirror_ball 🪩 nesting_dolls 🪆 spades ♠️ hearts ♥️ diamonds ♦️ clubs ♣️ chess_pawn ♟️ black_joker 🃏 mahjong 🀄 flower_playing_cards 🎴 performing_arts 🎭 framed_picture 🖼️ art 🎨 thread 🧵 sewing_needle 🪡 yarn 🧶 knot 🪢 eyeglasses 👓 dark_sunglasses 🕶️ goggles 🥽 lab_coat 🥼 safety_vest 🦺 necktie 👔 shirt 👕 tshirt 👕 jeans 👖 scarf 🧣 gloves 🧤 coat 🧥 socks 🧦 dress 👗 kimono 👘 sari 🥻 one_piece_swimsuit 🩱 swim_brief 🩲 shorts 🩳 bikini 👙 womans_clothes 👚 folding_hand_fan 🪭 purse 👛 handbag 👜 pouch 👝 shopping 🛍️ school_satchel 🎒 thong_sandal 🩴 mans_shoe 👞 shoe 👞 athletic_shoe 👟 hiking_boot 🥾 flat_shoe 🥿 high_heel 👠 sandal 👡 ballet_shoes 🩰 boot 👢 hair_pick 🪮 crown 👑 womans_hat 👒 tophat 🎩 mortar_board 🎓 billed_cap 🧢 military_helmet 🪖 rescue_worker_helmet ⛑️ prayer_beads 📿 lipstick 💄 ring 💍 gem 💎 mute 🔇 speaker 🔈 sound 🔉 loud_sound 🔊 loudspeaker 📢 mega 📣 postal_horn 📯 bell 🔔 no_bell 🔕 musical_score 🎼 musical_note 🎵 notes 🎶 studio_microphone 🎙️ level_slider 🎚️ control_knobs 🎛️ microphone 🎤 headphones 🎧 radio 📻 saxophone 🎷 accordion 🪗 guitar 🎸 musical_keyboard 🎹 trumpet 🎺 violin 🎻 banjo 🪕 drum 🥁 long_drum 🪘 maracas 🪇 flute 🪈 iphone 📱 calling 📲 phone ☎️ telephone ☎️ telephone_receiver 📞 pager 📟 fax 📠 battery 🔋 low_battery 🪫 electric_plug 🔌 computer 💻 desktop_computer 🖥️ printer 🖨️ keyboard ⌨️ computer_mouse 🖱️ trackball 🖲️ minidisc 💽 floppy_disk 💾 cd 💿 dvd 📀 abacus 🧮 movie_camera 🎥 film_strip 🎞️ film_projector 📽️ clapper 🎬 tv 📺 camera 📷 camera_flash 📸 video_camera 📹 vhs 📼 mag 🔍 mag_right 🔎 candle 🕯️ bulb 💡 flashlight 🔦 izakaya_lantern 🏮 lantern 🏮 diya_lamp 🪔 notebook_with_decorative_cover 📔 closed_book 📕 book 📖 open_book 📖 green_book 📗 blue_book 📘 orange_book 📙 books 📚 notebook 📓 ledger 📒 page_with_curl 📃 scroll 📜 page_facing_up 📄 newspaper 📰 newspaper_roll 🗞️ bookmark_tabs 📑 bookmark 🔖 label 🏷️ moneybag 💰 coin 🪙 yen 💴 dollar 💵 euro 💶 pound 💷 money_with_wings 💸 credit_card 💳 receipt 🧾 chart 💹 envelope ✉️ email 📧 e-mail 📧 incoming_envelope 📨 envelope_with_arrow 📩 outbox_tray 📤 inbox_tray 📥 package 📦 mailbox 📫 mailbox_closed 📪 mailbox_with_mail 📬 mailbox_with_no_mail 📭 postbox 📮 ballot_box 🗳️ pencil2 ✏️ black_nib ✒️ fountain_pen 🖋️ pen 🖊️ paintbrush 🖌️ crayon 🖍️ memo 📝 pencil 📝 briefcase 💼 file_folder 📁 open_file_folder 📂 card_index_dividers 🗂️ date 📅 calendar 📆 spiral_notepad 🗒️ spiral_calendar 🗓️ card_index 📇 chart_with_upwards_trend 📈 chart_with_downwards_trend 📉 bar_chart 📊 clipboard 📋 pushpin 📌 round_pushpin 📍 paperclip 📎 paperclips 🖇️ straight_ruler 📏 triangular_ruler 📐 scissors ✂️ card_file_box 🗃️ file_cabinet 🗄️ wastebasket 🗑️ lock 🔒 unlock 🔓 lock_with_ink_pen 🔏 closed_lock_with_key 🔐 key 🔑 old_key 🗝️ hammer 🔨 axe 🪓 pick ⛏️ hammer_and_pick ⚒️ hammer_and_wrench 🛠️ dagger 🗡️ crossed_swords ⚔️ bomb 💣 boomerang 🪃 bow_and_arrow 🏹 shield 🛡️ carpentry_saw 🪚 wrench 🔧 screwdriver 🪛 nut_and_bolt 🔩 gear ⚙️ clamp 🗜️ balance_scale ⚖️ probing_cane 🦯 link 🔗 chains ⛓️ hook 🪝 toolbox 🧰 magnet 🧲 ladder 🪜 alembic ⚗️ test_tube 🧪 petri_dish 🧫 dna 🧬 microscope 🔬 telescope 🔭 satellite 📡 syringe 💉 drop_of_blood 🩸 pill 💊 adhesive_bandage 🩹 crutch 🩼 stethoscope 🩺 x_ray 🩻 door 🚪 elevator 🛗 mirror 🪞 window 🪟 bed 🛏️ couch_and_lamp 🛋️ chair 🪑 toilet 🚽 plunger 🪠 shower 🚿 bathtub 🛁 mouse_trap 🪤 razor 🪒 lotion_bottle 🧴 safety_pin 🧷 broom 🧹 basket 🧺 roll_of_paper 🧻 bucket 🪣 soap 🧼 bubbles 🫧 toothbrush 🪥 sponge 🧽 fire_extinguisher 🧯 shopping_cart 🛒 smoking 🚬 coffin ⚰️ headstone 🪦 funeral_urn ⚱️ nazar_amulet 🧿 hamsa 🪬 moyai 🗿 placard 🪧 identification_card 🪪 atm 🏧 put_litter_in_its_place 🚮 potable_water 🚰 wheelchair ♿ mens 🚹 womens 🚺 restroom 🚻 baby_symbol 🚼 wc 🚾 passport_control 🛂 customs 🛃 baggage_claim 🛄 left_luggage 🛅 warning ⚠️ children_crossing 🚸 no_entry ⛔ no_entry_sign 🚫 no_bicycles 🚳 no_smoking 🚭 do_not_litter 🚯 non-potable_water 🚱 no_pedestrians 🚷 no_mobile_phones 📵 underage 🔞 radioactive ☢️ biohazard ☣️ arrow_up ⬆️ arrow_upper_right ↗️ arrow_right ➡️ arrow_lower_right ↘️ arrow_down ⬇️ arrow_lower_left ↙️ arrow_left ⬅️ arrow_upper_left ↖️ arrow_up_down ↕️ left_right_arrow ↔️ leftwards_arrow_with_hook ↩️ arrow_right_hook ↪️ arrow_heading_up ⤴️ arrow_heading_down ⤵️ arrows_clockwise 🔃 arrows_counterclockwise 🔄 back 🔙 end 🔚 on 🔛 soon 🔜 top 🔝 place_of_worship 🛐 atom_symbol ⚛️ om 🕉️ star_of_david ✡️ wheel_of_dharma ☸️ yin_yang ☯️ latin_cross ✝️ orthodox_cross ☦️ star_and_crescent ☪️ peace_symbol ☮️ menorah 🕎 six_pointed_star 🔯 khanda 🪯 aries ♈ taurus ♉ gemini ♊ cancer ♋ leo ♌ virgo ♍ libra ♎ scorpius ♏ sagittarius ♐ capricorn ♑ aquarius ♒ pisces ♓ ophiuchus ⛎ twisted_rightwards_arrows 🔀 repeat 🔁 repeat_one 🔂 arrow_forward ▶️ fast_forward ⏩ next_track_button ⏭️ play_or_pause_button ⏯️ arrow_backward ◀️ rewind ⏪ previous_track_button ⏮️ arrow_up_small 🔼 arrow_double_up ⏫ arrow_down_small 🔽 arrow_double_down ⏬ pause_button ⏸️ stop_button ⏹️ record_button ⏺️ eject_button ⏏️ cinema 🎦 low_brightness 🔅 high_brightness 🔆 signal_strength 📶 wireless 🛜 vibration_mode 📳 mobile_phone_off 📴 female_sign ♀️ male_sign ♂️ transgender_symbol ⚧️ heavy_multiplication_x ✖️ heavy_plus_sign ➕ heavy_minus_sign ➖ heavy_division_sign ➗ heavy_equals_sign 🟰 infinity ♾️ bangbang ‼️ interrobang ⁉️ question ❓ grey_question ❔ grey_exclamation ❕ exclamation ❗ heavy_exclamation_mark ❗ wavy_dash 〰️ currency_exchange 💱 heavy_dollar_sign 💲 medical_symbol ⚕️ recycle ♻️ fleur_de_lis ⚜️ trident 🔱 name_badge 📛 beginner 🔰 o ⭕ white_check_mark ✅ ballot_box_with_check ☑️ heavy_check_mark ✔️ x ❌ negative_squared_cross_mark ❎ curly_loop ➰ loop ➿ part_alternation_mark 〽️ eight_spoked_asterisk ✳️ eight_pointed_black_star ✴️ sparkle ❇️ copyright ©️ registered ®️ tm ™️ hash #️⃣ asterisk *️⃣ zero 0️⃣ one 1️⃣ two 2️⃣ three 3️⃣ four 4️⃣ five 5️⃣ six 6️⃣ seven 7️⃣ eight 8️⃣ nine 9️⃣ keycap_ten 🔟 capital_abcd 🔠 abcd 🔡 1234 🔢 symbols 🔣 abc 🔤 a 🅰️ ab 🆎 b 🅱️ cl 🆑 cool 🆒 free 🆓 information_source ℹ️ id 🆔 m Ⓜ️ new 🆕 ng 🆖 o2 🅾️ ok 🆗 parking 🅿️ sos 🆘 up 🆙 vs 🆚 koko 🈁 sa 🈂️ u6708 🈷️ u6709 🈶 u6307 🈯 ideograph_advantage 🉐 u5272 🈹 u7121 🈚 u7981 🈲 accept 🉑 u7533 🈸 u5408 🈴 u7a7a 🈳 congratulations ㊗️ secret ㊙️ u55b6 🈺 u6e80 🈵 red_circle 🔴 orange_circle 🟠 yellow_circle 🟡 green_circle 🟢 large_blue_circle 🔵 purple_circle 🟣 brown_circle 🟤 black_circle ⚫ white_circle ⚪ red_square 🟥 orange_square 🟧 yellow_square 🟨 green_square 🟩 blue_square 🟦 purple_square 🟪 brown_square 🟫 black_large_square ⬛ white_large_square ⬜ black_medium_square ◼️ white_medium_square ◻️ black_medium_small_square ◾ white_medium_small_square ◽ black_small_square ▪️ white_small_square ▫️ large_orange_diamond 🔶 large_blue_diamond 🔷 small_orange_diamond 🔸 small_blue_diamond 🔹 small_red_triangle 🔺 small_red_triangle_down 🔻 diamond_shape_with_a_dot_inside 💠 radio_button 🔘 white_square_button 🔳 black_square_button 🔲`.split(' '); const out = []; for (let i = 0; i + 1 < t.length; i += 2) out.push([t[i], t[i + 1]]); return out; })();
const EMOJI_MAP = Object.fromEntries(EMOJI);
const emojiSearch = (q, limit = 40) => {
    q = (q || '').toLowerCase();
    if (!q) return EMOJI.slice(0, limit);
    const starts = [], contains = [];
    for (const e of EMOJI) { if (e[0].startsWith(q)) starts.push(e); else if (e[0].includes(q)) contains.push(e); if (starts.length >= limit) break; }
    return starts.concat(contains).slice(0, limit);
};

/* ---------- Markdown (GFM subset with source line map) ---------- */
const md = (() => {
    const escapeAll = (s) => s.replace(/&(?![a-zA-Z]+;|#\d+;|#x[0-9a-fA-F]+;)/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const escapeKeepTags = (s) => s.replace(/<(\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*)?\/?)>|<!--[\s\S]*?-->|[<>]|&(?![a-zA-Z]+;|#\d+;|#x[0-9a-fA-F]+;)/g, (m, tag) => {
        if (tag) return '<' + tag + '>';
        if (m.startsWith('<!--')) return m;
        return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[m];
    });
    const safeUrl = (u) => /^\s*(javascript|vbscript|data:text\/html)/i.test(u) ? '#' : u;
    const emphasis = (s) => s
        .replace(/\*\*(?=\S)([\s\S]+?)(?<=\S)\*\*/g, '<strong>$1</strong>')
        .replace(/(?<![A-Za-z0-9])__(?=\S)([\s\S]+?)(?<=\S)__(?![A-Za-z0-9])/g, '<strong>$1</strong>')
        .replace(/(?<!\*)\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\*)/g, '<em>$1</em>')
        .replace(/(?<![A-Za-z0-9_])_(?=\S)([^_\n]+?)(?<=\S)_(?![A-Za-z0-9_])/g, '<em>$1</em>')
        .replace(/~~(?=\S)([\s\S]+?)(?<=\S)~~/g, '<del>$1</del>')
        .replace(/:([a-z0-9_+-]+):/g, (m, k) => EMOJI_MAP[k] ? `<span class="emoji">${EMOJI_MAP[k]}</span>` : m);

    function inline(src) {
        const slots = [];
        const keep = (html) => { slots.push(html); return `\u0000${slots.length - 1}\u0000`; };
        let s = src.replace(/(`+)([^`]|[^`][\s\S]*?[^`])\1(?!`)/g, (m, t, code) => keep(`<code>${escapeAll(code.replace(/^ (?=.* $)/, '').replace(/ $/, ''))}</code>`));
        s = escapeKeepTags(s);
        s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/g, (m, alt, url, title) => keep(`<img src="${safeUrl(url)}" alt="${alt}"${title ? ` title="${title}"` : ''}>`));
        s = s.replace(/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/g, (m, text, url, title) => keep(`<a href="${safeUrl(url)}"${title ? ` title="${title}"` : ''} target="_blank" rel="noopener">${emphasis(text)}</a>`));
        s = s.replace(/(^|[\s(])((?:https?:\/\/|www\.)[^\s<]+[^\s<.,:;"')\]!?])/g, (m, pre, url) => pre + keep(`<a href="${url.startsWith('www.') ? 'http://' + url : url}" target="_blank" rel="noopener">${url}</a>`));
        s = emphasis(s);
        return s.replace(/\u0000(\d+)\u0000/g, (m, i) => slots[i]);
    }

    const indentOf = (l) => { let n = 0; for (const ch of l) { if (ch === ' ') n++; else if (ch === '\t') n += 4; else break; } return n; };
    const stripIndent = (l, n) => { let i = 0, w = 0; while (i < l.length && w < n) { if (l[i] === ' ') w++; else if (l[i] === '\t') w += 4; else break; i++; } return l.slice(i); };
    const reList = /^(\s*)([-*+]|\d{1,9}[.)])(\s+|$)(.*)$/;
    const reFence = /^\s{0,3}(`{3,}|~{3,})\s*(\S*)/;
    const reHead = /^\s{0,3}(#{1,6})(?:\s+(.*?))?\s*#*\s*$/;
    const reHr = /^\s{0,3}([-*_])(\s*\1){2,}\s*$/;
    const reSep = /^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/;
    const reHtml = /^\s{0,3}<(?:[a-zA-Z][a-zA-Z0-9-]*|!--)/;
    const isBlockStart = (l) => reFence.test(l) || reHead.test(l) || reHr.test(l) || /^\s{0,3}>/.test(l) || reList.test(l) || reHtml.test(l);
    const splitRow = (l) => { l = l.trim(); if (l.startsWith('|')) l = l.slice(1); if (l.endsWith('|') && !l.endsWith('\\|')) l = l.slice(0, -1); return l.split(/(?<!\\)\|/).map((c) => c.replace(/\\\|/g, '|').trim()); };

    function blocks(lines, base) {
        const out = [];
        let i = 0;
        while (i < lines.length) {
            const line = lines[i];
            let m;
            if (!line.trim()) { i++; continue; }
            if ((m = reFence.exec(line))) {
                const fence = m[1], lang = m[2];
                let j = i + 1; const buf = [];
                while (j < lines.length && !lines[j].trim().startsWith(fence)) { buf.push(lines[j]); j++; }
                out.push(`<pre data-line="${base + i}"><code${lang ? ` class="lang-${esc(lang)}"` : ''}>${escapeAll(buf.join('\n'))}\n</code></pre>`);
                i = j + 1; continue;
            }
            if ((m = reHead.exec(line))) { out.push(`<h${m[1].length} data-line="${base + i}">${inline(m[2] || '')}</h${m[1].length}>`); i++; continue; }
            if (reHr.test(line)) { out.push(`<hr data-line="${base + i}">`); i++; continue; }
            if (/^\s{0,3}>/.test(line)) {
                let j = i; const buf = [];
                while (j < lines.length && /^\s{0,3}>/.test(lines[j])) { buf.push(lines[j].replace(/^\s{0,3}>\s?/, '')); j++; }
                out.push(`<blockquote data-line="${base + i}">${blocks(buf, base + i)}</blockquote>`);
                i = j; continue;
            }
            if (line.includes('|') && i + 1 < lines.length && reSep.test(lines[i + 1]) && lines[i + 1].includes('-')) {
                const head = splitRow(line), aligns = splitRow(lines[i + 1]).map((c) => c.startsWith(':') && c.endsWith(':') ? 'center' : c.endsWith(':') ? 'right' : c.startsWith(':') ? 'left' : '');
                let j = i + 2; const rows = [];
                while (j < lines.length && lines[j].trim() && lines[j].includes('|')) { rows.push(splitRow(lines[j])); j++; }
                const al = (k) => aligns[k] ? ` style="text-align:${aligns[k]}"` : '';
                out.push(`<table data-line="${base + i}"><thead><tr>${head.map((c, k) => `<th${al(k)}>${inline(c)}</th>`).join('')}</tr></thead>` +
                    (rows.length ? `<tbody>${rows.map((r, ri) => `<tr data-line="${base + i + 2 + ri}">${head.map((_, k) => `<td${al(k)}>${inline(r[k] || '')}</td>`).join('')}</tr>`).join('')}</tbody>` : '') + '</table>');
                i = j; continue;
            }
            if ((m = reList.exec(line))) { const r = parseList(lines, i, base); out.push(r.html); i = r.next; continue; }
            if (reHtml.test(line)) {
                let j = i; const buf = [];
                while (j < lines.length && lines[j].trim()) { buf.push(lines[j]); j++; }
                out.push(`<div data-line="${base + i}">${buf.join('\n')}</div>`);
                i = j; continue;
            }
            let j = i; const buf = [];
            while (j < lines.length && lines[j].trim() && (j === i || !isBlockStart(lines[j]))) { buf.push(lines[j].trim()); j++; }
            out.push(`<p data-line="${base + i}">${buf.map(inline).join('<br>\n')}</p>`);
            i = j;
        }
        return out.join('\n');
    }

    function parseList(lines, start, base) {
        const first = reList.exec(lines[start]);
        const indent = indentOf(first[1]);
        const ordered = /\d/.test(first[2]);
        const startNum = ordered ? parseInt(first[2], 10) : 1;
        const items = [];
        let j = start, loose = false;
        while (j < lines.length) {
            const l = lines[j];
            const mm = reList.exec(l);
            const cur = items[items.length - 1];
            if (mm && indentOf(mm[1]) === indent && (/\d/.test(mm[2]) === ordered)) {
                const contentIndent = indent + mm[2].length + (mm[3].length || 1);
                items.push({ line: j, content: [mm[4]], contentIndent });
                j++; continue;
            }
            if (mm && indentOf(mm[1]) < indent) break;
            if (!l.trim()) {
                const next = lines[j + 1];
                if (next === undefined) break;
                const nm = reList.exec(next);
                if ((nm && indentOf(nm[1]) >= indent) || (next.trim() && indentOf(next) >= cur.contentIndent)) { cur.content.push(''); if (!nm || indentOf(nm[1]) > indent) loose = loose || false; if (nm && indentOf(nm[1]) === indent) loose = true; j++; continue; }
                break;
            }
            const ind = indentOf(l);
            if (ind >= cur.contentIndent || (mm && ind > indent)) { cur.content.push(stripIndent(l, cur.contentIndent)); j++; continue; }
            if (!mm && cur.content[cur.content.length - 1] !== '' && !isBlockStart(l)) { cur.content.push(l.trim()); j++; continue; }
            break;
        }
        const html = items.map((it) => {
            let cls = '', prefix = '';
            const t = /^\[([ xX])\]\s+/.exec(it.content[0]);
            if (t) { it.content[0] = it.content[0].slice(t[0].length); const done = t[1] !== ' '; cls = ` class="task${done ? ' done' : ''}"`; prefix = `<input type="checkbox"${done ? ' checked' : ''}>`; }
            let body = blocks(it.content, base + it.line);
            const single = /^<p data-line="\d+">([\s\S]*)<\/p>$/.exec(body);
            if (!loose && single) body = single[1];
            else if (single) body = single[0];
            if (t) body = `<span>${body}</span>`;
            return `<li${cls} data-line="${base + it.line}">${prefix}${body}</li>`;
        }).join('\n');
        const tag = ordered ? 'ol' : 'ul';
        return { html: `<${tag}${ordered && startNum !== 1 ? ` start="${startNum}"` : ''}>${html}</${tag}>`, next: j };
    }

    return {
        render: (text) => sanitizeHtml(blocks(String(text || '').replace(/\r\n?/g, '\n').split('\n'), 0)),
        inline: (text) => sanitizeHtml(inline(String(text || ''))),
    };
})();

/* Raw HTML stays allowed in cards (it always was), minus anything that can run script:
   script-bearing elements, on* handlers, and javascript:/vbscript:/data: URLs (data: images excepted). */
function sanitizeHtml(html) {
    if (!/[<&]/.test(html)) return html;
    const t = document.createElement('template'); t.innerHTML = html;
    const DROP = 'script,style,iframe,frame,frameset,object,embed,applet,link,meta,base,form,noscript,template,portal,math,animate,set,animateMotion,animateTransform,animatemotion,animatetransform,discard,handler,listener';
    t.content.querySelectorAll(DROP).forEach((n) => n.remove());
    const bad = (v) => /^(javascript|vbscript|data):/i.test(String(v).replace(/[\u0000-\u0020\u007f-\u009f]/g, ''));
    t.content.querySelectorAll('*').forEach((el) => {
        for (const a of Array.from(el.attributes)) {
            const n = a.name.toLowerCase();
            if (n.startsWith('on') || n === 'srcdoc' || n === 'formaction' || n === 'action' || n === 'attributename') { el.removeAttribute(a.name); continue; }
            if (['href', 'src', 'xlink:href', 'poster', 'background', 'cite', 'srcset'].includes(n) && bad(a.value) && !(n === 'src' && el.tagName === 'IMG' && /^data:image\//i.test(a.value.trim()))) el.removeAttribute(a.name);
        }
        if (el.tagName === 'INPUT' && el.type !== 'checkbox') el.remove();
        if (el.tagName === 'A' && el.getAttribute('target') === '_blank') el.setAttribute('rel', 'noopener');
    });
    return t.innerHTML;
}

/* ---------- Dates ---------- */
const pad2 = (n) => String(n).padStart(2, '0');
const todayISO = () => { const d = new Date(); return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`; };
const parseDate = (s) => { if (!s) return null; const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s); const d = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(s); return isNaN(d) ? null : d; };
const fmtShort = (s) => { const d = parseDate(s); return d ? `${MONTHS[d.getMonth()]} ${d.getDate()}` : ''; };
const dayDiff = (s) => { const d = parseDate(s); if (!d) return 0; const t = new Date(); t.setHours(0, 0, 0, 0); return Math.round((d - t) / 86400000); };
const dueClass = (s) => { const n = dayDiff(s); return n < 0 ? 'overdue' : n <= 2 ? 'soon' : ''; };
function timeAgo(iso) {
    const d = parseDate(iso); if (!d) return '';
    const diff = (Date.now() - d.getTime()) / 1000, abs = Math.abs(diff), fut = diff < 0;
    const f = (n, u) => (fut ? `in ${n}${u}` : `${n}${u} ago`);
    if (abs < 45) return 'just now';
    if (abs < 3600) return f(Math.round(abs / 60), 'm');
    if (abs < 86400) return f(Math.round(abs / 3600), 'h');
    if (abs < 86400 * 7) return f(Math.round(abs / 86400), 'd');
    if (abs < 86400 * 30) return f(Math.round(abs / (86400 * 7)), 'w');
    if (abs < 86400 * 365) return f(Math.round(abs / (86400 * 30)), 'mo');
    return f(Math.round(abs / (86400 * 365)), 'y');
}
const fmtFull = (iso) => { const d = parseDate(iso); return d ? d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''; };

/* ---------- Layers (modals) ---------- */
const layersEl = document.getElementById('layers');
const layerStack = [];
function openLayer(name, html, opts = {}) {
    closeLayer(name);
    const el = document.createElement('div');
    el.className = 'layer' + (opts.top ? ' top' : '');
    el.dataset.layer = name;
    el.innerHTML = html;
    el._opts = opts;
    el.addEventListener('mousedown', (e) => { if (e.target === el) el._down = true; });
    el.addEventListener('mouseup', (e) => { if (e.target === el && el._down && opts.dismiss !== false) dismissLayer(name); el._down = false; });
    layersEl.appendChild(el);
    layerStack.push(name);
    const f = opts.focus ? $(opts.focus, el) : null; if (f) setTimeout(() => f.focus(), 30);
    return el;
}
const layerEl = (name) => $(`[data-layer="${name}"]`, layersEl);
function closeLayer(name) { const el = layerEl(name); if (el) el.remove(); const i = layerStack.lastIndexOf(name); if (i > -1) layerStack.splice(i, 1); }
function dismissLayer(name) { const el = layerEl(name); if (!el) return; if (el._opts.onClose) el._opts.onClose(); else closeLayer(name); }
const topLayer = () => layerStack[layerStack.length - 1];
const winHead = (title, name) => `<div class="win-head"><h3>${esc(title)}</h3><button class="ibtn" data-close="${name}" aria-label="Close">${icon('close')}</button></div>`;
function bindClose(el) { on(el, 'click', '[data-close]', (e, t) => dismissLayer(t.dataset.close)); }

/* Dialogs: confirm / prompt / alert (replace native ones) */
const dialog = {
    _open(kind, o) {
        return new Promise((resolve) => {
            const name = 'dialog';
            const el = openLayer(name, `
                <div class="win dialog ${o.danger ? 'danger' : o.info ? 'info' : ''}" role="dialog" aria-modal="true">
                    <div class="win-body">
                        <div class="icon-wrap">${icon(o.danger ? 'trash' : o.info ? 'info' : kind === 'prompt' ? 'edit' : 'warning')}</div>
                        ${o.title ? `<h3 style="margin:0 0 6px;font-size:16px">${esc(o.title)}</h3>` : ''}
                        ${o.message ? `<p class="help" style="font-size:13.5px;color:var(--text-2)">${esc(o.message)}</p>` : ''}
                        ${kind === 'prompt' ? `<input class="field" id="dlg-input" value="${esc(o.value || '')}" placeholder="${esc(o.placeholder || '')}" style="margin-top:10px">` : ''}
                    </div>
                    <div class="win-foot">
                        ${kind === 'alert' ? '' : `<button class="btn" data-r="cancel">${esc(o.cancel || 'Cancel')}</button>`}
                        <button class="btn primary ${o.danger ? 'danger' : ''}" data-r="ok" style="${o.danger ? 'background:var(--danger);border-color:var(--danger);color:#fff' : ''}">${esc(o.ok || (kind === 'alert' ? 'OK' : 'Confirm'))}</button>
                    </div>
                </div>`, { dismiss: false, focus: kind === 'prompt' ? '#dlg-input' : '[data-r="ok"]' });
            const finish = (v) => { closeLayer(name); resolve(v); };
            on(el, 'click', '[data-r]', (e, t) => finish(t.dataset.r === 'ok' ? (kind === 'prompt' ? $('#dlg-input', el).value : true) : (kind === 'prompt' ? null : false)));
            el.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !(e.target.matches('button') && !e.target.matches('[data-r="ok"]'))) { e.preventDefault(); finish(kind === 'prompt' ? $('#dlg-input', el).value : true); } if (e.key === 'Escape') { e.stopPropagation(); finish(kind === 'prompt' ? null : false); } });
            el._opts.onClose = () => finish(kind === 'prompt' ? null : false);
        });
    },
    confirm: (o) => dialog._open('confirm', typeof o === 'string' ? { message: o } : o),
    prompt: (o) => dialog._open('prompt', o),
    alert: (o) => dialog._open('alert', typeof o === 'string' ? { message: o, info: true } : { info: true, ...o }),
};

/* ---------- Toasts ---------- */
function toast(message, type = 'ok') {
    const box = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `${icon(type === 'ok' ? 'check-circle' : type === 'err' ? 'warning' : 'info')}<span>${esc(message)}</span>`;
    el.addEventListener('click', () => el.remove());
    box.appendChild(el);
    setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 320); }, 3200);
}

/* ---------- Combobox ----------
   comboHtml() renders the input; bindCombo() wires it. Options are [{ value, label, sub? }].
   Strict: the text must match an option, otherwise it reverts on blur. The list is fixed-
   positioned on document.body so it escapes scrolling panes and modals. */
function comboHtml(name, placeholder = '') { return `<div class="combo" data-combo="${esc(name)}"><input class="field" placeholder="${esc(placeholder)}" autocomplete="off" spellcheck="false"><span class="combo-caret">${icon('chev-down', 'sm')}</span></div>`; }
function bindCombo(wrap, opts) {
    const input = $('input', wrap); let options = opts.options || [], value = opts.value ?? '', list = null, sel = 0, filtered = [];
    const labelOf = (v) => { const o = options.find((x) => String(x.value) === String(v)); return o ? o.label : ''; };
    const close = () => { if (list) { list.remove(); list = null; } wrap.classList.remove('open'); };
    const place = () => { if (!list) return; const r = input.getBoundingClientRect(); const h = Math.min(list.scrollHeight + 2, 260); const below = window.innerHeight - r.bottom - 8; const top = below >= Math.min(h, 140) || r.top < h ? r.bottom + 4 : r.top - h - 4; list.style.left = r.left + 'px'; list.style.top = top + 'px'; list.style.width = r.width + 'px'; };
    const draw = () => {
        if (!list) { list = document.createElement('div'); list.className = 'combo-list'; document.body.appendChild(list); list.addEventListener('mousedown', (e) => { e.preventDefault(); const b = e.target.closest('[data-i]'); if (b) pick(filtered[+b.dataset.i]); }); wrap.classList.add('open'); }
        list.innerHTML = filtered.length ? filtered.map((o, i) => `<button class="combo-item ${i === sel ? 'sel' : ''} ${String(o.value) === String(value) ? 'cur' : ''}" data-i="${i}"><span class="grow">${esc(o.label)}${o.sub ? `<br><small>${esc(o.sub)}</small>` : ''}</span>${String(o.value) === String(value) ? icon('check', 'sm') : ''}</button>`).join('') : `<div class="combo-empty">${esc(opts.empty || 'No matches')}</div>`;
        const s = $('.combo-item.sel', list); if (s) s.scrollIntoView({ block: 'nearest' }); place();
    };
    const open = (q = '') => { const t = q.trim().toLowerCase(); filtered = t ? options.filter((o) => (o.label + ' ' + (o.sub || '')).toLowerCase().includes(t)) : options.slice(); sel = Math.max(0, filtered.findIndex((o) => String(o.value) === String(value))); if (t) sel = 0; draw(); };
    const pick = (o) => { if (!o) return; value = o.value; input.value = o.label; close(); if (opts.onPick) opts.onPick(o.value, o); };
    input.value = labelOf(value);
    input.addEventListener('focus', () => { input.select(); open(''); });
    input.addEventListener('click', () => { if (!list) open(''); });
    input.addEventListener('input', () => open(input.value));
    input.addEventListener('keydown', (e) => {
        if (!list && ['ArrowDown', 'ArrowUp'].includes(e.key)) { e.preventDefault(); open(''); return; }
        if (!list) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, filtered.length - 1); draw(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, 0); draw(); }
        else if (e.key === 'Enter') { e.preventDefault(); pick(filtered[sel]); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); input.value = labelOf(value); close(); }
        else if (e.key === 'Tab') close();
    });
    input.addEventListener('blur', () => { setTimeout(() => { close(); if (opts.strict !== false) input.value = labelOf(value); }, 120); });
    const onScroll = (e) => { if (!wrap.isConnected) return detach(); if (list && !list.contains(e.target)) place(); };
    const onResize = () => { if (!wrap.isConnected) return detach(); place(); };
    const detach = () => { window.removeEventListener('scroll', onScroll, true); window.removeEventListener('resize', onResize); close(); };
    window.addEventListener('scroll', onScroll, true); window.addEventListener('resize', onResize);
    const gc = new MutationObserver(() => { if (!wrap.isConnected) { detach(); gc.disconnect(); } });
    gc.observe(document.body, { childList: true, subtree: true });
    return {
        get value() { return value; },
        set(v) { value = v ?? ''; input.value = labelOf(value); },
        setOptions(o, v) { options = o || []; if (v !== undefined) value = v; input.value = labelOf(value); },
        clear() { value = ''; input.value = ''; },
        focus() { input.focus(); },
    };
}

/* ---------- State ---------- */
const S = {
    user: lsGet('beckon_user', { name: 'Guest', initials: 'G', color: 'slate' }),
    boardId: lsRaw('beckon_last_board') || '',
    boards: [],
    board: { title: 'Loading…', lists: [], archive: [], users: {} },
    sync: 'synced',
    hover: null,
    drag: null,
    composer: null,
    composerFocus: false,
    active: null,
    ui: {
        sidebar: lsRaw('beckon_sidebar_open') !== 'false',
        activity: lsRaw('beckon_activity_open') !== 'false',
        actMax: false,
        actTab: 'comments',
        view: lsRaw('beckon_view') || 'split',
        ratio: parseFloat(lsRaw('beckon_ratio')) || 50,
        revision: -1,
        editingComment: null,
        mobileMenu: false,
        pop: null,
    },
    wp: { sites: lsGet('beckon_wp_sites', []), selected: lsRaw('beckon_wp_selected') || '' },
    update: { current: '<?php echo BECKON_VERSION; ?>', latest: null, update_available: false },
    searchStats: { available: false, card_count: 0 },
};
let lastSaveTime = 0;
const ownLayoutHashes = [];           // hashes of layout.json as written by this tab's own saves
let layoutSavesInFlight = [];
let eventSource = null;
let sseUnavailable = false;

/* ---------- API ---------- */
function setSync(state) {
    S.sync = state;
    $$('.sync').forEach((el) => { el.innerHTML = `<span class="dot ${state === 'synced' ? 'ok' : state === 'saving' ? 'warn' : 'bad'}"></span>${state}`; });
}
async function api(action, payload = {}, board = S.boardId) {
    setSync('saving');
    try {
        const res = await fetch(`?action=${action}&board=${encodeURIComponent(board || '')}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { const err = new Error(data.error || `Request failed (${res.status})`); err.status = res.status; err.data = data; throw err; }
        setTimeout(() => { if (S.sync === 'saving') setSync('synced'); }, 400);
        return data;
    } catch (e) { if (e.status === 409) setSync('synced'); else setSync('offline'); throw e; }
}
async function apiUpload(action, file, board = S.boardId) {
    const fd = new FormData(); fd.append('file', file);
    const res = await fetch(`?action=${action}&board=${encodeURIComponent(board || '')}`, { method: 'POST', body: fd });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Upload failed');
    return data;
}
const saveLocal = () => lsSet(`beckon_${S.boardId}`, S.board);
/* ---------- Layout saves: revisions and merging ----------
   Every layout.json write bumps a revision. A tab sends the revision its copy is based on;
   if the board moved on (another tab, the CLI, a card moved in from another board), the
   server answers 409 with the newer layout and the tab merges its own changes into it,
   then retries. One save per tab is in flight at a time; edits made meanwhile ride the next. */
let layoutRev = 0, layoutBase = null, layoutSaving = null, layoutAgain = false;
const layoutSnapshot = (b) => JSON.parse(JSON.stringify({ version: b.version, title: b.title, lists: b.lists || [], archive: b.archive || [] }));
const canon = (v) => JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x)) ? Object.keys(x).sort().reduce((o, key) => (o[key] = x[key], o), {}) : x);
function mergeLayout(base, local, remote) {
    const out = JSON.parse(JSON.stringify(remote)); out.archive = out.archive || [];
    base = base || { title: remote.title, lists: [], archive: [] };
    const cardSig = (c) => { const x = { ...c }; delete x.description; return canon(x); };
    const index = (b) => { const m = new Map(); (b.lists || []).forEach((l) => l.cards.forEach((c, i) => m.set(String(c.id), { box: l.id, prev: i ? String(l.cards[i - 1].id) : null, card: c }))); (b.archive || []).forEach((c, i) => m.set(String(c.id), { box: '@archive', prev: i ? String(b.archive[i - 1].id) : null, card: c })); return m; };
    const B = index(base), L = index(local), R = index(out);
    if (local.title !== base.title) out.title = local.title;
    // Lists: added, renamed, deleted, reordered here win; lists only the other side touched stay as they are.
    const bl = new Map(base.lists.map((l) => [l.id, l])), ll = new Map(local.lists.map((l) => [l.id, l]));
    local.lists.forEach((l) => { const r = out.lists.find((x) => x.id === l.id); if (!bl.has(l.id)) { if (!r) out.lists.push({ ...JSON.parse(JSON.stringify(l)), cards: [] }); } else if (r && l.title !== bl.get(l.id).title) r.title = l.title; });
    // A list deleted here goes, but cards the other side added to it (never seen here) are archived, not lost.
    out.lists.filter((l) => bl.has(l.id) && !ll.has(l.id)).forEach((l) => l.cards.forEach((c) => { if (!B.has(String(c.id))) out.archive.unshift(c); }));
    out.lists = out.lists.filter((l) => !(bl.has(l.id) && !ll.has(l.id)));
    const common = (arr) => arr.map((l) => l.id).filter((id) => bl.has(id) && ll.has(id));
    if (canon(common(local.lists)) !== canon(common(base.lists))) { const order = local.lists.map((l) => l.id); const pos = (id) => { const i = order.indexOf(id); return i < 0 ? 1e9 : i; }; out.lists.sort((a, b) => pos(a.id) - pos(b.id)); }
    const take = (id) => { for (const l of out.lists) { const i = l.cards.findIndex((c) => String(c.id) === id); if (i > -1) return l.cards.splice(i, 1)[0]; } const a = out.archive.findIndex((c) => String(c.id) === id); return a > -1 ? out.archive.splice(a, 1)[0] : null; };
    const find = (id) => { for (const l of out.lists) { const c = l.cards.find((x) => String(x.id) === id); if (c) return c; } return out.archive.find((x) => String(x.id) === id) || null; };
    // Cards deleted here.
    for (const id of B.keys()) if (!L.has(id)) take(id);
    // Cards edited here (a card the other side deleted stays deleted: its files are gone).
    // Only the fields this tab changed are applied, so the other side's edits to other fields survive.
    for (const [id, lc] of L) {
        const b = B.get(id), r = find(id); if (!b || !r || cardSig(lc.card) === cardSig(b.card)) continue;
        for (const k of new Set([...Object.keys(lc.card), ...Object.keys(b.card)])) {
            if (k === 'description') continue;
            if (canon(lc.card[k]) === canon(b.card[k])) continue;
            if (k in lc.card) r[k] = JSON.parse(JSON.stringify(lc.card[k])); else delete r[k];
        }
    }
    // Cards added, moved or reordered here, placed after the same neighbour, in this tab's order.
    const placed = new Set(); for (const [id, lc] of L) { const b = B.get(id); if (!b || b.box !== lc.box || b.prev !== lc.prev) placed.add(id); }
    const order = [...local.lists.flatMap((l) => l.cards.map((c) => [l.id, c])), ...(local.archive || []).map((c) => ['@archive', c])];
    for (const [boxId, c] of order) {
        const id = String(c.id); if (!placed.has(id) || (B.has(id) && !R.has(id))) continue;
        const card = take(id) || JSON.parse(JSON.stringify(c)); // an existing card only moves; its fields were merged above
        const target = boxId === '@archive' ? out.archive : (out.lists.find((l) => l.id === boxId) || out.lists[0] || {}).cards;
        if (!target) continue;
        const prev = L.get(id).prev; const pi = prev ? target.findIndex((x) => String(x.id) === prev) : -1;
        target.splice(prev ? (pi > -1 ? pi + 1 : target.length) : 0, 0, card);
    }
    return out;
}
/* Swap in a merged or reloaded layout without losing the open card or a half-typed input. */
function adoptLayout(layout, rev, base) {
    const active = S.active && S.active.card;
    S.board.title = layout.title; S.board.lists = layout.lists; S.board.archive = layout.archive || []; if (layout.version) S.board.version = layout.version;
    layoutRev = rev; layoutBase = layoutSnapshot(base || layout);
    if (active) { const at = locateCard(active.id); if (at) { if (active.description !== undefined) at.card.description = active.description; S.active.card = at.card; S.active.l = at.l; S.active.c = at.c; } }
    saveLocal(); setTitle();
    const typing = document.activeElement && document.activeElement.closest && document.activeElement.closest('#board') && /INPUT|TEXTAREA/.test(document.activeElement.tagName);
    if (!typing && !S.drag) renderBoard();
    if (S.active && cwEl()) { cwUpdateHead(); cwRenderSide(); }
}
function persistLayout() {
    saveLocal(); lastSaveTime = Date.now();
    if (layoutSaving) { layoutAgain = true; return layoutSaving; }
    const boardAtStart = S.boardId;
    const run = (async () => {
        for (let attempt = 0; attempt < 6; attempt++) {
            if (S.boardId !== boardAtStart) return null;
            const body = layoutSnapshot(S.board);
            try {
                const r = await api('save_layout', { ...body, baseRev: layoutRev });
                layoutRev = r.rev; layoutBase = body;
                if (r.hash) { ownLayoutHashes.push(r.hash); if (ownLayoutHashes.length > 20) ownLayoutHashes.shift(); }
                const b = S.boards.find((x) => x.id === S.boardId); if (b) b.name = S.board.title;
                return r;
            } catch (e) {
                if (e.status !== 409 || !e.data || !e.data.layout || S.boardId !== boardAtStart) throw e;
                const remote = e.data.layout;
                adoptLayout(mergeLayout(layoutBase, layoutSnapshot(S.board), remote), e.data.rev, remote);
            }
        }
        toast('The board kept changing while saving. Reload to be safe.', 'err');
        return null;
    })();
    layoutSaving = run; layoutSavesInFlight.push(run);
    run.catch(() => {}).finally(() => {
        layoutSaving = null; layoutSavesInFlight = layoutSavesInFlight.filter((x) => x !== run);
        if (layoutAgain) { layoutAgain = false; persistLayout(); }
    });
    return run;
}
function persistCardDesc(card) { if (card && card.id) { saveLocal(); lastSaveTime = Date.now(); api('save_card', { id: card.id, description: card.description || '' }).catch(() => {}); } }
function persistMeta(id, meta) { if (id) { lastSaveTime = Date.now(); api('save_card_meta', { id, meta }).catch(() => {}); } }
async function loadData() {
    try {
        const data = await api('load');
        if (data.lists) { S.board = data; if (!S.board.archive) S.board.archive = []; if (!S.board.users || Array.isArray(S.board.users)) S.board.users = {}; layoutRev = data.rev || 0; layoutBase = layoutSnapshot(data); saveLocal(); setTitle(); return; }
    } catch (e) {}
    S.board = lsGet(`beckon_${S.boardId}`, { title: S.boardId, lists: [{ id: 'l1', title: 'Start', cards: [] }], archive: [], users: {} });
    setTitle();
}
function connectSSE() {
    if (eventSource) { eventSource.close(); eventSource = null; }
    if (!S.boardId || !window.EventSource || sseUnavailable) return;
    eventSource = new EventSource(`?action=events&board=${encodeURIComponent(S.boardId)}`);
    const boardForStream = S.boardId;
    // Streams are short and reconnect constantly; if the board changed in the gap, the
    // 'connected' hash differs from the last one this tab knew about.
    eventSource.addEventListener('connected', (ev) => {
        let hash = null; try { hash = JSON.parse(ev.data).hash || null; } catch (e) {}
        if (!hash || S.boardId !== boardForStream) return;
        const known = lastStreamHash[boardForStream]; lastStreamHash[boardForStream] = hash;
        if (known && known !== hash) onBoardUpdated(hash);
    });
    eventSource.addEventListener('board_updated', (ev) => {
        let hash = null; try { hash = JSON.parse(ev.data).hash || null; } catch (e) {}
        if (hash) lastStreamHash[boardForStream] = hash;
        onBoardUpdated(hash);
    });
    eventSource.addEventListener('timeout', () => { eventSource.close(); connectSSE(); });
    // The server has no worker to spare for a stream (PHP's built-in server
    // with one worker): stop asking, or the retry would stall the board.
    eventSource.addEventListener('unavailable', () => { sseUnavailable = true; eventSource.close(); eventSource = null; });
    eventSource.onerror = () => { eventSource.close(); setTimeout(connectSSE, 5000); };
}
const lastStreamHash = {};
async function onBoardUpdated(hash) {
        // Our own saves come back as events too. Wait for any save still in flight, then skip
        // the event only if the layout it reports is one this tab wrote.
        while (layoutSaving) await layoutSaving.catch(() => {});
        if (hash && ownLayoutHashes.includes(hash)) return;
        if (!hash && Date.now() - lastSaveTime < 2000) return;
        if (S.drag) return;
        // Don't pull the board out from under someone typing a list title or a new card.
        const typing = document.activeElement && document.activeElement.closest && document.activeElement.closest('#board') && /INPUT|TEXTAREA/.test(document.activeElement.tagName);
        if (!S.active && typing) { S.pendingReload = true; return; }
        if (!S.active) { await loadData(); renderBoard(); return; }
        // A card is open: take the newer board, but keep this card's in-progress state and
        // re-point the window at the fresh card object, so later saves don't send a stale board.
        const mine = S.active.card;
        await loadData();
        if (!S.active || S.active.card !== mine) return;
        const at = locateCard(mine.id);
        if (!at) { toast('This card was moved or removed elsewhere. Your edits to its text are still saved.', 'info'); renderBoard(); return; }
        Object.assign(at.card, mine);
        S.active.card = at.card; S.active.l = at.l; S.active.c = at.c;
        renderBoard(); cwUpdateHead(); cwRenderSide();
        // Comments from other tabs arrive with a board change (their comment count moved).
        if (!S.active.loading) api('get_card', { id: mine.id }).then((r) => {
            if (!S.active || String(S.active.card.id) !== String(mine.id) || S.ui.editingComment) return;
            const fresh = (r.meta && r.meta.comments) || [];
            if (canon(fresh) !== canon(S.active.meta.comments)) { S.active.meta.comments = fresh; S.active.card.commentCount = fresh.length; cwRenderActivity(); }
        }).catch(() => {});
}

/* ---------- Users ---------- */
const getUser = (id) => (S.board.users && S.board.users[id]) || null;
const userAvatar = (id) => { const u = getUser(id); if (!u) return null; return u.avatarFile || (u.avatarHash ? `https://trello-members.s3.amazonaws.com/${u.id}/${u.avatarHash}/170.png` : null); };
const userInitials = (id) => (getUser(id) && getUser(id).initials) || '?';
const userName = (id) => (getUser(id) && getUser(id).fullName) || 'Unknown user';
const avatarHtml = (id, cls = '') => { const src = userAvatar(id); return `<span class="avatar ${cls}" title="${esc(userName(id))}">${src ? `<img src="${esc(src)}" alt="">` : esc(userInitials(id))}</span>`; };
const meAvatar = (cls = '') => `<span class="avatar ${cls} bg-${esc(S.user.color || 'slate')}" data-color>${esc(S.user.initials || 'G')}</span>`;
function saveIdentity() { S.user.initials = initialsOf(S.user.name); lsSet('beckon_user', S.user); }

/* ---------- Card stats ---------- */
const taskStats = (card) => card.checklistStats || card.descStats || { total: 0, done: 0 };
function refreshDescFlags(card) {
    const txt = card.description || '';
    card.hasDesc = txt.trim().length > 0;
    card.hasAtt = txt.indexOf('/uploads/') !== -1;
    const plain = txt.replace(/^\s*(```|~~~)[\s\S]*?^\s*\1\s*$/gm, '');
    const total = (plain.match(/^\s*(?:>\s*)*(?:[-*+]|\d{1,9}[.)])\s+\[[ xX]\]\s+\S/gm) || []).length, done = (plain.match(/^\s*(?:>\s*)*(?:[-*+]|\d{1,9}[.)])\s+\[[xX]\]\s+\S/gm) || []).length;
    if (total > 0) card.descStats = { total, done }; else delete card.descStats;
}

/* ---------- Theme ---------- */
const isDark = () => { const t = document.documentElement.getAttribute('data-theme'); return t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches; };
const themeMode = () => document.documentElement.getAttribute('data-theme') || 'system';
function setTheme(mode) {
    if (mode === 'system') { document.documentElement.removeAttribute('data-theme'); try { localStorage.removeItem('beckon_theme'); localStorage.removeItem('beckon_darkMode'); } catch (e) {} }
    else { document.documentElement.setAttribute('data-theme', mode); try { localStorage.setItem('beckon_theme', mode); } catch (e) {} }
    renderTopbar();
}
function toggleTheme() { setTheme(isDark() ? 'light' : 'dark'); }
function showThemeCtx(x, y) {
    const cur = themeMode();
    const row = (mode, label, ic) => `<button data-x="${mode}">${icon(ic, 'sm')} ${label}<span style="margin-left:auto;color:var(--accent)">${cur === mode ? icon('check', 'sm') : ''}</span></button>`;
    const el = ctxAt(x, y, row('light', 'Light', 'sun') + row('dark', 'Dark', 'moon') + row('system', 'System', 'monitor'));
    on(el, 'click', '[data-x]', (e, t) => { closeCtx(); setTheme(t.dataset.x); });
}

/* ---------- Popovers ---------- */
function closePop() { $$('.pop, .mobile-menu').forEach((p) => p.remove()); S.ui.pop = null; S.ui.mobileMenu = false; const mb = $('[data-act="mobile-menu"]'); if (mb) mb.innerHTML = icon('menu'); }
function openPop(wrapId, name, html, opts = {}) {
    if (S.ui.pop === name) { closePop(); return null; }
    closePop();
    const wrap = document.getElementById(wrapId); if (!wrap) return null;
    const pop = document.createElement('div'); pop.className = 'pop' + (opts.right ? ' right' : ''); pop.innerHTML = html;
    wrap.appendChild(pop); S.ui.pop = name;
    const f = opts.focus ? $(opts.focus, pop) : null; if (f) setTimeout(() => f.focus(), 20);
    return pop;
}
document.addEventListener('mousedown', (e) => {
    if (S.ui.pop && !e.target.closest('.pop') && !e.target.closest('.mobile-menu') && !e.target.closest('[data-act]')) closePop();
    if (!e.target.closest('.ctx')) closeCtx();
    if (!e.target.closest('.picker') && !e.target.closest('[data-react-add]')) $$('.picker').forEach((p) => p.remove());
});

/* ---------- Top bar ---------- */
function renderTopbar() {
    const tb = document.getElementById('topbar');
    const archived = (S.board.archive || []).length, users = Object.keys(S.board.users || {}).length;
    const hasBoard = !!S.boardId;
    tb.innerHTML = `
        <button class="brand" data-act="overview" title="All boards">${LOGO}<span>Beckon</span></button>
        <div class="tb-sep"></div>
        <div style="position:relative;min-width:0" id="switch-wrap">
            <button class="board-btn" data-act="switcher" title="Switch board"><h1>${esc(hasBoard ? S.board.title : 'No board')}</h1>${icon('chev-down', 'sm')}</button>
        </div>
        ${hasBoard ? `<button class="ibtn tb-desktop" data-act="settings" title="Board settings">${icon('pencil', 'sm')}</button>` : ''}
        <span class="sync tb-desktop"></span>
        <div class="tb-right">
            <div class="tb-desktop" style="display:flex;gap:6px;align-items:center">
                <button class="btn" data-act="search" title="Search (/)">${icon('search', 'sm')} Search <kbd>/</kbd></button>
                <label class="btn" title="Import a Trello JSON export">${icon('upload', 'sm')} Import <input type="file" accept=".json" class="hidden" data-import-file></label>
                ${hasBoard ? `<div style="position:relative" id="archive-wrap"><button class="btn" data-act="archive">${icon('archive', 'sm')} Archive ${archived ? `<span class="count">${archived}</span>` : ''}</button></div>
                <button class="btn" data-act="users">${icon('users', 'sm')} Users ${users ? `<span class="count">${users}</span>` : ''}</button>` : ''}
                <div class="tb-sep"></div>
                <button class="ibtn" data-act="theme" title="Toggle light / dark. Right-click for system">${icon(isDark() ? 'sun' : 'moon')}</button>
                <div style="position:relative" id="identity-wrap"><button class="ibtn identity-btn" data-act="identity" title="Your identity" style="padding:0;border-radius:50%">${meAvatar()}</button></div>
                <button class="btn primary" data-act="new-board">${icon('plus', 'sm')} New board</button>
            </div>
            <div class="tb-mobile">
                <button class="ibtn" data-act="search" aria-label="Search">${icon('search')}</button>
                <button class="ibtn" data-act="mobile-menu" aria-label="Menu">${icon('menu')}</button>
            </div>
        </div>`;
    setSync(S.sync);
}
function mobileMenuHtml() {
    const archived = (S.board.archive || []).length, users = Object.keys(S.board.users || {}).length;
    return `
        <div class="mobile-menu">
            <div class="sync" style="display:inline-flex;margin:4px 8px 8px"></div>
            <label>${icon('upload')} Import Trello JSON<input type="file" accept=".json" class="hidden" data-import-file></label>
            ${S.boardId ? `<button data-act="archive-m">${icon('archive')} Archive ${archived ? `<span class="count pill">${archived}</span>` : ''}</button>
            <button data-act="users">${icon('users')} Users ${users ? `<span class="count pill">${users}</span>` : ''}</button>
            <button data-act="settings">${icon('pencil')} Board settings</button>` : ''}
            <button data-act="identity-m">${icon('user')} Your identity</button>
            <button data-act="theme">${icon(isDark() ? 'sun' : 'moon')} ${isDark() ? 'Light mode' : 'Dark mode'}</button>
            ${themeMode() !== 'system' ? `<button data-act="theme-system">${icon('monitor')} Follow system theme</button>` : ''}
            <div class="divider"></div>
            <button data-act="new-board" style="color:var(--accent-ink);font-weight:700">${icon('plus')} New board</button>
        </div>`;
}
function bindTopbar() {
    const tb = document.getElementById('topbar');
    on(tb, 'click', '[data-act]', (e, t) => {
        const act = t.dataset.act;
        if (act === 'overview') openOverview();
        else if (act === 'switcher') openSwitcher();
        else if (act === 'settings') { closePop(); openBoardSettings(); }
        else if (act === 'search') { closePop(); openSearch(); }
        else if (act === 'archive') openArchivePop();
        else if (act === 'archive-m') { closePop(); openArchiveModal(); }
        else if (act === 'users') { closePop(); openUsersModal(); }
        else if (act === 'theme') toggleTheme();
        else if (act === 'theme-system') { closePop(); setTheme('system'); }
        else if (act === 'identity') openIdentityPop();
        else if (act === 'identity-m') { closePop(); openIdentityModal(); }
        else if (act === 'new-board') { closePop(); openCreateBoard(); }
        else if (act === 'mobile-menu') { if (S.ui.mobileMenu) { closePop(); } else { closePop(); tb.insertAdjacentHTML('beforeend', mobileMenuHtml()); S.ui.mobileMenu = true; S.ui.pop = 'mobile'; t.innerHTML = icon('close'); setSync(S.sync); } }
    });
    tb.addEventListener('contextmenu', (e) => { const t = e.target.closest('[data-act="theme"]'); if (t) { e.preventDefault(); showThemeCtx(e.clientX, e.clientY); } });
    on(tb, 'change', '[data-import-file]', (e, t) => { handleImportFile(t.files[0]); t.value = ''; closePop(); });
}
function openSwitcher() {
    const pop = openPop('switch-wrap', 'switcher', `
        <div class="pop-search"><input class="field" placeholder="Find a board…" data-q></div>
        <div class="pop-list scroll" data-list></div>
        <div class="pop-foot"><button class="pop-item accent" data-create>${icon('plus', 'sm')} Create new board</button></div>`, { focus: '[data-q]' });
    if (!pop) return;
    const draw = (q = '') => {
        const list = S.boards.filter((b) => !q || b.name.toLowerCase().includes(q) || b.id.toLowerCase().includes(q));
        $('[data-list]', pop).innerHTML = `<div class="pop-head">Boards</div>` + (list.length ? list.map((b) => `<button class="pop-item" data-board="${esc(b.id)}"><span class="grow">${esc(b.name)}<span class="sub">${esc(b.id)}</span></span>${b.id === S.boardId ? `<span class="dot ok"></span>` : ''}</button>`).join('') : `<div class="empty">No boards match.</div>`);
    };
    draw();
    $('[data-q]', pop).addEventListener('input', (e) => draw(e.target.value.trim().toLowerCase()));
    $('[data-q]', pop).addEventListener('keydown', (e) => { if (e.key === 'Enter') { const first = $('[data-board]', pop); if (first) selectBoard(first.dataset.board); } });
    on(pop, 'click', '[data-board]', (e, t) => selectBoard(t.dataset.board));
    on(pop, 'click', '[data-create]', () => { closePop(); openCreateBoard(); });
}
function archiveListHtml(q = '') {
    const list = (S.board.archive || []).filter((c) => !q || (c.title || '').toLowerCase().includes(q));
    if (!list.length) return `<div class="empty">${ART.inbox}<div>${(S.board.archive || []).length ? 'No matches.' : 'Archive is empty.'}</div></div>`;
    return list.map((c) => `<button class="pop-item" data-archived="${esc(c.id)}"><span class="grow"><b style="font-weight:600">${esc(c.title)}</b><span class="sub">${(c.labels || []).map((l) => esc(nameOf(l))).join(', ') || 'Archived ' + (c.created_at ? timeAgo(c.created_at) : '')}</span></span>${icon('chev-right', 'sm')}</button>`).join('');
}
function openArchivedCard(id) { const idx = (S.board.archive || []).findIndex((c) => String(c.id) === String(id)); if (idx > -1) openCard('archive', idx); }
function openArchivePop() {
    const pop = openPop('archive-wrap', 'archive', `
        <div class="pop-search"><input class="field" placeholder="Search archived cards…" data-q></div>
        <div class="pop-list scroll" data-list>${archiveListHtml()}</div>`, { right: true, focus: '[data-q]' });
    if (!pop) return;
    pop.style.width = '340px';
    $('[data-q]', pop).addEventListener('input', (e) => { $('[data-list]', pop).innerHTML = archiveListHtml(e.target.value.trim().toLowerCase()); });
    on(pop, 'click', '[data-archived]', (e, t) => { closePop(); openArchivedCard(t.dataset.archived); });
}
function openArchiveModal() {
    const el = openLayer('archive', `<div class="win">${winHead('Archived cards', 'archive')}<div class="pop-search"><input class="field" placeholder="Search archived cards…" data-q></div><div class="pop-list scroll" data-list style="max-height:none;flex:1">${archiveListHtml()}</div></div>`, { focus: '[data-q]' });
    bindClose(el);
    $('[data-q]', el).addEventListener('input', (e) => { $('[data-list]', el).innerHTML = archiveListHtml(e.target.value.trim().toLowerCase()); });
    on(el, 'click', '[data-archived]', (e, t) => { closeLayer('archive'); openArchivedCard(t.dataset.archived); });
}
function identityFormHtml() {
    return `<span class="label">Your identity</span>
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">${meAvatar('lg')}<input class="field" value="${esc(S.user.name)}" placeholder="Your name" data-name></div>
        <span class="label">Avatar color</span>
        <div class="color-grid">${LABEL_COLORS.map((c) => `<button class="swatch bg-${c} ${S.user.color === c ? 'on' : ''}" data-color="${c}" aria-label="${c}"></button>`).join('')}</div>`;
}
function bindIdentity(root) {
    $('[data-name]', root).addEventListener('input', (e) => { S.user.name = e.target.value; saveIdentity(); $$('.avatar[data-color]').forEach((a) => { a.textContent = S.user.initials; }); });
    on(root, 'click', '[data-color]', (e, t) => { S.user.color = t.dataset.color; saveIdentity(); $$('.swatch', root).forEach((s) => s.classList.toggle('on', s.dataset.color === t.dataset.color)); $$('.avatar[data-color]').forEach((a) => { a.className = a.className.replace(/bg-\w+/, 'bg-' + S.user.color); }); });
}
function openIdentityPop() { const pop = openPop('identity-wrap', 'identity', `<div class="pop-panel">${identityFormHtml()}</div>`, { right: true }); if (pop) { pop.style.width = '280px'; bindIdentity(pop); } }
function openIdentityModal() { const el = openLayer('identity', `<div class="win ctxwin">${winHead('Your identity', 'identity')}<div class="win-body">${identityFormHtml()}</div></div>`); bindClose(el); bindIdentity(el); }

/* ---------- Boards ---------- */
async function fetchBoards() { try { S.boards = (await api('list_boards')).boards || []; } catch (e) {} }
async function switchBoard() {
    if (S.boardId) { try { localStorage.setItem('beckon_last_board', S.boardId); } catch (e) {} }
    await loadData();
    renderTopbar(); renderBoard(); connectSSE();
    const url = new URL(location.href); if (S.boardId) url.searchParams.set('board', S.boardId); else url.searchParams.delete('board'); history.replaceState({}, '', url);
}
async function selectBoard(id) { closePop(); closeOverview(); if (id === S.boardId) return; S.boardId = id; await switchBoard(); }
function openCreateBoard() {
    const el = openLayer('create-board', `<div class="win ctxwin">${winHead('Create a board', 'create-board')}
        <div class="win-body"><span class="label">Board title</span><input class="field" placeholder="e.g. Project Alpha" data-title></div>
        <div class="win-foot"><button class="btn" data-close="create-board">Cancel</button><button class="btn primary" data-ok>Create board</button></div></div>`, { focus: '[data-title]' });
    bindClose(el);
    const go = async () => {
        const title = $('[data-title]', el).value.trim(); if (!title) return;
        const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
        try { const res = await api('create_board', { title, slug }); closeLayer('create-board'); closeOverview(); await fetchBoards(); S.boardId = res.id || slug; await switchBoard(); toast(`Board "${title}" created`); }
        catch (e) { toast(e.message, 'err'); }
    };
    $('[data-ok]', el).addEventListener('click', go);
    $('[data-title]', el).addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
}
function openBoardSettings() {
    const el = openLayer('board-settings', `<div class="win ctxwin">${winHead('Board settings', 'board-settings')}
        <div class="win-body">
            <div class="form-row"><span class="label">Board name</span><div style="display:flex;gap:8px"><input class="field" value="${esc(S.board.title)}" data-title><button class="btn primary" data-save>Save</button></div>
            <div class="help" style="margin-top:6px">Folder: <code>boards/${esc(S.boardId)}</code>. Renaming can change the folder name.</div></div>
            <div class="danger-zone"><h4>${icon('warning', 'sm')} Danger zone</h4><p>Permanently delete this board and every card in it. There is no undo.</p><button class="btn danger" data-delete>${icon('trash', 'sm')} Delete board</button></div>
        </div></div>`, { focus: '[data-title]' });
    bindClose(el);
    const save = async () => {
        const title = $('[data-title]', el).value.trim(); if (!title) return;
        try {
            const res = await api('rename_board', { board: S.boardId, title });
            S.board.title = res.name;
            if (res.id !== S.boardId) { const old = S.boardId; S.boardId = res.id; const store = lsRaw(`beckon_${old}`); if (store) { localStorage.setItem(`beckon_${res.id}`, store); localStorage.removeItem(`beckon_${old}`); } }
            await fetchBoards(); closeLayer('board-settings'); await switchBoard(); toast('Board renamed');
        } catch (e) { toast(e.message, 'err'); }
    };
    $('[data-save]', el).addEventListener('click', save);
    $('[data-title]', el).addEventListener('keydown', (e) => { if (e.key === 'Enter') save(); });
    $('[data-delete]', el).addEventListener('click', async () => {
        if (!await dialog.confirm({ title: `Delete "${S.board.title}"?`, message: 'Every list, card, comment and upload on this board will be removed for good.', ok: 'Delete board', danger: true })) return;
        try {
            await api('delete_board', { board: S.boardId }); closeLayer('board-settings'); await fetchBoards();
            if (S.boards.length) { S.boardId = S.boards[0].id; await switchBoard(); } else { S.boardId = ''; S.board = { title: 'No boards', lists: [], archive: [], users: {} }; renderTopbar(); renderBoard(); openOverview(); }
            toast('Board deleted');
        } catch (e) { toast(e.message, 'err'); }
    });
}

/* ---------- Overview (all boards) ---------- */
function openOverview() {
    closeOverview(); closePop();
    const el = document.createElement('div'); el.className = 'overview'; el.id = 'overview';
    el.innerHTML = `
        <div class="overview-head">${S.boards.length ? `<button class="ibtn" data-ov-close aria-label="Close">${icon('close', 'lg')}</button>` : ''}</div>
        <div class="overview-body scroll"><div class="overview-inner">
            <h1>Your boards</h1><p class="lead">${S.boards.length ? 'Pick a board or start a new one.' : 'Welcome aboard. Create your first board to begin.'}</p>
            <div class="ov-grid">
                <button class="ov-card new" data-ov-new>${icon('plus', 'lg')} Create new board</button>
                ${S.boards.map((b) => `<button class="ov-card ${b.id === S.boardId ? 'on' : ''}" data-ov-board="${esc(b.id)}">${ART.compass.replace('<svg', '<svg class="ov-art"')}<b>${esc(b.name)}</b><small>${esc(b.id)}</small></button>`).join('')}
            </div>
        </div></div>
        <div class="overview-foot">
            <a href="https://beckon.run/changelog" target="_blank" rel="noopener">${LOGO.replace('class="mark"', 'class="mark" style="width:18px;height:18px;border-radius:4px"')} v${esc(VERSION)}</a><span>·</span>
            <a href="https://github.com/austinginder/beckon" target="_blank" rel="noopener">GitHub</a>
            ${S.update.update_available ? `<span>·</span><button class="update" data-ov-update>${icon('download', 'sm')} Update to v${esc(S.update.latest)}</button>` : `<span>·</span><a href="#" data-ov-check title="${S.update.error ? esc('Last check failed: ' + S.update.error) : (S.update.last_check ? 'Checked ' + esc(timeAgo(new Date(S.update.last_check * 1000).toISOString())) : '')}">${S.update.error ? icon('warning', 'sm') + ' ' : ''}Check for updates</a>`}
            ${S.update.can_rollback ? `<span>·</span><a href="#" data-ov-rollback title="Restore the copy saved before the last update">Restore v${esc(S.update.previous_version || 'previous')}</a>` : ''}
        </div>`;
    document.body.appendChild(el);
    on(el, 'click', '[data-ov-close]', closeOverview);
    on(el, 'click', '[data-ov-new]', openCreateBoard);
    on(el, 'click', '[data-ov-board]', (e, t) => selectBoard(t.dataset.ovBoard));
    on(el, 'click', '[data-ov-update]', openUpdateDialog);
    on(el, 'click', '[data-ov-check]', async (e) => { e.preventDefault(); toast('Checking GitHub…', 'info'); try { S.update = await api('check_updates', { force: true }); openOverview(); toast(S.update.update_available ? `Beckon v${S.update.latest} is available` : S.update.error ? 'Check failed: ' + S.update.error : `You are on the latest version (v${S.update.current})`, S.update.error ? 'err' : 'ok'); } catch (x) { toast('Check failed: ' + x.message, 'err'); } });
    on(el, 'click', '[data-ov-rollback]', async (e) => { e.preventDefault(); if (!await dialog.confirm({ title: `Restore v${S.update.previous_version}?`, message: 'The current index.php (and CLI, if present) is swapped for the copy saved before the last update. Your boards are not touched.', ok: 'Restore' })) return; try { await api('update_rollback'); toast('Previous version restored. Reloading…'); setTimeout(() => location.reload(), 800); } catch (x) { toast('Restore failed: ' + x.message, 'err'); } });
}
function closeOverview() { const el = document.getElementById('overview'); if (el) el.remove(); }
function openUpdateDialog() {
    const u = S.update;
    const blocker = u.git_checkout ? 'This install is a git checkout. Update it with <code>git pull</code> instead.' : !u.writable ? 'The web server cannot write to <code>index.php</code>. Run <code>php beckon-cli.php update</code> on the server instead.' : !u.verified ? 'This release publishes no checksum for <code>index.php</code>, so Beckon will not install it automatically. Download it from GitHub instead.' : '';
    const el = openLayer('update', `<div class="win md">${winHead(`Beckon v${esc(u.latest)}`, 'update')}
        <div class="win-body scroll">
            <div class="help" style="margin-bottom:12px">${u.published_at ? `Released ${esc(timeAgo(u.published_at))}. ` : ''}You are on v${esc(u.current)}. ${u.html_url ? `<a href="${esc(u.html_url)}" target="_blank" rel="noopener">View on GitHub</a>.` : ''}</div>
            ${blocker ? `<div class="danger-zone" style="margin-bottom:12px"><h4>${icon('warning', 'sm')} Cannot install from here</h4><p>${blocker}</p></div>` : `<div class="help" style="margin-bottom:12px">${icon('lock', 'xs')} The download is verified against the checksum GitHub publishes for the release. The current file is kept in <code>boards/.updates/</code> so you can restore it.</div>`}
            <div class="md" style="font-size:13.5px;max-height:40vh;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--surface-2)">${md.render(u.notes || '') || '<p class="help">No release notes.</p>'}</div>
        </div>
        <div class="win-foot"><button class="btn" data-close="update">Not now</button>${blocker ? '' : `<button class="btn primary" data-install>${icon('download', 'sm')} Install v${esc(u.latest)}</button>`}</div></div>`);
    bindClose(el);
    const btn = $('[data-install]', el);
    if (btn) btn.addEventListener('click', async () => {
        btn.disabled = true; btn.innerHTML = `<span class="spinner"></span> Installing…`;
        try { const r = await api('perform_update'); closeLayer('update'); toast(`Updated to v${r.to}. Reloading…`); setTimeout(() => location.reload(), 900); }
        catch (x) { btn.disabled = false; btn.innerHTML = `${icon('download', 'sm')} Install v${esc(u.latest)}`; toast('Update failed: ' + x.message, 'err'); }
    });
}

/* ---------- Board ---------- */
function cardHtml(card, l, c) {
    const st = taskStats(card), labels = card.labels || [], hov = S.hover && String(S.hover.id) === String(card.id);
    const meta = [];
    if (card.hasDesc) meta.push(`<span title="Has description">${icon('text', 'xs')}</span>`);
    if (card.hasAtt) meta.push(`<span title="Has attachment">${icon('paperclip', 'xs')}</span>`);
    if (card.commentCount > 0) meta.push(`<span title="Comments">${icon('chat', 'xs')}${card.commentCount}</span>`);
    if (st.total > 0) meta.push(`<span class="${st.done === st.total ? 'done' : ''}" title="Tasks">${icon('check-circle', 'xs')}${st.done}/${st.total}<i class="mini-progress"><i style="width:${Math.round(st.done / st.total * 100)}%"></i></i></span>`);
    if (card.startDate || card.dueDate) meta.push(`<span class="chip-date ${card.dueDate ? dueClass(card.dueDate) : ''}">${icon('clock', 'xs')}${card.startDate ? fmtShort(card.startDate) : ''}${card.startDate && card.dueDate ? ' – ' : ''}${card.dueDate ? fmtShort(card.dueDate) : ''}</span>`);
    const people = (card.assignees || []).slice(0, 4).map((uid) => avatarHtml(uid, 'xs')).join('');
    return `<article class="card${hov ? ' hover' : ''}" draggable="true" data-l="${l}" data-c="${c}" data-id="${esc(card.id)}">
        ${card.coverImage ? `<div class="cover"><img src="${esc(card.coverImage)}" alt="" loading="lazy"></div>` : ''}
        <div class="card-in">
            ${labels.length ? `<div class="labels">${labels.map((lb) => `<span class="lbl bg-${esc(colorOf(lb))}">${esc(nameOf(lb))}</span>`).join('')}</div>` : ''}
            <div class="card-title">${esc(card.title)}</div>
            ${meta.length || people ? `<div class="card-meta"><div class="meta-items">${meta.join('')}</div>${people ? `<div class="assignees">${people}</div>` : ''}</div>` : ''}
        </div>
        <button class="ibtn sm card-more" data-more aria-label="Card actions">${icon('dots', 'sm')}</button>
    </article>`;
}
function renderBoard() {
    const board = document.getElementById('board');
    if (!S.boardId) { board.innerHTML = `<div class="board-empty">${ART.waves}<div>No board selected</div></div>`; return; }
    const lists = S.board.lists || [];
    board.innerHTML = lists.map((list, l) => `
        <section class="col" data-l="${l}">
            <div class="col-head" draggable="true">
                <input value="${esc(list.title)}" data-list-title aria-label="List title" spellcheck="false">
                <span class="col-count">${list.cards.length}</span>
                <button class="ibtn sm" data-list-menu aria-label="List actions">${icon('dots-h', 'sm')}</button>
            </div>
            <div class="col-body scroll" data-col-body>${list.cards.map((card, c) => cardHtml(card, l, c)).join('')}</div>
            <div class="col-foot">${S.composer === l ? `<div class="composer"><textarea rows="2" placeholder="Card title…" data-composer></textarea><div class="row"><button class="btn primary sm" data-composer-add>Add card</button><button class="ibtn sm" data-composer-cancel aria-label="Cancel">${icon('close', 'sm')}</button><span class="help" style="margin-left:auto">Enter to add</span></div></div>` : `<button class="add-card" data-add-card>${icon('plus', 'sm')} Add card</button>`}</div>
        </section>`).join('') + `<button class="add-list" data-add-list>${icon('plus', 'sm')} Add list</button><div style="width:6px;flex:none"></div>`;
    if (S.composerFocus) { S.composerFocus = false; const ta = $('[data-composer]', board); if (ta) ta.focus(); }
}
function bindBoard() {
    const board = document.getElementById('board');
    board.addEventListener('click', (e) => {
        const t = e.target;
        if (t.closest('[data-more]')) { const card = t.closest('.card'); stop(e); showCtx(e.clientX, e.clientY, +card.dataset.l, +card.dataset.c); return; }
        const card = t.closest('.card'); if (card) { openCard(+card.dataset.l, +card.dataset.c); return; }
        const add = t.closest('[data-add-card]'); if (add) { S.composer = +add.closest('.col').dataset.l; S.composerFocus = true; renderBoard(); return; }
        if (t.closest('[data-composer-add]')) { commitComposer(); return; }
        if (t.closest('[data-composer-cancel]')) { S.composer = null; renderBoard(); return; }
        if (t.closest('[data-add-list]')) { addList(); return; }
        const lm = t.closest('[data-list-menu]'); if (lm) { stop(e); showListCtx(e.clientX, e.clientY, +lm.closest('.col').dataset.l); }
    });
    board.addEventListener('focusout', () => { setTimeout(async () => { if (!S.pendingReload || S.active || (document.activeElement && document.activeElement.closest && document.activeElement.closest('#board') && /INPUT|TEXTAREA/.test(document.activeElement.tagName))) return; S.pendingReload = false; while (layoutSaving) await layoutSaving.catch(() => {}); await loadData(); renderBoard(); }, 250); });
    board.addEventListener('contextmenu', (e) => { const card = e.target.closest('.card'); if (!card) return; e.preventDefault(); if (TD.el) return; showCtx(e.clientX, e.clientY, +card.dataset.l, +card.dataset.c); });
    board.addEventListener('keydown', (e) => {
        if (e.target.matches('[data-composer]')) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commitComposer(); } if (e.key === 'Escape') { S.composer = null; renderBoard(); } }
        if (e.target.matches('[data-list-title]') && e.key === 'Enter') e.target.blur();
    });
    board.addEventListener('change', (e) => { if (e.target.matches('[data-list-title]')) { const l = +e.target.closest('.col').dataset.l; S.board.lists[l].title = e.target.value.trim() || 'Untitled'; e.target.value = S.board.lists[l].title; persistLayout(); } });
    board.addEventListener('mouseover', (e) => { const card = e.target.closest('.card'); if (card) { setHover(+card.dataset.l, +card.dataset.c, card); } });
    board.addEventListener('mouseout', (e) => { const card = e.target.closest('.card'); if (card && !card.contains(e.relatedTarget)) setHover(null, null, card); });
    bindTouchDrag(board);
    bindDnD(board);
}
function setHover(l, c, el) { $$('.card.hover').forEach((x) => x.classList.remove('hover')); if (l === null) { S.hover = null; return; } S.hover = { l, c, id: el.dataset.id }; el.classList.add('hover'); }
function commitComposer() {
    const ta = $('[data-composer]'); if (!ta) return;
    const title = ta.value.trim(); const l = S.composer;
    if (title) { addCard(l, title); ta.value = ''; S.composerFocus = true; renderBoard(); }
    else { S.composer = null; renderBoard(); }
}
function addList() { S.board.lists.push({ id: uid(), title: 'New list', cards: [] }); persistLayout(); renderBoard(); const inputs = $$('[data-list-title]'); const last = inputs[inputs.length - 1]; if (last) { last.focus(); last.select(); } const wrap = document.getElementById('board-wrap'); wrap.scrollLeft = wrap.scrollWidth; }
/* Where a card sits right now. Positions shift under an open dialog when the board reloads, so
   anything that awaits a confirm looks the card up again by id before changing the board. */
function locateCard(id) {
    const same = (x) => String(x.id) === String(id);
    for (let l = 0; l < S.board.lists.length; l++) { const c = S.board.lists[l].cards.findIndex(same); if (c > -1) return { l, c, card: S.board.lists[l].cards[c] }; }
    const a = (S.board.archive || []).findIndex(same); if (a > -1) return { l: 'archive', c: a, card: S.board.archive[a] };
    return null;
}
function removeCardFromBoard(id) { const at = locateCard(id); if (!at) return null; (at.l === 'archive' ? S.board.archive : S.board.lists[at.l].cards).splice(at.c, 1); return at.card; }
async function deleteList(l) {
    const list = S.board.lists[l]; if (!list) return;
    const n = list.cards.length;
    if (!await dialog.confirm({ title: `Delete "${list.title}"?`, message: n ? `${n} card${n === 1 ? '' : 's'} in this list will be deleted too.` : 'This list is empty.', ok: 'Delete list', danger: true })) return;
    const at = S.board.lists.findIndex((x) => x.id === list.id); if (at < 0) return;
    const gone = S.board.lists.splice(at, 1)[0];
    gone.cards.forEach((c) => api('delete_card', { id: c.id }).catch(() => {}));
    persistLayout(); renderBoard();
}
function addCard(l, title) {
    const now = new Date().toISOString();
    const card = { id: uid(), title: title || 'New card', description: '', labels: [], dueDate: null, assignees: [], commentCount: 0, created_at: now };
    S.board.lists[l].cards.push(card);
    persistLayout(); persistCardDesc(card);
    persistMeta(card.id, { created_at: now, comments: [], activity: [{ text: 'Card created', date: now }], revisions: [], assigned_to: [], checklists: [] });
    return card;
}
function cloneCard(l, c) {
    const original = S.board.lists[l].cards[c];
    const copy = JSON.parse(JSON.stringify(original)); copy.id = uid(); copy.title += ' (copy)';
    S.board.lists[l].cards.splice(c + 1, 0, copy); persistLayout();
    api('get_card', { id: original.id }).then((res) => { copy.description = res.description; persistCardDesc(copy); const meta = res.meta || {}; persistMeta(copy.id, { comments: [], activity: [{ text: 'Card duplicated', date: new Date().toISOString() }], revisions: [], assigned_to: copy.assignees || [], checklists: JSON.parse(JSON.stringify(meta.checklists || [])), title: copy.title, labels: copy.labels || [] }); }).catch(() => {});
    renderBoard(); toast('Card duplicated');
}
function logActivity(cardId, text) { api('load_card_meta', { id: cardId }).then((m) => { m.activity = m.activity || []; m.activity.unshift({ text, date: new Date().toISOString() }); persistMeta(cardId, m); }).catch(() => {}); }
async function archiveCard(l, c, skipConfirm = false) {
    const card = S.board.lists[l].cards[c]; if (!card) return;
    if (!skipConfirm && !await dialog.confirm({ title: `Archive "${card.title}"?`, message: 'You can restore it later from the archive.', ok: 'Archive' })) return;
    const live = removeCardFromBoard(card.id); if (!live) return;
    S.board.archive = S.board.archive || []; S.board.archive.unshift(live);
    persistLayout(); logActivity(card.id, 'Archived'); S.hover = null; renderBoard(); renderTopbar(); toast('Card archived');
}
async function deleteCard(l, c) {
    const card = S.board.lists[l].cards[c]; if (!card) return;
    if (!await dialog.confirm({ title: `Delete "${card.title}"?`, message: 'The card, its description, comments and history will be removed permanently.', ok: 'Delete card', danger: true })) return;
    if (!removeCardFromBoard(card.id)) return; persistLayout(); api('delete_card', { id: card.id }).catch(() => {}); S.hover = null; renderBoard(); toast('Card deleted');
}

/* ---------- Drag and drop ---------- */
const dropLine = document.createElement('div'); dropLine.className = 'drop-line';
/* Hit-test shared by mouse (dragover) and touch: works out S.dropTarget and places the drop line. */
function dragOverAt(el, clientY) {
    const col = el && el.closest && el.closest('.col'); if (!col || !S.drag) return false;
    const l = +col.dataset.l;
    if (S.drag.type === 'list') {
        $$('.col.drop-before, .col.drop-after').forEach((x) => x.classList.remove('drop-before', 'drop-after'));
        if (l === S.drag.l) { S.dropTarget = null; return true; }
        col.classList.add(l < S.drag.l ? 'drop-before' : 'drop-after'); S.dropTarget = { type: 'list', l }; return true;
    }
    const body = $('[data-col-body]', col), cardEl = el.closest('.card');
    if (cardEl && !cardEl.classList.contains('dragging')) {
        const r = cardEl.getBoundingClientRect(); const before = clientY < r.top + r.height / 2;
        body.insertBefore(dropLine, before ? cardEl : cardEl.nextSibling);
        S.dropTarget = { type: 'card', l, c: +cardEl.dataset.c, pos: before ? 'top' : 'bottom' };
    } else if (!cardEl) { body.appendChild(dropLine); S.dropTarget = { type: 'card', l, c: null, pos: 'bottom' }; }
    return true;
}

/* Touch: hold a card (or a list's header) to pick it up, then drag. Holding without moving
   opens the card menu, as before. A quick swipe still scrolls the board. */
const TD = { el: null, handle: null, kind: null, timer: null, armed: false, dragging: false, sx: 0, sy: 0, x: 0, y: 0, ghost: null, ox: 0, oy: 0, raf: 0 };
function bindTouchDrag(board) {
    const wrap = document.getElementById('board-wrap');
    const reset = () => {
        clearTimeout(TD.timer); cancelAnimationFrame(TD.raf);
        if (TD.el) TD.el.classList.remove('pressing', 'lifted', 'dragging');
        if (TD.handle) TD.handle.setAttribute('draggable', 'true');
        if (TD.ghost) TD.ghost.remove();
        wrap.classList.remove('touch-dragging');
        Object.assign(TD, { el: null, handle: null, kind: null, timer: null, armed: false, dragging: false, ghost: null });
    };
    const hitTest = () => { dragOverAt(document.elementFromPoint(TD.x, TD.y), TD.y); };
    const autoScroll = () => {
        if (!TD.dragging) return;
        const wr = wrap.getBoundingClientRect(); let moved = false;
        if (TD.x < wr.left + 36) { wrap.scrollLeft -= 14; moved = true; } else if (TD.x > wr.right - 36) { wrap.scrollLeft += 14; moved = true; }
        const under = document.elementFromPoint(TD.x, TD.y); const body = under && under.closest && under.closest('[data-col-body]');
        if (body) { const br = body.getBoundingClientRect(); if (TD.y < br.top + 36) { body.scrollTop -= 12; moved = true; } else if (TD.y > br.bottom - 36) { body.scrollTop += 12; moved = true; } }
        if (moved) hitTest();
        TD.raf = requestAnimationFrame(autoScroll);
    };
    const startDrag = () => {
        TD.dragging = true; wrap.classList.add('touch-dragging');
        if (TD.kind === 'card') S.drag = { type: 'card', l: +TD.el.dataset.l, c: +TD.el.dataset.c };
        else S.drag = { type: 'list', l: +TD.el.dataset.l };
        const src = TD.kind === 'card' ? TD.el : TD.handle; const r = src.getBoundingClientRect();
        TD.ghost = src.cloneNode(true); TD.ghost.classList.remove('pressing', 'lifted', 'hover'); TD.ghost.classList.add('touch-ghost');
        if (TD.kind === 'list') { TD.ghost.style.background = 'var(--surface-2)'; TD.ghost.style.borderRadius = 'var(--r-lg)'; TD.ghost.style.border = '1px solid var(--line)'; }
        TD.ghost.style.width = r.width + 'px'; TD.ox = TD.sx - r.left; TD.oy = TD.sy - r.top;
        document.body.appendChild(TD.ghost);
        TD.el.classList.remove('lifted'); TD.el.classList.add('dragging');
        TD.raf = requestAnimationFrame(autoScroll);
    };
    board.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1 || TD.el) return;
        const card = e.target.closest('.card'); const head = !card && e.target.closest('.col-head');
        if (!card && !head) return;
        if (head && e.target.closest('input, button')) return;
        const t = e.touches[0];
        Object.assign(TD, { el: card || head.closest('.col'), handle: card || head, kind: card ? 'card' : 'list', sx: t.clientX, sy: t.clientY, x: t.clientX, y: t.clientY, armed: false, dragging: false });
        TD.handle.setAttribute('draggable', 'false'); // keep the browser's own drag out of this
        if (card) card.classList.add('pressing');
        TD.timer = setTimeout(() => { if (!TD.el) return; TD.armed = true; TD.el.classList.remove('pressing'); TD.el.classList.add('lifted'); if (navigator.vibrate) navigator.vibrate(10); }, 350);
    }, { passive: true });
    board.addEventListener('touchmove', (e) => {
        if (!TD.el) return;
        const t = e.touches[0]; TD.x = t.clientX; TD.y = t.clientY;
        const moved = Math.hypot(TD.x - TD.sx, TD.y - TD.sy) > 8;
        if (!TD.armed) { if (moved) reset(); return; } // it was a scroll
        e.preventDefault();
        if (!TD.dragging && moved) startDrag();
        if (TD.dragging) { TD.ghost.style.left = (TD.x - TD.ox) + 'px'; TD.ghost.style.top = (TD.y - TD.oy) + 'px'; hitTest(); }
    }, { passive: false });
    board.addEventListener('touchend', (e) => {
        if (!TD.el) return;
        if (TD.dragging) { e.preventDefault(); const had = S.dropTarget; if (had) onDrop(); else { S.drag = null; dropLine.remove(); $$('.col.drop-before, .col.drop-after').forEach((x) => x.classList.remove('drop-before', 'drop-after')); renderBoard(); } }
        else if (TD.armed) { e.preventDefault(); if (TD.kind === 'card') showCtx(TD.x, TD.y, +TD.el.dataset.l, +TD.el.dataset.c); }
        reset();
    });
    board.addEventListener('touchcancel', () => { if (TD.dragging) { S.drag = null; S.dropTarget = null; dropLine.remove(); renderBoard(); } reset(); });
}
function bindDnD(board) {
    board.addEventListener('dragstart', (e) => {
        const card = e.target.closest('.card'), head = e.target.closest('.col-head');
        if (card) { S.drag = { type: 'card', l: +card.dataset.l, c: +card.dataset.c }; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', card.dataset.id); } catch (x) {} setTimeout(() => card.classList.add('dragging'), 0); }
        else if (head) {
            if (['INPUT', 'BUTTON', 'svg', 'path'].includes(e.target.tagName)) { e.preventDefault(); return; }
            const col = head.closest('.col'); S.drag = { type: 'list', l: +col.dataset.l }; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setDragImage(col, 30, 20); } catch (x) {} setTimeout(() => col.classList.add('dragging'), 0);
        }
    });
    board.addEventListener('dragover', (e) => {
        if (!S.drag || !e.target.closest('.col')) return;
        e.preventDefault(); e.dataTransfer.dropEffect = 'move';
        dragOverAt(e.target, e.clientY);
    });
    board.addEventListener('drop', (e) => { e.preventDefault(); onDrop(); });
    board.addEventListener('dragend', () => { S.drag = null; S.dropTarget = null; dropLine.remove(); renderBoard(); });
}
function onDrop() {
    const d = S.drag, t = S.dropTarget; if (!d || !t) return;
    if (d.type === 'list' && t.type === 'list') { if (d.l !== t.l) { const [list] = S.board.lists.splice(d.l, 1); S.board.lists.splice(t.l, 0, list); persistLayout(); } }
    else if (d.type === 'card' && t.type === 'card') {
        const card = S.board.lists[d.l].cards[d.c]; if (!card) return;
        S.board.lists[d.l].cards.splice(d.c, 1);
        let idx = t.c === null ? S.board.lists[t.l].cards.length : t.c;
        if (d.l === t.l && t.c !== null && d.c < t.c) idx--;
        if (t.pos === 'bottom' && t.c !== null) idx++;
        S.board.lists[t.l].cards.splice(idx, 0, card);
        if (d.l !== t.l) logActivity(card.id, `Moved to ${S.board.lists[t.l].title}`);
        persistLayout();
    }
    S.drag = null; S.dropTarget = null; dropLine.remove(); renderBoard();
}

/* ---------- Context menus ---------- */
function closeCtx() { const c = $('.ctx'); if (c) c.remove(); }
function ctxAt(x, y, html) {
    closeCtx(); closePop();
    const el = document.createElement('div'); el.className = 'ctx'; el.innerHTML = html; document.body.appendChild(el);
    const r = el.getBoundingClientRect();
    el.style.left = clamp(x, 8, window.innerWidth - r.width - 8) + 'px'; el.style.top = clamp(y, 8, window.innerHeight - r.height - 8) + 'px';
    return el;
}
function showCtx(x, y, l, c) {
    const card = S.board.lists[l] && S.board.lists[l].cards[c]; if (!card) return;
    const el = ctxAt(x, y, `
        <button data-x="open">${icon('pencil', 'sm')} Open card</button>
        <button data-x="move">${icon('move', 'sm')} Move…</button>
        <button data-x="wp">${icon('send', 'sm')} Publish to WordPress</button>
        <button data-x="cover">${icon('image', 'sm')} Change cover</button>
        ${card.coverImage ? `<button data-x="uncover">${icon('close', 'sm')} Remove cover</button>` : ''}
        <button data-x="dup">${icon('duplicate', 'sm')} Duplicate</button>
        <button data-x="archive">${icon('archive', 'sm')} Archive</button>
        <div class="divider"></div>
        <button class="danger" data-x="delete">${icon('trash', 'sm')} Delete</button>`);
    on(el, 'click', '[data-x]', (e, t) => {
        const a = t.dataset.x; closeCtx();
        if (a === 'open') openCard(l, c); else if (a === 'move') openMoveModal(l, c); else if (a === 'wp') prepareWpPublish(l, c);
        else if (a === 'cover') openCoverModal(l, c); else if (a === 'uncover') { card.coverImage = null; persistLayout(); renderBoard(); }
        else if (a === 'dup') cloneCard(l, c); else if (a === 'archive') archiveCard(l, c); else if (a === 'delete') deleteCard(l, c);
    });
}
function showListCtx(x, y, l) {
    const el = ctxAt(x, y, `<button data-x="add">${icon('plus', 'sm')} Add card</button><button data-x="rename">${icon('pencil', 'sm')} Rename list</button><div class="divider"></div><button class="danger" data-x="delete">${icon('trash', 'sm')} Delete list</button>`);
    on(el, 'click', '[data-x]', (e, t) => {
        const a = t.dataset.x; closeCtx();
        if (a === 'add') { S.composer = l; S.composerFocus = true; renderBoard(); }
        else if (a === 'rename') { const inp = $(`.col[data-l="${l}"] [data-list-title]`); if (inp) { inp.focus(); inp.select(); } }
        else if (a === 'delete') deleteList(l);
    });
}

/* ---------- Move / cover modals ---------- */
function openMoveModal(l, c) {
    const card = S.board.lists[l].cards[c]; if (!card) return;
    const el = openLayer('move', `<div class="win ctxwin">${winHead('Move card', 'move')}
        <div class="win-body">
            <div class="form-row"><span class="label">Board</span>${comboHtml('board', 'Choose a board…')}</div>
            <div class="form-row"><span class="label">List</span>${comboHtml('list', 'Choose a list…')}<div class="help hidden" data-note>The card will land at the top of the first list on that board.</div></div>
        </div>
        <div class="win-foot"><button class="btn" data-close="move">Cancel</button><button class="btn primary" data-ok>Move card</button></div></div>`);
    bindClose(el);
    const note = $('[data-note]', el);
    const listCombo = bindCombo($('[data-combo="list"]', el), { options: [], empty: 'No lists on that board' });
    const fill = async () => {
        const bid = boardCombo.value;
        let lists = bid === S.boardId ? S.board.lists : [];
        if (bid !== S.boardId) { try { lists = (await api('get_board_lists', { board_id: bid })).lists || []; } catch (e) { lists = []; } }
        const cur = bid === S.boardId ? S.board.lists[l].id : (lists[0] ? lists[0].id : '');
        listCombo.setOptions(lists.map((x) => ({ value: x.id, label: x.title })), cur);
        $('[data-combo="list"]', el).classList.toggle('hidden', !lists.length); note.classList.toggle('hidden', !!lists.length);
    };
    const boardCombo = bindCombo($('[data-combo="board"]', el), { options: S.boards.map((b) => ({ value: b.id, label: b.name, sub: b.id === S.boardId ? 'this board' : '' })), value: S.boardId, onPick: fill });
    fill();
    $('[data-ok]', el).addEventListener('click', async () => {
        const bid = boardCombo.value, lid = listCombo.value;
        try {
            if (bid === S.boardId) {
                const tl = S.board.lists.findIndex((x) => x.id === lid);
                if (tl > -1 && tl !== l) { S.board.lists[l].cards.splice(c, 1); S.board.lists[tl].cards.push(card); persistLayout(); logActivity(card.id, `Moved to ${S.board.lists[tl].title}`); }
            } else {
                await api('move_card_to_board', { id: card.id, target_board: bid, target_list_id: lid || null });
                S.board.lists[l].cards.splice(c, 1); persistLayout();
            }
            closeLayer('move'); renderBoard(); toast('Card moved');
        } catch (e) { toast('Move failed: ' + e.message, 'err'); }
    });
}
async function openCoverModal(l, c) {
    const card = S.board.lists[l].cards[c]; if (!card) return;
    let files = [];
    try { files = (await api('list_uploads')).files || []; } catch (e) { toast('Could not load uploads', 'err'); return; }
    const el = openLayer('cover', `<div class="win md" style="height:min(70vh,640px)">${winHead('Cover image', 'cover')}
        <div class="win-body scroll dropzone" data-zone style="flex:1">
            <label class="dash-btn" style="margin-bottom:14px">${icon('image')} Upload an image<input type="file" accept="image/*" class="hidden" data-file></label>
            <div class="cover-grid" data-grid></div>
        </div></div>`);
    bindClose(el);
    const grid = $('[data-grid]', el), zone = $('[data-zone]', el);
    const draw = () => { grid.innerHTML = files.length ? files.map((f) => `<div class="cover-tile" data-src="${esc(f)}"><img src="${esc(f)}" alt="" loading="lazy">${card.coverImage === f ? `<span class="tick">${icon('check', 'sm')}</span>` : ''}</div>`).join('') : `<div class="empty" style="grid-column:1/-1">${ART.inbox}<div>No images uploaded to this board yet.</div></div>`; };
    draw();
    const upload = async (file) => { if (!file || !file.type.startsWith('image/')) return; try { const res = await apiUpload('upload', file); if (res.url) { files.unshift(res.url); draw(); } } catch (e) { toast('Upload failed', 'err'); } };
    $('[data-file]', el).addEventListener('change', (e) => upload(e.target.files[0]));
    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('over'));
    zone.addEventListener('drop', (e) => { e.preventDefault(); zone.classList.remove('over'); upload(e.dataTransfer.files[0]); });
    on(grid, 'click', '[data-src]', (e, t) => { card.coverImage = t.dataset.src; persistLayout(); closeLayer('cover'); renderBoard(); toast('Cover updated'); });
}

/* ---------- Card window ---------- */
let descTimer = null, previewTimer = null;
const cwEl = () => layerEl('card');
async function openCard(l, c) {
    const card = l === 'archive' ? S.board.archive[c] : (S.board.lists[l] && S.board.lists[l].cards[c]);
    if (!card) return;
    closePop(); closeCtx();
    S.active = { l, c, card, meta: { comments: [], activity: [], revisions: [], assigned_to: [], checklists: [] }, original: card.description || '', loading: !!card.id };
    S.ui.revision = -1; S.ui.editingComment = null; S.ui.actMax = false;
    renderCardWindow();
    if (!card.id) return;
    try {
        const res = await api('get_card', { id: card.id });
        if (!S.active || S.active.card !== card) return;
        card.description = res.description || '';
        S.active.original = card.description;
        S.active.meta = Object.assign({ comments: [], activity: [], revisions: [], assigned_to: [], checklists: [] }, res.meta || {});
        S.active.loading = false;
        const ta = $('#cw-desc'); if (ta) { ta.value = card.description; ta.readOnly = false; }
        cwUpdatePreview(); cwUpdateStats(); cwRenderActivity(); cwRenderSide(); cwUpdateHead();
    } catch (e) { if (S.active && S.active.card === card) { S.active.failed = true; toast('Could not load this card. It is read-only until you reopen it.', 'err'); } }
}
function closeCard() {
    const a = S.active; if (!a) return;
    clearTimeout(descTimer); clearTimeout(previewTimer);
    if (!a.loading) {
        if ((a.card.description || '') !== (a.original || '')) {
            a.meta.revisions = a.meta.revisions || [];
            a.meta.revisions.unshift({ id: uid(), date: new Date().toISOString(), text: a.original || '', user: S.user.name });
            if (a.meta.revisions.length > 50) a.meta.revisions = a.meta.revisions.slice(0, 50);
            a.meta.activity.unshift({ text: 'Modified description', date: new Date().toISOString() });
            persistMeta(a.card.id, a.meta);
        }
        persistCardDesc(a.card);
    }
    persistLayout();
    S.active = null; closeLayer('card'); acClose(); renderBoard(); renderTopbar();
}
function renderCardWindow() {
    const a = S.active, mobile = isMobile();
    const el = openLayer('card', `
        <div class="win cw" role="dialog" aria-modal="true">
            <div class="cw-head">
                <div class="cw-title"><input id="cw-title" value="${esc(a.card.title)}" placeholder="Card title" spellcheck="false"><div class="cw-sub" id="cw-sub"></div></div>
                <div class="cw-actions">
                    <button class="btn sm hidden" id="cw-save-rev" title="Snapshot the current text as a revision">${icon('save', 'sm')}<span class="tb-desktop">Save revision</span></button>
                    <div class="seg" id="cw-view">
                        <button data-view="edit" title="Editor">${icon('edit', 'sm')}<span>Edit</span></button>
                        ${mobile ? '' : `<button data-view="split" title="Split">${icon('columns', 'sm')}<span>Split</span></button>`}
                        <button data-view="preview" title="Preview">${icon('eye', 'sm')}<span>Preview</span></button>
                    </div>
                    <button class="ibtn" data-cw="sidebar" title="Toggle details">${icon('sidebar')}</button>
                    <button class="ibtn tb-desktop" data-cw="present" title="Presentation mode">${icon('presentation')}</button>
                    <button class="ibtn danger" data-cw="close" title="Close (Esc)">${icon('close', 'lg')}</button>
                </div>
            </div>
            <div class="cw-body">
                <div class="cw-main">
                    <div class="cw-panes" id="cw-panes">
                        <div class="pane pane-editor" id="pane-editor">
                            <div class="pane-head"><span>Markdown</span><span class="stat" id="cw-stats"></span><span class="spacer"></span><label title="Insert an image">${icon('image', 'sm')} Image<input type="file" accept="image/*" class="hidden" id="cw-img"></label></div>
                            <textarea id="cw-desc" class="scroll" placeholder="Write in Markdown. Type : for emoji, - [ ] for tasks, drop an image to attach it." spellcheck="true"${a.loading ? ' readonly' : ''}>${esc(a.card.description || '')}</textarea>
                        </div>
                        <div class="pane-divider" id="pane-divider"></div>
                        <div class="pane pane-preview" id="pane-preview">
                            <div class="pane-head"><span>Preview</span><span class="spacer"></span><span class="badge hidden" id="cw-rev-badge">Previewing revision</span></div>
                            <div class="md scroll" id="cw-preview"></div>
                        </div>
                    </div>
                    <div class="cw-activity" id="cw-activity"></div>
                </div>
                <div class="side-shield hidden" id="side-shield"></div>
                <aside class="cw-side scroll" id="cw-side"></aside>
            </div>
        </div>`, { onClose: closeCard });
    cwUpdateHead(); cwApplyView(); cwUpdatePreview(); cwUpdateStats(); cwRenderActivity(); cwRenderSide();
    const ta = $('#cw-desc', el);
    ta.addEventListener('input', () => {
        const card = S.active.card; card.description = ta.value; refreshDescFlags(card); saveLocal(); setSync('saving');
        clearTimeout(descTimer); descTimer = setTimeout(() => { persistCardDesc(card); persistLayout(); setSync('synced'); }, 1000);
        clearTimeout(previewTimer); previewTimer = setTimeout(() => { if (S.ui.revision === -1) cwUpdatePreview(); }, 70);
        cwUpdateStats(); cwUpdateHead();
    });
    acAttach(ta);
    ta.addEventListener('dragover', (e) => { e.preventDefault(); ta.classList.add('over'); });
    ta.addEventListener('dragleave', () => ta.classList.remove('over'));
    ta.addEventListener('drop', (e) => { e.preventDefault(); ta.classList.remove('over'); const f = e.dataTransfer.files[0]; if (f && f.type.startsWith('image/')) cwUploadImage(f); });
    ta.addEventListener('paste', (e) => { const item = Array.from(e.clipboardData.items || []).find((i) => i.type.startsWith('image/')); if (item) { e.preventDefault(); cwUploadImage(item.getAsFile()); } });
    $('#cw-img', el).addEventListener('change', (e) => { cwUploadImage(e.target.files[0]); e.target.value = ''; });
    const title = $('#cw-title', el);
    title.addEventListener('input', () => { S.active.card.title = title.value; });
    title.addEventListener('change', () => { S.active.card.title = title.value.trim() || 'Untitled'; title.value = S.active.card.title; persistLayout(); });
    title.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); ta.focus(); } });
    on(el, 'click', '[data-view]', (e, t) => { S.ui.view = t.dataset.view; try { localStorage.setItem('beckon_view', S.ui.view); } catch (x) {} cwApplyView(); });
    on(el, 'click', '[data-cw]', (e, t) => {
        const a = t.dataset.cw;
        if (a === 'close') closeCard();
        else if (a === 'sidebar') { S.ui.sidebar = !S.ui.sidebar; try { localStorage.setItem('beckon_sidebar_open', S.ui.sidebar); } catch (x) {} cwApplyView(); }
        else if (a === 'present') openPresentation();
    });
    $('#side-shield', el).addEventListener('click', () => { S.ui.sidebar = false; cwApplyView(); });
    $('#cw-save-rev', el).addEventListener('click', manualSaveRevision);
    const prev = $('#cw-preview', el);
    prev.addEventListener('click', (e) => { if (e.target.matches('input[type="checkbox"]')) { toggleTaskAt(prev, e.target); return; } });
    prev.addEventListener('mouseup', (e) => { if (!e.target.matches('input[type="checkbox"]')) syncCursorFromPreview(prev); });
    const div = $('#pane-divider', el);
    div.addEventListener('mousedown', (e) => {
        e.preventDefault(); div.classList.add('on'); document.body.style.cursor = 'col-resize'; document.body.style.userSelect = 'none';
        const panes = $('#cw-panes', el);
        const move = (ev) => { const r = panes.getBoundingClientRect(); S.ui.ratio = clamp(((ev.clientX - r.left) / r.width) * 100, 15, 85); cwApplyView(); };
        const up = () => { div.classList.remove('on'); document.body.style.cursor = ''; document.body.style.userSelect = ''; window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); try { localStorage.setItem('beckon_ratio', S.ui.ratio); } catch (x) {} };
        window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
    });
    if (!isMobile()) setTimeout(() => ta.focus({ preventScroll: true }), 40);
}
function cwApplyView() {
    const el = cwEl(); if (!el) return;
    let view = S.ui.view; if (isMobile() && view === 'split') view = 'edit';
    $$('[data-view]', el).forEach((b) => b.classList.toggle('on', b.dataset.view === view));
    const ed = $('#pane-editor', el), pv = $('#pane-preview', el), dv = $('#pane-divider', el);
    ed.classList.toggle('hidden', view === 'preview'); pv.classList.toggle('hidden', view === 'edit'); dv.classList.toggle('hidden', view !== 'split');
    if (view === 'split') { ed.style.width = S.ui.ratio + '%'; pv.style.width = (100 - S.ui.ratio) + '%'; } else { ed.style.width = '100%'; pv.style.width = '100%'; }
    $('#cw-side', el).classList.toggle('hidden', !S.ui.sidebar);
    $('#side-shield', el).classList.toggle('hidden', !(S.ui.sidebar && window.innerWidth <= 860));
    $('[data-cw="sidebar"]', el).style.color = S.ui.sidebar ? 'var(--accent)' : '';
    const act = $('#cw-activity', el); act.classList.toggle('open', S.ui.activity && !S.ui.actMax); act.classList.toggle('max', S.ui.actMax);
    $('#cw-panes', el).classList.toggle('hidden', S.ui.actMax);
}
function cwUpdateHead() {
    const el = cwEl(), a = S.active; if (!el || !a) return;
    const sub = $('#cw-sub', el);
    if (a.l === 'archive') sub.innerHTML = `<span class="pill warn">${icon('archive', 'xs')} Archived</span><button class="btn sm" data-restore>${icon('undo', 'sm')} Restore</button>`;
    else sub.innerHTML = `in <b>${esc(S.board.lists[a.l] ? S.board.lists[a.l].title : '')}</b>${a.card.created_at ? ` · created ${timeAgo(a.card.created_at)}` : ''}`;
    const rb = $('[data-restore]', sub); if (rb) rb.addEventListener('click', restoreArchivedCard);
    const dirty = !a.loading && (a.card.description || '') !== (a.original || '');
    $('#cw-save-rev', el).classList.toggle('hidden', !dirty);
    $('#cw-rev-badge', el).classList.toggle('hidden', S.ui.revision === -1);
}
function cwUpdateStats() {
    const el = cwEl(); if (!el) return;
    const text = (S.active.card.description || '').trim(); const words = text ? text.split(/\s+/).length : 0;
    $('#cw-stats', el).textContent = words ? `${words} words · ${Math.max(1, Math.ceil(words / 200))} min read` : '';
}
function cwUpdatePreview() {
    const el = cwEl(), a = S.active; if (!el || !a) return;
    const text = S.ui.revision > -1 && a.meta.revisions[S.ui.revision] ? a.meta.revisions[S.ui.revision].text : a.card.description;
    $('#cw-preview', el).innerHTML = md.render(text || '') || `<p class="help">Nothing here yet. Start writing in the editor.</p>`;
}
function manualSaveRevision() {
    const a = S.active; if (!a) return;
    a.meta.revisions = a.meta.revisions || [];
    a.meta.revisions.unshift({ id: uid(), date: new Date().toISOString(), text: a.card.description || '', user: S.user.name });
    if (a.meta.revisions.length > 50) a.meta.revisions = a.meta.revisions.slice(0, 50);
    a.meta.activity.unshift({ text: 'Saved revision manually', date: new Date().toISOString() });
    persistMeta(a.card.id, a.meta); a.original = a.card.description || '';
    cwUpdateHead(); cwRenderActivity(); toast('Revision saved');
}
function restoreArchivedCard() {
    const a = S.active; if (!a || a.l !== 'archive') return;
    const card = S.board.archive.splice(a.c, 1)[0];
    if (!S.board.lists.length) S.board.lists.push({ id: uid(), title: 'Inbox', cards: [] });
    S.board.lists[0].cards.unshift(card); a.l = 0; a.c = 0;
    persistLayout(); a.meta.activity.unshift({ text: 'Restored from archive', date: new Date().toISOString() }); persistMeta(card.id, a.meta);
    cwUpdateHead(); cwRenderActivity(); cwRenderSide(); toast('Card restored');
}
async function cwUploadImage(file) {
    if (!file || !cardReady()) return;
    try {
        const res = await apiUpload('upload', file);
        if (!res.url) throw new Error('No URL returned');
        const ta = $('#cw-desc'); const v = ta.value; const s = ta.selectionStart, e = ta.selectionEnd;
        const ins = `${s > 0 && v[s - 1] !== '\n' ? '\n' : ''}![${esc(file.name.replace(/\.[^.]+$/, ''))}](${res.url})\n`;
        ta.value = v.slice(0, s) + ins + v.slice(e); ta.setSelectionRange(s + ins.length, s + ins.length);
        ta.dispatchEvent(new Event('input', { bubbles: true })); toast('Image added');
    } catch (e) { toast('Upload failed: ' + e.message, 'err'); }
}
function toggleTaskAt(previewEl, checkbox) {
    const ta = $('#cw-desc'); if (!ta || S.ui.revision > -1 || ta.readOnly) return;
    const li = checkbox.closest('li[data-line]'); if (!li) return;
    const lines = ta.value.split('\n'); const n = parseInt(li.dataset.line, 10);
    if (!(n >= 0 && n < lines.length)) return;
    const next = lines[n].replace(/^(\s*(?:>\s*)*(?:[-*+]|\d{1,9}[.)])\s+\[)([ xX])(\])/, (m, p, s, sf) => p + (s === ' ' ? 'x' : ' ') + sf);
    if (next === lines[n]) return;
    lines[n] = next; ta.value = lines.join('\n');
    ta.dispatchEvent(new Event('input', { bubbles: true }));
}

/* Preview click → editor caret (uses data-line source map) */
function scrollTextareaTo(ta, index) {
    const div = document.createElement('div'); const st = getComputedStyle(ta);
    ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'width', 'padding', 'border', 'boxSizing', 'whiteSpace', 'wordWrap', 'wordBreak', 'tabSize'].forEach((k) => { div.style[k] = st[k]; });
    div.style.position = 'absolute'; div.style.top = '-9999px'; div.style.left = '-9999px'; div.style.height = 'auto'; div.style.whiteSpace = 'pre-wrap';
    div.textContent = ta.value.substring(0, index); const span = document.createElement('span'); span.textContent = '|'; div.appendChild(span); document.body.appendChild(div);
    const top = span.offsetTop; div.remove();
    ta.scrollTo({ top: Math.max(0, top - ta.clientHeight / 2), behavior: 'smooth' });
}
function mapPreviewPosition(node, offset) {
    const block = (node.nodeType === 1 ? node : node.parentElement).closest('[data-line]'); if (!block) return null;
    const lineIndex = parseInt(block.getAttribute('data-line'), 10); if (isNaN(lineIndex)) return null;
    const range = document.createRange(); range.selectNodeContents(block); try { range.setEnd(node, offset); } catch (e) { return null; }
    const pre = range.toString();
    const text = S.active.card.description || ''; const lines = text.split('\n');
    const isCode = block.tagName === 'PRE';
    const vis = pre.split('\n'); const lineOffset = vis.length - 1; const col = vis[vis.length - 1].length;
    let li = Math.min(lineIndex + (isCode ? 1 : 0) + lineOffset, lines.length - 1);
    const raw = lines[li] || '';
    let sourceCol = 0;
    if (isCode) sourceCol = Math.min(col, raw.length);
    else { const rendered = vis[lineOffset] || ''; let mi = 0; for (let i = 0; i < raw.length && mi < col; i++) { const ch = raw[i]; if ('*_`[]()#>-+!~'.includes(ch)) { sourceCol++; continue; } if (ch === rendered[mi]) mi++; sourceCol++; } }
    let g = 0; for (let i = 0; i < li; i++) g += lines[i].length + 1;
    return g + sourceCol;
}
function syncCursorFromPreview(previewEl) {
    if (S.ui.revision > -1 || S.ui.view === 'preview') return;
    const sel = window.getSelection(); if (!sel || !sel.anchorNode || !previewEl.contains(sel.anchorNode)) return;
    const a = mapPreviewPosition(sel.anchorNode, sel.anchorOffset), b = mapPreviewPosition(sel.focusNode, sel.focusOffset);
    if (a === null || b === null) return;
    const ta = $('#cw-desc'); if (!ta) return;
    const start = Math.min(a, b), end = Math.max(a, b);
    ta.focus({ preventScroll: true }); ta.setSelectionRange(start, end);
    if (start === end) scrollTextareaTo(ta, start);
}

/* ---------- Sidebar ---------- */
function cwRenderSide() {
    const el = cwEl(), a = S.active; if (!el || !a) return;
    const card = a.card, meta = a.meta;
    const cls = meta.checklists || [];
    const clTotal = cls.reduce((n, c) => n + c.items.length, 0), clDone = cls.reduce((n, c) => n + c.items.filter((i) => i.state === 'complete').length, 0);
    const assignees = card.assignees || [];
    const me = S.user.id;
    const otherBoards = S.boards.filter((b) => b.id !== S.boardId);
    $('#cw-side', el).innerHTML = `<div style="display:contents">
        <div class="side-mobile-head"><span>Details</span><button class="ibtn" data-side-close aria-label="Close">${icon('close')}</button></div>
        <div class="side-in">
            <div class="side-meta">${card.created_at ? `Created <b title="${esc(fmtFull(card.created_at))}">${timeAgo(card.created_at)}</b><br>` : ''}${a.l === 'archive' ? 'In the <b>archive</b>' : `In list <b>${esc(S.board.lists[a.l] ? S.board.lists[a.l].title : '')}</b>`}</div>
            <div class="side-sec"><h4>Assignees</h4><div class="chips">
                ${assignees.map((uid) => `<span class="uchip">${avatarHtml(uid, 'xs')}${esc(userName(uid))}<button data-unassign="${esc(uid)}" aria-label="Remove">${icon('close', 'xs')}</button></span>`).join('')}
                ${me && !assignees.includes(me) ? `<button class="btn sm" data-join>${icon('plus', 'xs')} Join</button>` : ''}
                ${!me ? `<span class="help">Log in as a user (Users) to assign yourself.</span>` : ''}
            </div></div>
            <div class="side-sec"><h4>Labels</h4><div class="label-grid">${LABEL_COLORS.map((c) => { const has = (card.labels || []).find((x) => colorOf(x) === c); return `<button class="lbl-toggle ${has ? 'on bg-' + c : ''}" data-label="${c}"><i class="bg-${c}"></i>${esc(has ? nameOf(has) : c.charAt(0).toUpperCase() + c.slice(1))}</button>`; }).join('')}</div></div>
            <div class="side-sec"><h4>Checklists ${clTotal ? `<span class="pill ${clDone === clTotal ? 'ok' : ''}">${Math.round(clDone / clTotal * 100)}%</span>` : ''}<button class="btn sm" data-cl-new>${icon('plus', 'xs')} New</button></h4>
                ${cls.map((cl, ci) => { const done = cl.items.filter((i) => i.state === 'complete').length; return `
                <div class="checklist">
                    <div class="cl-head"><b title="${esc(cl.name)}">${esc(cl.name)}</b><small>${done}/${cl.items.length}</small><button class="ibtn sm danger" data-cl-del="${ci}" aria-label="Delete checklist">${icon('trash', 'xs')}</button></div>
                    <div class="progress ${cl.items.length && done === cl.items.length ? 'done' : ''}" style="margin-bottom:6px"><i style="width:${cl.items.length ? Math.round(done / cl.items.length * 100) : 0}%"></i></div>
                    ${cl.items.map((it, ii) => `<div class="cl-item ${it.state === 'complete' ? 'done' : ''}" data-cl="${ci}" data-item="${ii}"><span class="box">${it.state === 'complete' ? icon('check', 'xs') : ''}</span><span>${esc(it.name)}</span><button class="ibtn sm" data-cl-item-del aria-label="Remove item">${icon('close', 'xs')}</button></div>`).join('')}
                    <input class="cl-add" placeholder="Add an item…" data-cl-add="${ci}">
                </div>`; }).join('')}
            </div>
            <div class="grid-2">
                <div class="side-sec"><h4>Start</h4><input type="date" class="field" value="${esc(card.startDate || '')}" data-date="startDate"></div>
                <div class="side-sec"><h4>Due</h4><input type="date" class="field" value="${esc(card.dueDate || '')}" data-date="dueDate"></div>
            </div>
            <div class="side-sec"><h4>Actions</h4><div class="side-actions">
                ${otherBoards.length ? comboHtml('move-board', 'Move to board…') : ''}
                <button class="btn wp" data-side="wp">${icon('send', 'sm')} Publish draft to WordPress</button>
                ${a.l !== 'archive' ? `<button class="btn" data-side="archive">${icon('archive', 'sm')} Archive card</button>` : ''}
                <button class="btn danger" data-side="delete">${icon('trash', 'sm')} Delete card</button>
            </div></div>
        </div></div>`;
    const side = $('#cw-side', el).firstElementChild;
    const persist = () => { if (cardReady()) persistMeta(card.id, meta); };
    on(side, 'click', '[data-side-close]', () => { S.ui.sidebar = false; cwApplyView(); });
    on(side, 'click', '[data-unassign]', (e, t) => { card.assignees = (card.assignees || []).filter((x) => x !== t.dataset.unassign); persistLayout(); cwRenderSide(); });
    on(side, 'click', '[data-join]', () => { card.assignees = card.assignees || []; card.assignees.push(me); persistLayout(); cwRenderSide(); });
    on(side, 'click', '[data-label]', (e, t) => { const c = t.dataset.label; const ls = card.labels || []; const i = ls.findIndex((x) => colorOf(x) === c); if (i > -1) ls.splice(i, 1); else ls.push({ color: c, name: c.charAt(0).toUpperCase() + c.slice(1) }); card.labels = ls; persistLayout(); cwRenderSide(); });
    on(side, 'click', '[data-cl-new]', async () => { const name = await dialog.prompt({ title: 'New checklist', value: 'Checklist', ok: 'Create' }); if (!name) return; meta.checklists = meta.checklists || []; meta.checklists.push({ id: uid(), name, items: [] }); persist(); updateChecklistStats(); cwRenderSide(); const inp = $$('[data-cl-add]', $('#cw-side', el)).pop(); if (inp) inp.focus(); });
    on(side, 'click', '[data-cl-del]', async (e, t) => { if (!await dialog.confirm({ title: 'Delete this checklist?', ok: 'Delete', danger: true })) return; meta.checklists.splice(+t.dataset.clDel, 1); persist(); updateChecklistStats(); cwRenderSide(); });
    on(side, 'click', '.cl-item', (e, t) => { if (e.target.closest('[data-cl-item-del]')) { meta.checklists[+t.dataset.cl].items.splice(+t.dataset.item, 1); } else { const it = meta.checklists[+t.dataset.cl].items[+t.dataset.item]; it.state = it.state === 'complete' ? 'incomplete' : 'complete'; } persist(); updateChecklistStats(); cwRenderSide(); });
    on(side, 'keydown', '[data-cl-add]', (e, t) => { if (e.key !== 'Enter') return; const v = t.value.trim(); if (!v) return; meta.checklists[+t.dataset.clAdd].items.push({ id: Date.now().toString(), name: v, state: 'incomplete' }); persist(); updateChecklistStats(); cwRenderSide(); const inp = $(`[data-cl-add="${t.dataset.clAdd}"]`, $('#cw-side', el)); if (inp) inp.focus(); });
    on(side, 'change', '[data-date]', (e, t) => { card[t.dataset.date] = t.value || null; meta.activity.unshift({ text: `${t.dataset.date === 'dueDate' ? 'Due' : 'Start'} date ${t.value ? 'set to ' + fmtShort(t.value) : 'cleared'}`, date: new Date().toISOString() }); persist(); persistLayout(); cwRenderActivity(); });
    const moveWrap = $('[data-combo="move-board"]', side);
    if (moveWrap) { const mc = bindCombo(moveWrap, { options: otherBoards.map((b) => ({ value: b.id, label: b.name })), onPick: async (bid) => {
        const name = (S.boards.find((b) => b.id === bid) || {}).name || bid;
        if (!await dialog.confirm({ title: `Move this card to "${name}"?`, ok: 'Move' })) { mc.clear(); return; }
        try { await api('move_card_to_board', { id: card.id, target_board: bid }); if (a.l === 'archive') S.board.archive.splice(a.c, 1); else S.board.lists[a.l].cards.splice(a.c, 1); saveLocal(); S.active.loading = true; closeCard(); toast(`Moved to ${name}`); }
        catch (err) { toast('Move failed: ' + err.message, 'err'); mc.clear(); }
    } }); }
    on(side, 'click', '[data-side]', async (e, t) => {
        const act = t.dataset.side;
        if (act === 'wp') openWpModal({ card, description: card.description || '' });
        else if (act === 'archive') { if (!await dialog.confirm({ title: `Archive "${card.title}"?`, ok: 'Archive' })) return; const live = removeCardFromBoard(S.active.card.id) || card; S.board.archive = S.board.archive || []; S.board.archive.unshift(live); meta.activity.unshift({ text: 'Archived', date: new Date().toISOString() }); persist(); S.active.original = card.description || ''; closeCard(); toast('Card archived'); }
        else if (act === 'delete') { if (!await dialog.confirm({ title: `Delete "${card.title}"?`, message: 'This removes the card and all of its history.', ok: 'Delete card', danger: true })) return; removeCardFromBoard(S.active.card.id); api('delete_card', { id: card.id }).catch(() => {}); S.active.loading = true; closeCard(); toast('Card deleted'); }
    });
}
function updateChecklistStats() {
    const a = S.active; if (!a) return; let total = 0, done = 0;
    (a.meta.checklists || []).forEach((cl) => cl.items.forEach((i) => { total++; if (i.state === 'complete') done++; }));
    a.card.checklistStats = total ? { total, done } : null; persistLayout();
}

/* ---------- Activity drawer ---------- */
function cwRenderActivity() {
    const el = cwEl(), a = S.active; if (!el || !a) return;
    const m = a.meta, tab = S.ui.actTab;
    const act = $('#cw-activity', el);
    const tabBtn = (id, label, n) => `<button class="act-tab ${tab === id && S.ui.activity ? 'on' : ''}" data-tab="${id}">${label}<span class="n">${n}</span></button>`;
    let body = '';
    if (tab === 'comments') {
        body = (m.comments.length ? m.comments.map((c) => commentHtml(c)).join('') : `<div class="empty">${ART.inbox}<div>No comments yet.</div></div>`) +
            `<div class="composer-row"><input class="field" placeholder="Write a comment… (:emoji: works here too)" data-comment-input><button class="btn primary" data-comment-send>${icon('send', 'sm')} Send</button></div>`;
    } else if (tab === 'history') {
        body = m.activity.length ? m.activity.map((log) => `<div class="log-row"><time title="${esc(fmtFull(log.date))}">${esc(timeAgo(log.date))}</time><span>${esc(log.text)}</span></div>`).join('') : `<div class="empty">No activity recorded.</div>`;
    } else {
        const revs = m.revisions || [];
        body = !revs.length ? `<div class="empty">${ART.compass}<div>No revisions yet.<br><small>A revision is saved each time you change a card and close it.</small></div></div>` : `
            <div class="rev-box">
                <div class="ends"><span>Oldest</span><span class="${S.ui.revision === -1 ? 'cur' : 'prev'}" id="rev-state">${S.ui.revision === -1 ? 'Current version' : 'Previewing history'}</span><span>Newest</span></div>
                <input type="range" min="-1" max="${revs.length - 1}" step="1" value="${S.ui.revision}" data-rev-slider>
                <div class="bottom"><div id="rev-caption"></div><button class="btn primary sm ${S.ui.revision === -1 ? 'hidden' : ''}" data-rev-restore>${icon('undo', 'sm')} Restore this</button></div>
            </div>
            <div class="help" style="text-align:center;margin-top:10px">Drag the slider left to walk back through history. The preview pane shows the selected version.</div>`;
    }
    act.innerHTML = `<div style="display:contents">
        <div class="act-head" data-act-toggle>
            ${tabBtn('comments', 'Comments', m.comments.length)}${tabBtn('history', 'Activity', m.activity.length)}${tabBtn('revisions', 'Revisions', (m.revisions || []).length)}
            <span class="spacer"></span>
            ${S.ui.activity ? `<button class="ibtn sm" data-act-max title="${S.ui.actMax ? 'Restore' : 'Maximize'}">${icon(S.ui.actMax ? 'maximize' : 'minimize', 'sm')}</button>` : ''}
            <button class="ibtn sm" title="${S.ui.activity ? 'Collapse' : 'Expand'}" style="transform:rotate(${S.ui.activity ? 0 : 180}deg)">${icon('chev-down', 'sm')}</button>
        </div>
        <div class="act-body scroll">${body}</div></div>`;
    cwApplyView();
    const actRoot = act.firstElementChild;
    on(actRoot, 'click', '[data-tab]', (e, t) => { e.stopPropagation(); S.ui.actTab = t.dataset.tab; S.ui.activity = true; try { localStorage.setItem('beckon_activity_open', 'true'); } catch (x) {} cwRenderActivity(); });
    on(actRoot, 'click', '[data-act-max]', (e) => { e.stopPropagation(); S.ui.actMax = !S.ui.actMax; cwRenderActivity(); });
    $('[data-act-toggle]', actRoot).addEventListener('click', (e) => { if (e.target.closest('[data-tab],[data-act-max]')) return; S.ui.activity = !S.ui.activity; if (!S.ui.activity) S.ui.actMax = false; try { localStorage.setItem('beckon_activity_open', S.ui.activity); } catch (x) {} cwRenderActivity(); });
    const inp = $('[data-comment-input]', actRoot);
    if (inp) { acAttach(inp); inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !acOpen()) addComment(inp.value); }); $('[data-comment-send]', actRoot).addEventListener('click', () => addComment(inp.value)); }
    on(actRoot, 'click', '[data-comment-edit]', (e, t) => { S.ui.editingComment = t.dataset.commentEdit; cwRenderActivity(); const ta = $('[data-edit-text]', $('#cw-activity', el)); if (ta) ta.focus(); });
    on(actRoot, 'click', '[data-comment-cancel]', () => { S.ui.editingComment = null; cwRenderActivity(); });
    on(actRoot, 'click', '[data-comment-save]', (e, t) => { const c = m.comments.find((x) => x.id === t.dataset.commentSave); const ta = $('[data-edit-text]', actRoot); if (c && ta && cardReady()) { c.text = ta.value; c.editedDate = new Date().toISOString(); commentCall('comment_edit', { comment_id: c.id, text: c.text }); } S.ui.editingComment = null; cwRenderActivity(); });
    on(actRoot, 'click', '[data-comment-del]', async (e, t) => { if (!await dialog.confirm({ title: 'Delete this comment?', ok: 'Delete', danger: true })) return; if (!cardReady()) return; m.comments = m.comments.filter((x) => x.id !== t.dataset.commentDel); a.card.commentCount = Math.max(0, (a.card.commentCount || 1) - 1); cwRenderActivity(); renderBoard(); commentCall('comment_delete', { comment_id: t.dataset.commentDel }); });
    on(actRoot, 'click', '[data-react]', (e, t) => toggleReaction(t.dataset.react, t.dataset.emoji));
    on(actRoot, 'click', '[data-react-add]', (e, t) => { e.stopPropagation(); openReactionPicker(t.dataset.reactAdd, t); });
    const slider = $('[data-rev-slider]', actRoot);
    if (slider) {
        const caption = () => { const cap = $('#rev-caption', actRoot); const r = (m.revisions || [])[S.ui.revision]; cap.innerHTML = r ? `<b>${esc(fmtFull(r.date))}</b><small>by ${esc(r.user || 'unknown')}</small>` : `<span class="help">Viewing the live version</span>`; const st = $('#rev-state', actRoot); st.textContent = S.ui.revision === -1 ? 'Current version' : 'Previewing history'; st.className = S.ui.revision === -1 ? 'cur' : 'prev'; $('[data-rev-restore]', actRoot).classList.toggle('hidden', S.ui.revision === -1); cwUpdateHead(); };
        caption();
        slider.addEventListener('input', () => { S.ui.revision = parseInt(slider.value, 10); caption(); if (S.ui.view === 'edit') { S.ui.view = 'split'; cwApplyView(); } cwUpdatePreview(); });
        $('[data-rev-restore]', actRoot).addEventListener('click', async () => {
            if (S.ui.revision < 0) return;
            if (!await dialog.confirm({ title: 'Restore this revision?', message: 'The current text is kept in history, so nothing is lost.', ok: 'Restore' })) return;
            const ta = $('#cw-desc', el); ta.value = m.revisions[S.ui.revision].text || ''; S.ui.revision = -1; ta.dispatchEvent(new Event('input', { bubbles: true })); cwUpdatePreview(); cwRenderActivity(); toast('Revision restored');
        });
    }
}
function commentHtml(c) {
    const name = userName(c.user_id) !== 'Unknown user' ? userName(c.user_id) : (c.user && c.user.name) || 'Unknown';
    const av = c.user_id && userAvatar(c.user_id) ? avatarHtml(c.user_id) : `<span class="avatar">${esc((c.user_id && getUser(c.user_id) ? userInitials(c.user_id) : (c.user && c.user.initials)) || 'U')}</span>`;
    const editing = S.ui.editingComment === c.id;
    const mine = (uid) => uid.includes(S.user.id);
    return `<div class="comment">${av}<div class="grow">
        <div class="comment-box">
            <div class="comment-top"><b>${esc(name)}</b><time title="${esc(fmtFull(c.date))}">${esc(timeAgo(c.date))}${c.editedDate ? ' · edited' : ''}</time>
                <span class="tools">${editing ? '' : `<button class="ibtn sm" data-comment-edit="${esc(c.id)}" title="Edit">${icon('pencil', 'xs')}</button><button class="ibtn sm danger" data-comment-del="${esc(c.id)}" title="Delete">${icon('trash', 'xs')}</button>`}</span></div>
            ${editing ? `<textarea class="field" rows="3" data-edit-text>${esc(c.text)}</textarea><div style="display:flex;justify-content:flex-end;gap:6px;margin-top:6px"><button class="btn sm" data-comment-cancel>Cancel</button><button class="btn sm primary" data-comment-save="${esc(c.id)}">Save</button></div>` : `<div class="md">${md.render(c.text || '')}</div>`}
        </div>
        <div class="reactions">
            ${(c.reactions || []).map((r) => `<button class="react ${mine(r.users) ? 'mine' : ''}" data-react="${esc(c.id)}" data-emoji="${esc(r.emoji)}" title="${esc(r.users.map((u) => userName(u)).join(', '))}"><span class="em">${md.inline(r.emoji)}</span><span class="n">${r.users.length}</span></button>`).join('')}
            <button class="react add" data-react-add="${esc(c.id)}" title="Add reaction">${icon('smile', 'sm')}</button>
        </div></div></div>`;
}
function cardReady() { const a = S.active; if (a && a.loading) { toast(a.failed ? 'This card did not load, so changes are off. Close and reopen it.' : 'Still loading this card…', 'info'); return false; } return !!a; }
/* Comments are written one at a time on the server, so two tabs commenting on one card both keep theirs. */
function commentCall(action, payload) {
    const a = S.active; if (!a) return;
    const card = a.card;
    api(action, { id: card.id, ...payload }).then((r) => {
        if (!r || !Array.isArray(r.comments)) return;
        if (S.active && S.active.card.id === card.id) { S.active.meta.comments = r.comments; cwRenderActivity(); }
        card.commentCount = r.comments.length; persistLayout(); renderBoard();
    }).catch((e) => toast('Comment not saved: ' + e.message, 'err'));
}
function addComment(text) {
    const a = S.active; text = (text || '').trim(); if (!a || !text || !cardReady()) return;
    const comment = { id: uid(), text, date: new Date().toISOString(), user_id: S.user.id || null, user: { name: S.user.name, initials: S.user.initials }, reactions: [] };
    a.meta.comments.unshift(comment);
    a.card.commentCount = (a.card.commentCount || 0) + 1;
    cwRenderActivity(); renderBoard();
    commentCall('comment_add', { comment });
}
async function toggleReaction(commentId, emoji) {
    const a = S.active; if (!a) return;
    const c = a.meta.comments.find((x) => x.id === commentId); if (!c) return;
    const uidv = S.user.id || 'guest';
    c.reactions = c.reactions || [];
    let r = c.reactions.find((x) => x.emoji === emoji);
    if (r) { const i = r.users.indexOf(uidv); if (i > -1) { r.users.splice(i, 1); if (!r.users.length) c.reactions = c.reactions.filter((x) => x !== r); } else r.users.push(uidv); }
    else c.reactions.push({ emoji, users: [uidv] });
    cwRenderActivity();
    try { await api('toggle_reaction', { card_id: a.card.id, comment_id: commentId, emoji, user_id: uidv }); } catch (e) {}
}
function openReactionPicker(commentId, anchor) {
    $$('.picker').forEach((p) => p.remove());
    const box = document.createElement('div'); box.className = 'picker';
    box.innerHTML = `<input class="field" placeholder="Search emoji…" data-q><div class="picker-grid" data-grid></div>`;
    anchor.parentElement.appendChild(box);
    const grid = $('[data-grid]', box);
    const draw = (q) => { grid.innerHTML = emojiSearch(q, 64).map(([k, ch]) => `<button data-k="${esc(k)}" title=":${esc(k)}:">${ch}</button>`).join(''); };
    draw('');
    $('[data-q]', box).addEventListener('input', (e) => draw(e.target.value.trim()));
    on(grid, 'click', '[data-k]', (e, t) => { toggleReaction(commentId, `:${t.dataset.k}:`); box.remove(); });
    setTimeout(() => $('[data-q]', box).focus(), 10);
}

/* ---------- Emoji autocomplete (":ali" in any textarea/input) ---------- */
const AC = { el: null, items: [], sel: 0, ta: null, start: 0 };
const acOpen = () => !!AC.el;
function acAttach(ta) {
    ta.addEventListener('input', () => acCheck(ta));
    ta.addEventListener('keydown', acKey);
    ta.addEventListener('blur', () => setTimeout(acClose, 150));
    ta.addEventListener('scroll', acClose);
}
function acClose() { if (AC.el) { AC.el.remove(); AC.el = null; } AC.items = []; AC.ta = null; }
function acCheck(ta) {
    const pos = ta.selectionStart; const before = ta.value.slice(0, pos);
    const m = /(?:^|\s):([a-z0-9_+-]{2,})$/i.exec(before);
    if (!m) return acClose();
    const items = emojiSearch(m[1].toLowerCase(), 8);
    if (!items.length) return acClose();
    AC.ta = ta; AC.start = pos - m[1].length - 1; AC.items = items; AC.sel = 0;
    if (!AC.el) { AC.el = document.createElement('div'); AC.el.className = 'ac'; document.body.appendChild(AC.el); AC.el.addEventListener('mousedown', (e) => { const b = e.target.closest('button'); if (b) { e.preventDefault(); acPick(+b.dataset.i); } }); }
    acRender();
    const p = caretCoords(ta); AC.el.style.left = clamp(p.left, 8, window.innerWidth - 250) + 'px'; AC.el.style.top = clamp(p.top + 4, 8, window.innerHeight - 230) + 'px';
}
function acRender() { if (!AC.el) return; AC.el.innerHTML = AC.items.map(([k, ch], i) => `<button data-i="${i}" class="${i === AC.sel ? 'sel' : ''}"><span class="em">${ch}</span>${esc(k)}</button>`).join(''); }
function acKey(e) {
    if (!AC.el) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); AC.sel = (AC.sel + 1) % AC.items.length; acRender(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); AC.sel = (AC.sel - 1 + AC.items.length) % AC.items.length; acRender(); }
    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); acPick(AC.sel); }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); acClose(); }
}
function acPick(i) {
    const item = AC.items[i], ta = AC.ta; if (!item || !ta) return;
    const end = ta.selectionStart; const ins = `:${item[0]}: `;
    ta.value = ta.value.slice(0, AC.start) + ins + ta.value.slice(end);
    const p = AC.start + ins.length; ta.setSelectionRange(p, p); acClose();
    ta.dispatchEvent(new Event('input', { bubbles: true }));
}
function caretCoords(ta) {
    const div = document.createElement('div'); const st = getComputedStyle(ta);
    ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'padding', 'border', 'boxSizing', 'width', 'tabSize', 'textTransform'].forEach((k) => { div.style[k] = st[k]; });
    div.style.position = 'absolute'; div.style.top = '0'; div.style.left = '-9999px'; div.style.visibility = 'hidden'; div.style.whiteSpace = ta.tagName === 'TEXTAREA' ? 'pre-wrap' : 'pre'; div.style.wordWrap = 'break-word'; div.style.height = 'auto'; div.style.overflow = 'hidden';
    div.textContent = ta.value.slice(0, ta.selectionStart);
    const span = document.createElement('span'); span.textContent = ta.value.slice(ta.selectionStart) || '.'; div.appendChild(span);
    document.body.appendChild(div);
    const r = ta.getBoundingClientRect(); const lh = parseFloat(st.lineHeight) || 20;
    const out = { top: r.top + span.offsetTop - ta.scrollTop + lh, left: r.left + span.offsetLeft - ta.scrollLeft };
    div.remove(); return out;
}

/* ---------- Search palette ---------- */
const SEARCH = { q: '', results: [], sel: 0, board: '', busy: false };
function openSearch() {
    SEARCH.q = ''; SEARCH.results = []; SEARCH.sel = 0; SEARCH.board = '';
    const el = openLayer('search', `<div class="win palette">
        <div class="palette-in">${icon('search')}<input placeholder="Search cards across all boards…" data-q autocomplete="off" spellcheck="false"><span class="spinner hidden" data-spin></span><kbd>esc</kbd></div>
        <div class="palette-filters scroll"><button class="chip on" data-board="">All boards</button>${S.boards.map((b) => `<button class="chip" data-board="${esc(b.id)}">${esc(b.name)}</button>`).join('')}</div>
        <div class="palette-list scroll" data-list></div>
        <div class="palette-foot"><div class="keys"><span><kbd>↑</kbd> <kbd>↓</kbd> navigate</span><span><kbd>↵</kbd> open</span></div><button data-reindex title="Rebuild the search index">${S.searchStats.available === false && S.searchStats.card_count === 0 ? 'Build index' : 'Rebuild index'}</button></div>
    </div>`, { top: true, focus: '[data-q]' });
    const list = $('[data-list]', el), inp = $('[data-q]', el);
    const draw = () => {
        if (SEARCH.q.length < 2) { list.innerHTML = `<div class="empty">${ART.compass}<div>Type to search titles, descriptions, comments and labels.</div></div>`; return; }
        if (!SEARCH.results.length) { list.innerHTML = SEARCH.busy ? '' : `<div class="empty"><div>No results for “${esc(SEARCH.q)}”.</div></div>`; return; }
        list.innerHTML = SEARCH.results.map((r, i) => `<div class="result ${i === SEARCH.sel ? 'sel' : ''}" data-i="${i}"><div class="grow"><b>${esc(r.title)}</b>${r.snippet ? `<div class="snip">${esc(r.snippet).replace(/&lt;mark&gt;/g, '<mark>').replace(/&lt;\/mark&gt;/g, '</mark>')}</div>` : ''}</div><div class="side"><span class="pill">${esc(r.board_name || r.board_id)}</span>${(r.labels || []).length ? `<span class="dots">${r.labels.slice(0, 4).map((l) => `<i class="bg-${esc(colorOf(l))}"></i>`).join('')}</span>` : ''}</div></div>`).join('');
        const s = $('.result.sel', list); if (s) s.scrollIntoView({ block: 'nearest' });
    };
    draw();
    const run = debounce(async () => {
        const q = SEARCH.q; if (q.length < 2) { SEARCH.results = []; draw(); return; }
        SEARCH.busy = true; $('[data-spin]', el).classList.remove('hidden');
        try { const res = await api('search', { query: q, board_id: SEARCH.board || null, limit: 30 }); if (SEARCH.q !== q) return; if (res.error) toast(res.error, 'err'); SEARCH.results = res.results || []; SEARCH.sel = 0; }
        catch (e) { SEARCH.results = []; }
        SEARCH.busy = false; $('[data-spin]', el).classList.add('hidden'); draw();
    }, 220);
    inp.addEventListener('input', () => { SEARCH.q = inp.value.trim(); draw(); run(); });
    inp.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); SEARCH.sel = Math.min(SEARCH.sel + 1, SEARCH.results.length - 1); draw(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); SEARCH.sel = Math.max(SEARCH.sel - 1, 0); draw(); }
        else if (e.key === 'Enter' && SEARCH.results.length) { e.preventDefault(); goToResult(SEARCH.results[SEARCH.sel]); }
    });
    on(el, 'click', '[data-board]', (e, t) => { SEARCH.board = t.dataset.board; $$('[data-board]', el).forEach((c) => c.classList.toggle('on', c === t)); inp.focus(); run(); });
    on(list, 'click', '[data-i]', (e, t) => goToResult(SEARCH.results[+t.dataset.i]));
    $('[data-reindex]', el).addEventListener('click', async () => {
        if (!await dialog.confirm({ title: 'Rebuild the search index?', message: 'Every card on every board is re-read. This can take a moment on large boards.', ok: 'Rebuild', info: true })) return;
        try { const res = await api('reindex'); S.searchStats = res.stats || S.searchStats; toast(`Index rebuilt: ${res.indexed} cards`); run(); } catch (e) { toast('Reindex failed', 'err'); }
    });
}
async function goToResult(r) {
    if (!r) return;
    closeLayer('search');
    if (r.board_id !== S.boardId) { S.boardId = r.board_id; await switchBoard(); }
    const same = (x) => String(x.id) === String(r.card_id);
    for (let l = 0; l < S.board.lists.length; l++) { const c = S.board.lists[l].cards.findIndex(same); if (c > -1) { openCard(l, c); return; } }
    const ai = (S.board.archive || []).findIndex(same); if (ai > -1) { openCard('archive', ai); return; }
    toast('Card not found on the board. Try rebuilding the index.', 'err');
}

/* ---------- Users (board members) ---------- */
let editingUser = null;
function openUsersModal() {
    editingUser = null;
    const el = openLayer('users', `<div class="win lg" style="height:min(70vh,640px)">${winHead('Users', 'users')}<div class="users-body scroll" data-body></div></div>`);
    bindClose(el); renderUsers();
}
function renderUsers() {
    const el = layerEl('users'); if (!el) return;
    const body = $('[data-body]', el);
    const users = Object.values(S.board.users || {});
    if (!editingUser) {
        body.innerHTML = `<div class="users-list">
            <div class="help" style="margin-bottom:12px">Users are simple identities kept in <code>users.json</code>. There are no passwords. Use <b>Log in as</b> to comment and take assignments as that person.</div>
            <div class="list-rows">
                <button class="dash-btn" data-new>${icon('plus', 'sm')} Create user</button>
                ${users.map((u) => `<div class="row-item">${u.avatarFile ? `<span class="avatar lg"><img src="${esc(u.avatarFile)}" alt=""></span>` : `<span class="avatar lg">${esc(u.initials || '?')}</span>`}<div class="grow"><b>${esc(u.fullName)}</b><small>${esc(u.username || 'no username')}</small></div>${S.user.id === u.id ? `<span class="pill ok">You</span>` : `<button class="btn sm" data-login="${esc(u.id)}">Log in as</button>`}<button class="btn sm" data-edit="${esc(u.id)}">Edit</button></div>`).join('')}
            </div></div>`;
    } else {
        const u = editingUser;
        body.innerHTML = `<div class="users-edit">
            <span class="label">${u._new ? 'New user' : 'Edit user'}</span>
            <div class="avatar-pick" data-avatar>${u.avatarFile ? `<span class="avatar xl"><img src="${esc(u.avatarFile)}" alt=""></span>` : `<span class="avatar xl">${esc(u.initials || '?')}</span>`}<div class="veil">Change</div><input type="file" accept="image/*" class="hidden" data-avatar-file></div>
            <div class="form-row"><span class="label">Full name</span><input class="field" value="${esc(u.fullName)}" data-f="fullName"></div>
            <div class="grid-2"><div class="form-row"><span class="label">Username</span><input class="field" value="${esc(u.username || '')}" data-f="username"></div><div class="form-row"><span class="label">Initials</span><input class="field" value="${esc(u.initials || '')}" data-f="initials" style="text-align:center;font-family:var(--mono)"></div></div>
            <div style="display:flex;justify-content:flex-end;gap:8px"><button class="btn" data-cancel>Cancel</button><button class="btn primary" data-save>Save user</button></div>
        </div>`;
    }
    const root = body.firstElementChild;
    on(root, 'click', '[data-new]', () => { editingUser = { id: uid(), fullName: '', username: '', initials: '', avatarFile: null, _new: true }; renderUsers(); const f = $('[data-f="fullName"]', root); if (f) f.focus(); });
    on(root, 'click', '[data-edit]', (e, t) => { editingUser = JSON.parse(JSON.stringify(S.board.users[t.dataset.edit])); renderUsers(); });
    on(root, 'click', '[data-login]', (e, t) => { const u = S.board.users[t.dataset.login]; S.user = { name: u.fullName, initials: u.initials || initialsOf(u.fullName), color: S.user.color || 'blue', id: u.id }; lsSet('beckon_user', S.user); closeLayer('users'); renderTopbar(); if (S.active) cwRenderSide(); toast(`Logged in as ${u.fullName}`); });
    on(root, 'click', '[data-cancel]', () => { editingUser = null; renderUsers(); });
    on(root, 'input', '[data-f]', (e, t) => { editingUser[t.dataset.f] = t.value; if (t.dataset.f === 'fullName') { editingUser.initials = initialsOf(t.value); $('[data-f="initials"]', root).value = editingUser.initials; } });
    on(root, 'click', '[data-avatar]', (e, t) => { if (!e.target.matches('input')) $('[data-avatar-file]', t).click(); });
    on(root, 'change', '[data-avatar-file]', async (e, t) => { try { const res = await apiUpload('upload_avatar', t.files[0]); if (res.url) { editingUser.avatarFile = res.url; renderUsers(); } } catch (x) { toast('Avatar upload failed', 'err'); } });
    on(root, 'click', '[data-save]', async () => {
        if (!editingUser.fullName.trim()) { toast('A name is required', 'err'); return; }
        const u = { ...editingUser }; delete u._new; if (!u.initials) u.initials = initialsOf(u.fullName);
        if (!S.board.users || Array.isArray(S.board.users)) S.board.users = {};
        S.board.users[u.id] = u;
        try { await api('save_users', { users: S.board.users }); editingUser = null; renderUsers(); renderTopbar(); toast('User saved'); } catch (x) { toast('Could not save users', 'err'); }
    });
}

/* ---------- WordPress publishing ---------- */
let wpManage = false;
function saveWp() { lsSet('beckon_wp_sites', S.wp.sites); try { localStorage.setItem('beckon_wp_selected', S.wp.selected || ''); } catch (e) {} }
async function prepareWpPublish(l, c) {
    const card = S.board.lists[l].cards[c];
    try { const res = await api('get_card', { id: card.id }); openWpModal({ card, description: res.description || '' }); }
    catch (e) { toast('Could not load the card', 'err'); }
}
function openWpModal(target) {
    wpManage = S.wp.sites.length === 0;
    const el = openLayer('wp', `<div class="win ctxwin"><div class="win-head"><h3 data-title></h3><button class="ibtn" data-close="wp" aria-label="Close">${icon('close')}</button></div><div class="win-body" data-body></div></div>`);
    bindClose(el); renderWp(el, target);
}
function renderWp(el, target) {
    $('[data-title]', el).textContent = wpManage ? 'WordPress sites' : 'Publish to WordPress';
    const body = $('[data-body]', el);
    if (!wpManage) {
        body.innerHTML = `
            <div class="help" style="margin-bottom:12px">Creates a <b>draft</b> post titled “${esc(target.card.title)}”. Local images are uploaded to the media library first and the cover becomes the featured image.</div>
            <span class="label">Destination</span>
            <div style="display:flex;gap:8px;margin-bottom:14px"><div style="flex:1;min-width:0">${comboHtml('site', 'Choose a site…')}</div><button class="btn" data-manage title="Manage sites">${icon('pencil', 'sm')}</button></div>
            <div class="pub-progress hidden" data-prog><div class="txt"><span data-status>Preparing…</span><span data-pct>0%</span></div><div class="progress"><i data-bar style="width:0%"></i></div></div>
            <div style="display:flex;justify-content:flex-end;gap:8px"><button class="btn" data-close="wp">Cancel</button><button class="btn primary" data-publish>${icon('send', 'sm')} Create draft</button></div>`;
        if (!S.wp.selected || !S.wp.sites.find((s) => s.id === S.wp.selected)) { S.wp.selected = S.wp.sites[0].id; saveWp(); }
        bindCombo($('[data-combo="site"]', body), { options: S.wp.sites.map((s) => ({ value: s.id, label: s.name, sub: s.url })), value: S.wp.selected, onPick: (v) => { S.wp.selected = v; saveWp(); } });
        $('[data-manage]', body).addEventListener('click', () => { wpManage = true; renderWp(el, target); });
        $('[data-publish]', body).addEventListener('click', () => publishToWp(el, target));
    } else {
        body.innerHTML = `<div>
            <div class="list-rows" style="margin-bottom:16px">${S.wp.sites.length ? S.wp.sites.map((s) => `<div class="row-item"><div class="grow"><b>${esc(s.name)}</b><small>${esc(s.url)} · ${esc(s.user)}</small></div><button class="ibtn sm danger" data-del="${esc(s.id)}" aria-label="Remove">${icon('trash', 'xs')}</button></div>`).join('') : `<div class="help">No sites yet. Add one below. Use an <b>application password</b> from your WordPress profile, never your login password.</div>`}</div>
            <span class="label">Add a site</span>
            <div class="form-row"><input class="field" placeholder="Friendly name (e.g. Personal blog)" data-n="name"></div>
            <div class="form-row"><input class="field" placeholder="https://example.com" data-n="url"></div>
            <div class="grid-2"><div class="form-row"><input class="field" placeholder="Username" data-n="user" autocomplete="off"></div><div class="form-row"><input class="field" type="password" placeholder="Application password" data-n="pass" autocomplete="new-password"></div></div>
            <div style="display:flex;justify-content:space-between;gap:8px">${S.wp.sites.length ? `<button class="btn" data-back>${icon('back', 'sm')} Back</button>` : `<span></span>`}<button class="btn primary" data-add>Save site</button></div></div>`;
        on(body.firstElementChild, 'click', '[data-del]', async (e, t) => { if (!await dialog.confirm({ title: 'Remove this site?', ok: 'Remove', danger: true })) return; S.wp.sites = S.wp.sites.filter((s) => s.id !== t.dataset.del); if (S.wp.selected === t.dataset.del) S.wp.selected = ''; saveWp(); renderWp(el, target); });
        const back = $('[data-back]', body); if (back) back.addEventListener('click', () => { wpManage = false; renderWp(el, target); });
        $('[data-add]', body).addEventListener('click', () => {
            const site = { id: Date.now().toString() }; let ok = true;
            $$('[data-n]', body).forEach((i) => { site[i.dataset.n] = i.value.trim(); if (!site[i.dataset.n]) ok = false; });
            if (!ok) { toast('All four fields are required', 'err'); return; }
            S.wp.sites.push(site); S.wp.selected = site.id; saveWp(); wpManage = false; renderWp(el, target);
        });
    }
}
async function publishToWp(el, target) {
    const site = S.wp.sites.find((s) => s.id === S.wp.selected); if (!site) { toast('Pick a site first', 'err'); return; }
    const prog = $('[data-prog]', el), btn = $('[data-publish]', el); prog.classList.remove('hidden'); btn.disabled = true;
    const set = (status, pct) => { $('[data-status]', prog).textContent = status; $('[data-pct]', prog).textContent = Math.round(pct) + '%'; $('[data-bar]', prog).style.width = pct + '%'; };
    try {
        const doc = new DOMParser().parseFromString(md.render(target.description || ''), 'text/html');
        const imgs = Array.from(doc.querySelectorAll('img')).filter((i) => i.getAttribute('src').includes('boards/'));
        const paths = new Set(imgs.map((i) => new URL(i.getAttribute('src'), location.origin).pathname.replace(/^\//, '')));
        const cover = target.card.coverImage; if (cover && cover.startsWith('boards/')) paths.add(cover);
        const total = paths.size + 1; let done = 0; const uploaded = {};
        const upload = async (p) => { if (uploaded[p]) return uploaded[p]; set(`Uploading ${p.split('/').pop()}…`, done / total * 100); const r = await api('wp_upload', { wp_url: site.url, wp_user: site.user, wp_pass: site.pass, local_path: p }); uploaded[p] = { id: r.id, url: r.url }; done++; return uploaded[p]; };
        for (const img of imgs) { const p = new URL(img.getAttribute('src'), location.origin).pathname.replace(/^\//, ''); const d = await upload(p); img.setAttribute('src', d.url); img.setAttribute('data-wp-id', d.id); }
        let featured = null; if (cover && cover.startsWith('boards/')) featured = (await upload(cover)).id;
        doc.querySelectorAll('[data-line]').forEach((n) => n.removeAttribute('data-line')); // editor source map, not content
        set('Creating draft…', 90);
        const res = await api('wp_post', { wp_url: site.url, wp_user: site.user, wp_pass: site.pass, title: target.card.title, html: doc.body.innerHTML, featured_media: featured });
        set('Done', 100); toast('Draft created on WordPress');
        if (res.edit_link) window.open(res.edit_link, '_blank');
        closeLayer('wp');
    } catch (e) { toast('Publish failed: ' + e.message, 'err'); prog.classList.add('hidden'); btn.disabled = false; }
}

/* ---------- Trello import ---------- */
function handleImportFile(file) {
    if (!file) return;
    const r = new FileReader();
    r.onload = (ev) => {
        let j; try { j = JSON.parse(ev.target.result); } catch (e) { toast('That file is not valid JSON', 'err'); return; }
        if (!j || !Array.isArray(j.cards) || !Array.isArray(j.lists)) { toast('This does not look like a Trello board export', 'err'); return; }
        // Trello stores dates as UTC timestamps; the board shows calendar days. Convert in this
        // browser's timezone so an evening deadline doesn't land on the next day.
        const localDay = (iso) => { const d = iso ? new Date(iso) : null; return d && !isNaN(d) ? `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}` : iso; };
        j.cards.forEach((c) => { if (c.due) c.due = localDay(c.due); if (c.start) c.start = localDay(c.start); });
        const atts = []; j.cards.forEach((c) => (c.attachments || []).forEach((a) => { if (a.url) atts.push({ cardId: c.id, url: a.url, name: a.name, id: a.id }); }));
        openImportModal({ json: j, attachments: atts });
    };
    r.readAsText(file);
}
function openImportModal(imp) {
    const j = imp.json;
    const el = openLayer('import', `<div class="win ctxwin" style="max-width:520px">${winHead(`Import “${j.name || 'Untitled'}”`, 'import')}<div class="win-body" data-body></div></div>`, { dismiss: false });
    bindClose(el);
    const body = $('[data-body]', el);
    body.innerHTML = `
        <div class="grid-2" style="margin-bottom:16px"><div class="stat-box"><b>${j.cards.length}</b><span>Cards</span></div><div class="stat-box"><b>${imp.attachments.length}</b><span>Attachments</span></div></div>
        <div class="grid-2" style="margin-bottom:16px"><div class="stat-box"><b>${j.lists.filter((l) => !l.closed).length}</b><span>Lists</span></div><div class="stat-box"><b>${(j.members || []).length}</b><span>Members</span></div></div>
        <span class="label">Private board access (optional)</span>
        <textarea class="field curl-box" placeholder="Paste a “Copy as cURL” command from a Trello request to fetch private attachments…" data-curl></textarea>
        <div class="help" style="margin:6px 0 16px">Browser dev tools → Network → right-click any trello.com request → Copy → Copy as cURL.</div>
        <div style="display:flex;justify-content:flex-end;gap:8px"><button class="btn" data-close="import">Cancel</button><button class="btn primary" data-go>${icon('upload', 'sm')} Start import</button></div>`;
    $('[data-go]', body).addEventListener('click', async () => {
        const m = /(?:-b|--cookie)\s+'([^']+)'/.exec($('[data-curl]', body).value) || /(?:-b|--cookie)\s+"([^"]+)"/.exec($('[data-curl]', body).value); const cookies = m ? m[1] : '';
        const total = imp.attachments.length + 1; let cur = 0;
        body.innerHTML = `<div class="pub-progress"><div class="txt"><span data-status>Creating board…</span><span data-pct>0%</span></div><div class="progress"><i data-bar style="width:0"></i></div></div><div class="help">Keep this window open. Attachments download one at a time.</div>`;
        const set = (s) => { $('[data-status]', body).textContent = s; const p = cur / total * 100; $('[data-pct]', body).textContent = Math.round(p) + '%'; $('[data-bar]', body).style.width = p + '%'; };
        try {
            const fd = new FormData(); fd.append('file', new Blob([JSON.stringify({ name: j.name, lists: j.lists, cards: j.cards, checklists: j.checklists || [], actions: j.actions || [], members: j.members || [], labelNames: j.labelNames || {} })], { type: 'application/json' }), 'import.json');
            const res = await (await fetch('?action=import_trello', { method: 'POST', body: fd })).json();
            if (!res.board) throw new Error(res.error || 'Board creation failed');
            cur++; set('Board created');
            for (const [i, a] of imp.attachments.entries()) { set(`Attachment ${i + 1} of ${imp.attachments.length}: ${a.name}`); try { await api('import_attachment', { board: res.board, card: a.cardId, url: a.url, name: a.name, attachmentId: a.id, cookies }); } catch (e) {} cur++; set(`Attachment ${i + 1} of ${imp.attachments.length}`); }
            set('Done'); await fetchBoards(); S.boardId = res.board; await switchBoard();
            setTimeout(() => { closeLayer('import'); toast(`Imported “${j.name}”`); }, 600);
        } catch (e) { toast('Import failed: ' + e.message, 'err'); closeLayer('import'); }
    });
}

/* ---------- Presentation mode + export ---------- */
function openPresentation() {
    const a = S.active; if (!a) return; closePresentation();
    const el = document.createElement('div'); el.className = 'present'; el.id = 'present';
    el.innerHTML = `<div class="present-tools"><button data-export title="Download as a standalone HTML file">${icon('download')}</button><button data-close title="Close (Esc)">${icon('close')}</button></div>
        <div class="present-scroll scroll"><div class="present-in"><h1 class="pt">${esc(a.card.title)}</h1><div class="pm"><span>${esc(a.l === 'archive' ? 'Archived' : (S.board.lists[a.l] || {}).title || '')}</span>${(a.card.assignees || []).length ? `<span>${esc(a.card.assignees.map(userName).join(', '))}</span>` : ''}</div><div class="md" data-md>${md.render(a.card.description || '')}</div><div class="end">•••</div></div></div>`;
    document.body.appendChild(el);
    on(el, 'click', '[data-close]', closePresentation);
    on(el, 'click', '[data-export]', exportPresentation);
    $('[data-md]', el).addEventListener('click', (e) => { if (e.target.matches('input[type="checkbox"]')) { toggleTaskAt($('[data-md]', el), e.target); $('[data-md]', el).innerHTML = md.render(a.card.description || ''); } });
}
function closePresentation() { const el = document.getElementById('present'); if (el) el.remove(); }
async function toDataUrl(url) { try { const b = await (await fetch(url)).blob(); return await new Promise((res, rej) => { const r = new FileReader(); r.onloadend = () => res(r.result); r.onerror = rej; r.readAsDataURL(b); }); } catch (e) { return url; } }
async function exportPresentation() {
    const a = S.active; if (!a) return;
    const btn = $('#present [data-export]'); if (btn) btn.disabled = true;
    try {
        const tmp = document.createElement('div'); tmp.innerHTML = md.render(a.card.description || '');
        for (const img of Array.from(tmp.querySelectorAll('img'))) { const src = img.getAttribute('src'); if (!/^https?:/i.test(src) || src.includes(location.host)) img.setAttribute('src', await toDataUrl(src)); }
        tmp.querySelectorAll('input[type="checkbox"]').forEach((i) => i.setAttribute('disabled', ''));
        const css = `body{margin:0;background:#0f1218;color:#e8ebf0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}.wrap{max-width:920px;margin:0 auto;padding:72px 32px}h1.pt{font-size:52px;letter-spacing:-.03em;line-height:1.1;margin:0 0 18px;padding-bottom:20px;border-bottom:4px solid #f2b134}.pm{font-family:ui-monospace,Menlo,monospace;color:#8b93a1;margin-bottom:56px}.pm span{background:#252b36;padding:4px 12px;border-radius:6px}.md{font-size:22px;line-height:1.8}.md h1,.md h2{border-bottom:1px solid #2a303b;padding-bottom:.2em}.md h1{font-size:2.3em}.md h2{font-size:1.8em}.md h3{font-size:1.4em}.md a{color:#8597ff}.md img{max-width:100%;border-radius:8px;display:block;margin:1.5em auto;max-height:78vh}.md pre{background:#252b36;padding:1em;border-radius:8px;overflow-x:auto;font-size:.9em}.md code{font-family:ui-monospace,Menlo,monospace;background:#252b36;padding:.15em .4em;border-radius:5px;font-size:.88em;color:#b9c4ff}.md pre code{background:none;padding:0;color:inherit}.md blockquote{border-left:3px solid #3a4150;margin:0 0 1em;padding:.2em 0 .2em 1em;color:#8b93a1}.md table{border-collapse:collapse;width:100%}.md th,.md td{border:1px solid #2a303b;padding:6px 10px;text-align:left}.md th{background:#252b36}.md li.task{list-style:none;margin-left:-1.4em}.md li.task input{transform:scale(1.4);margin-right:.8em}.md li.task.done>span{color:#8b93a1;text-decoration:line-through}.md hr{border:0;border-top:1px solid #2a303b;margin:1.5em 0}.emoji{font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji"}@media(max-width:700px){.wrap{padding:40px 18px}h1.pt{font-size:32px}.md{font-size:17px}}`;
        const html = `<!DOCTYPE html>\n<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${esc(a.card.title)}</title><style>${css}</style></head><body><div class="wrap"><h1 class="pt">${esc(a.card.title)}</h1><div class="pm"><span>${esc((S.board.lists[a.l] || {}).title || 'Archived')}</span></div><div class="md">${tmp.innerHTML}</div></div></body></html>`;
        const blob = new Blob([html], { type: 'text/html' }); const url = URL.createObjectURL(blob);
        const link = document.createElement('a'); link.href = url; link.download = `${(a.card.title || 'card').replace(/[^a-z0-9]+/gi, '-').replace(/(^-|-$)/g, '').toLowerCase() || 'card'}.html`; document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
        toast('Exported as standalone HTML');
    } catch (e) { toast('Export failed: ' + e.message, 'err'); }
    if (btn) btn.disabled = false;
}

/* ---------- Keyboard ---------- */
window.addEventListener('keydown', (e) => {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement && document.activeElement.tagName) || (document.activeElement && document.activeElement.isContentEditable);
    if (e.key === 'Escape') {
        if (AC.el) { acClose(); return; }
        if ($('.picker')) { $$('.picker').forEach((p) => p.remove()); return; }
        if ($('.ctx')) { closeCtx(); return; }
        if (document.getElementById('present')) { closePresentation(); return; }
        if (S.ui.pop) { closePop(); return; }
        const top = topLayer(); if (top) { if (top === 'dialog') return; dismissLayer(top); return; }
        if (document.getElementById('overview') && S.boards.length) { closeOverview(); return; }
        if (S.composer !== null && S.composer !== undefined) { S.composer = null; renderBoard(); }
        return;
    }
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); if (topLayer() === 'search') closeLayer('search'); else openSearch(); return; }
    if (typing) return;
    if (e.key === '/' && !topLayer()) { e.preventDefault(); openSearch(); return; }
    if (topLayer() || S.ui.pop || document.getElementById('overview')) return;
    if (S.hover && !e.metaKey && !e.ctrlKey && !e.altKey) {
        const at = locateCard(S.hover.id); if (!at || at.l === 'archive') return;
        if (e.key === 'Enter') { e.preventDefault(); openCard(at.l, at.c); }
        else if (e.key.toLowerCase() === 'c') { e.preventDefault(); archiveCard(at.l, at.c); }
    }
});
window.addEventListener('resize', debounce(() => { if (S.active) cwApplyView(); }, 120));
window.addEventListener('pagehide', () => {
    const a = S.active; if (!a || a.loading || !a.card.id) return;
    const send = (action, body) => { try { fetch(`?action=${action}&board=${encodeURIComponent(S.boardId)}`, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); } catch (e) {} };
    if ((a.card.description || '') === (a.original || '')) return;
    send('save_card', { id: a.card.id, description: a.card.description || '' });
    const meta = JSON.parse(JSON.stringify(a.meta));
    meta.revisions = [{ id: uid(), date: new Date().toISOString(), text: a.original || '', user: S.user.name }].concat(meta.revisions || []).slice(0, 50);
    meta.activity = [{ text: 'Modified description', date: new Date().toISOString() }].concat(meta.activity || []);
    send('save_card_meta', { id: a.card.id, meta });
    a.original = a.card.description || '';
});
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (!document.documentElement.getAttribute('data-theme')) renderTopbar(); });

/* ---------- Boot ---------- */
async function boot() {
    bindTopbar(); bindBoard(); renderTopbar(); renderBoard();
    const urlBoard = new URLSearchParams(location.search).get('board'); if (urlBoard) S.boardId = urlBoard;
    await fetchBoards();
    // One background check per browser session; the server only calls GitHub when its week-old cache has expired.
    api('check_updates', { background: true }).then((r) => { if (r && typeof r === 'object') { S.update = r; if (document.getElementById('overview')) openOverview(); } }).catch(() => {});
    api('search_stats').then((r) => { if (r) S.searchStats = r; }).catch(() => {});
    if (!S.boards.length) { S.boardId = ''; S.board = { title: 'Welcome', lists: [], archive: [], users: {} }; renderTopbar(); renderBoard(); openOverview(); return; }
    if (!S.boards.find((b) => b.id === S.boardId)) S.boardId = S.boards[0].id;
    await switchBoard();
}
boot();

    </script>
</body>
</html>
