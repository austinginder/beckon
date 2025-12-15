<?php
/**
 * Beckon - Where Markdown charts the course
 */
namespace Beckon;

use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

// Configuration
define('BECKON_VERSION', '1.0.0');

class App {
    private $baseDir;
    private $boardsDir;

    public function __construct() {
        $this->baseDir = __DIR__;
        $this->boardsDir = $this->baseDir . '/boards';
        
        // Ensure directories exist
        if (!file_exists($this->boardsDir)) {
            mkdir($this->boardsDir, 0755, true);
        }
    }

    public function run() {
        // Only intercept if this is an API request
        if (isset($_GET['action'])) {
            $this->handleApi($_GET['action']);
        }
        // Otherwise, let the script continue to render the HTML below
    }

    private function handleApi($action) {
        header('Content-Type: application/json');

        try {
            // parse JSON input
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            
            // Context setup
            $boardId = $_GET['board'] ?? 'main';
            $boardId = preg_replace('/[^a-z0-9-]/i', '', $boardId);
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

    // --- API Actions ---

    protected function actionCheckUpdates() {
        $stateFile = $this->boardsDir . '/update_state.json';
        $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : ['last_check' => 0, 'latest_version' => BECKON_VERSION];

        if (time() - ($state['last_check'] ?? 0) > 86400) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://github.com/austinginder/beckon/releases/latest',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Beckon-Updater'
            ]);
            curl_exec($ch);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            $latestVersion = basename($effectiveUrl);
            $state = ['last_check' => time(), 'latest_version' => $latestVersion];
            file_put_contents($stateFile, json_encode($state));
        }

        return [
            'update_available' => version_compare(ltrim($state['latest_version'], 'v'), ltrim(BECKON_VERSION, 'v'), '>'),
            'latest_version' => $state['latest_version']
        ];
    }

    protected function actionPerformUpdate($input) {
        $targetVersion = $input['version'] ?? null;
        if (!$targetVersion || !preg_match('/^v?[\d\.]+$/', $targetVersion)) throw new Exception("Invalid version");

        $url = "https://raw.githubusercontent.com/austinginder/beckon/{$targetVersion}/index.php";
        $newContent = @file_get_contents($url);
        
        if (!$newContent || strpos($newContent, '<?php') !== 0) throw new Exception("Download failed or invalid file signature.");

        if (!copy(__FILE__, __FILE__ . '.bak')) throw new Exception("Could not create backup.");
        if (file_put_contents(__FILE__, $newContent) === false) throw new Exception("Could not overwrite index.php.");

        return ['status' => 'updated'];
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
        
        // Helper to download avatar
        $downloadAvatar = function($id, $hash) use ($avatarDir, $slug) {
            if (!$hash) return null;
            $url = "https://trello-members.s3.amazonaws.com/{$id}/{$hash}/170.png";
            $filename = "{$id}.png";
            $content = @file_get_contents($url);
            if ($content) {
                file_put_contents("$avatarDir/$filename", $content);
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

        // 4. Checklists & Actions (Preparation)
        $checklists = [];
        foreach ($json['checklists'] ?? [] as $cl) $checklists[$cl['idCard']][] = $cl;

        $cardMeta = [];
        foreach (array_reverse($json['actions'] ?? []) as $act) {
            if (!isset($act['data']['card']['id'])) continue;
            $cid = $act['data']['card']['id'];

            // Import missing users found in comments
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

            // Parse specific action types
            $actor = $act['memberCreator']['fullName'] ?? 'Someone';
            $date = $act['date'];

            if ($act['type'] === 'commentCard') {
                $cardMeta[$cid]['comments'][] = [
                    'id' => $act['id'], 'text' => $act['data']['text'], 'date' => $date,
                    'user_id' => $act['memberCreator']['id'] ?? null,
                    'user' => ['name' => $actor, 'initials' => $act['memberCreator']['initials'] ?? '?']
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

        // 5. Attachments Map
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
            $createdDate = date('c', hexdec(substr($c['id'], 0, 8)));

            // Cover Image Logic
            $coverImagePath = null;
            if (!empty($c['idAttachmentCover']) && isset($attachmentMap[$c['idAttachmentCover']])) {
                $att = $attachmentMap[$c['idAttachmentCover']];
                $cleanName = preg_replace('/[^a-z0-9-]/i', '-', pathinfo($att['name'], PATHINFO_FILENAME));
                $filename = strtolower($cleanName . '-' . $att['id'] . '.' . $att['ext']);
                $coverImagePath = "boards/$slug/uploads/$filename";
            }

            // Checklists
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

            // Labels
            $mappedLabels = [];
            foreach ($c['labels'] ?? [] as $l) {
                $colorKey = $l['color'] ?? 'black';
                $name = $l['name'];
                if (empty($name) && isset($json['labelNames'][$colorKey])) $name = $json['labelNames'][$colorKey];
                $mappedLabels[] = ['color' => $colorMap[$colorKey] ?? 'slate', 'name' => $name ?: ucfirst($colorKey)];
            }

            $cardData = [
                'id' => $c['id'], 'title' => $c['name'],
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

            // Save Files
            file_put_contents("$targetDir/{$c['id']}.md", $c['desc']);
            
            $meta = $cardMeta[$c['id']] ?? ['comments' => [], 'activity' => [], 'revisions' => []];
            $meta['activity'] = array_reverse($meta['activity']);
            $meta['comments'] = array_reverse($meta['comments']);
            $meta['assigned_to'] = $c['idMembers'] ?? [];
            $meta['created_at'] = $createdDate;
            $meta['checklists'] = $cardChecklists;
            
            file_put_contents("$targetDir/{$c['id']}.json", json_encode($meta, JSON_PRETTY_PRINT));
        }

        file_put_contents("$targetDir/layout.json", json_encode([
            'title' => $json['name'], 'lists' => $lists, 'archive' => $archive
        ], JSON_PRETTY_PRINT));

        file_put_contents("$targetDir/users.json", json_encode($usersMap, JSON_PRETTY_PRINT));

        return ['status' => 'imported', 'board' => $slug];
    }

    protected function actionSaveUsers($input, $boardId, $boardDir) {
        file_put_contents("$boardDir/users.json", json_encode($input['users'], JSON_PRETTY_PRINT));
        return ['status' => 'saved'];
    }

    protected function actionUploadAvatar($input, $boardId, $boardDir) {
        if (!isset($_FILES['file'])) throw new Exception('No file');
        $dir = "$boardDir/uploads/avatars";
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
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
        $ext = pathinfo($rawName, PATHINFO_EXTENSION) ?: pathinfo(parse_url($input['url'], PHP_URL_PATH), PATHINFO_EXTENSION);
        $cleanName = preg_replace('/[^a-z0-9-]/i', '-', pathinfo($rawName, PATHINFO_FILENAME));
        
        if (!empty($input['attachmentId'])) {
            $filename = strtolower("$cleanName-{$input['attachmentId']}.$ext");
        } else {
            $filename = strtolower("$cleanName.$ext");
            $counter = 1;
            while(file_exists("$uploadDir/$filename")) $filename = strtolower("$cleanName-" . $counter++ . ".$ext");
        }

        // Curl Download
        $ch = curl_init($input['url']);
        $fp = fopen("$uploadDir/$filename", 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_HEADER => 0, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Beckon-Importer)', CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FAILONERROR => true
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

        // Append to Markdown
        $mdPath = "$boardPath/$cardId.md";
        if (file_exists($mdPath)) {
            $isImg = in_array(strtolower($ext), ['jpg','jpeg','png','gif','webp']);
            $append = "\n\n" . ($isImg ? '!' : '') . "[$rawName](boards/$slug/uploads/$filename)";
            file_put_contents($mdPath, $append, FILE_APPEND);
        }
        
        return ['status' => 'ok'];
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
        file_put_contents("$p/layout.json", json_encode(['title' => $title, 'lists' => []], JSON_PRETTY_PRINT));
        file_put_contents("$p/users.json", json_encode([], JSON_PRETTY_PRINT));
        
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
        return ['status' => 'ok'];
    }

    protected function actionRenameBoard($input, $boardId, $boardDir) {
        $newTitle = $input['title'];
        $newSlug = $this->slugify($newTitle);
        if (!$newSlug) throw new Exception("Invalid title");

        $layout = json_decode(file_get_contents("$boardDir/layout.json"), true);
        $layout['title'] = $newTitle;
        file_put_contents("$boardDir/layout.json", json_encode($layout, JSON_PRETTY_PRINT));

        if ($boardId !== $newSlug) {
            $newPath = $this->boardsDir . '/' . $newSlug;
            if (file_exists($newPath)) throw new Exception("Board exists");

            // Fix MD links
            foreach (glob("$boardDir/*.md") as $file) {
                $c = file_get_contents($file);
                $newC = str_replace("boards/$boardId/uploads/", "boards/$newSlug/uploads/", $c);
                if ($c !== $newC) file_put_contents($file, $newC);
            }
            rename($boardDir, $newPath);
            return ['status' => 'renamed', 'id' => $newSlug, 'name' => $newTitle];
        }
        return ['status' => 'updated', 'id' => $boardId, 'name' => $newTitle];
    }

    protected function actionLoad($input, $boardId, $boardDir) {
        $data = json_decode(@file_get_contents("$boardDir/layout.json"), true) ?? [];
        $users = json_decode(@file_get_contents("$boardDir/users.json"), true) ?? [];
        
        if (!isset($data['lists'])) $data['lists'] = [['id' => 'l1', 'title' => 'Start', 'cards' => []]];
        if (!isset($data['archive'])) $data['archive'] = [];
        if (!isset($data['title'])) $data['title'] = ucfirst(basename($boardDir));

        $hydrate = function(&$cards) use ($boardDir) {
            foreach ($cards as &$card) {
                $card['description'] = @file_get_contents("$boardDir/{$card['id']}.md") ?: '';
                $card['labels'] = $card['labels'] ?? [];
                $meta = json_decode(@file_get_contents("$boardDir/{$card['id']}.json"), true) ?? [];
                $card['commentCount'] = isset($meta['comments']) ? count($meta['comments']) : 0;
            }
        };

        foreach ($data['lists'] as &$list) $hydrate($list['cards']);
        $hydrate($data['archive']);
        $data['users'] = $users;
        
        return $data;
    }

    protected function actionLoadCardMeta($input, $boardId, $boardDir) {
        $meta = json_decode(@file_get_contents("$boardDir/{$input['id']}.json"), true) ?? [];
        return array_merge(['comments' => [], 'activity' => [], 'revisions' => [], 'assigned_to' => []], $meta);
    }

    protected function actionSaveLayout($input, $boardId, $boardDir) {
        // Strip descriptions
        foreach ($input['lists'] as &$list) foreach ($list['cards'] as &$card) unset($card['description']);
        if (isset($input['archive'])) foreach ($input['archive'] as &$card) unset($card['description']);
        
        file_put_contents("$boardDir/layout.json", json_encode($input, JSON_PRETTY_PRINT));
        return ['status' => 'saved'];
    }

    protected function actionSaveCard($input, $boardId, $boardDir) {
        file_put_contents("$boardDir/{$input['id']}.md", $input['description'] ?? '');
        return ['status' => 'saved'];
    }

    protected function actionSaveCardMeta($input, $boardId, $boardDir) {
        file_put_contents("$boardDir/{$input['id']}.json", json_encode($input['meta'], JSON_PRETTY_PRINT));
        return ['status' => 'saved'];
    }

    protected function actionMoveCardToBoard($input, $boardId, $boardDir) {
        $targetId = $input['target_board'];
        $cardId = $input['id'];
        $targetPath = $this->getBoardPath($targetId);
        
        if (!file_exists($targetPath)) throw new Exception("Target board not found");

        $sourceLayout = json_decode(file_get_contents("$boardDir/layout.json"), true);
        $cardData = null;
        
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

        $targetLayout = json_decode(@file_get_contents("$targetPath/layout.json"), true) ?? [];
        if (empty($targetLayout['lists'])) $targetLayout['lists'] = [['id' => 'l1', 'title' => 'Inbox', 'cards' => []]];
        array_unshift($targetLayout['lists'][0]['cards'], $cardData);

        foreach (["$cardId.md", "$cardId.json"] as $f) {
            if (file_exists("$boardDir/$f")) rename("$boardDir/$f", "$targetPath/$f");
        }

        file_put_contents("$boardDir/layout.json", json_encode($sourceLayout, JSON_PRETTY_PRINT));
        file_put_contents("$targetPath/layout.json", json_encode($targetLayout, JSON_PRETTY_PRINT));
        
        // Log Activity
        $metaFile = "$targetPath/$cardId.json";
        $meta = json_decode(@file_get_contents($metaFile), true) ?? ['activity' => []];
        $meta['activity'] = $meta['activity'] ?? [];
        $targetTitle = $targetLayout['title'] ?? $targetId;
        array_unshift($meta['activity'], ['text' => "Moved from board '{$sourceLayout['title']}' to '{$targetTitle}'", 'date' => date('c')]);

        file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));

        return ['status' => 'moved'];
    }

    protected function actionListUploads($input, $boardId, $boardDir) {
        $files = glob("$boardDir/uploads/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}", GLOB_BRACE);
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        return ['files' => array_map(fn($f) => "boards/$boardId/uploads/" . basename($f), $files)];
    }

    protected function actionSaveRevision($input, $boardId, $boardDir) {
        $meta = json_decode(@file_get_contents("$boardDir/{$input['id']}.json"), true) ?? [];
        $meta['revisions'] = $meta['revisions'] ?? [];
        array_unshift($meta['revisions'], [
            'id' => uniqid(), 'date' => date('c'), 'text' => $input['text'], 'user' => $input['user'] ?? 'Unknown'
        ]);
        if (count($meta['revisions']) > 50) $meta['revisions'] = array_slice($meta['revisions'], 0, 50);
        file_put_contents("$boardDir/{$input['id']}.json", json_encode($meta, JSON_PRETTY_PRINT));
        return ['status' => 'saved'];
    }

    protected function actionDeleteCard($input, $boardId, $boardDir) {
        $id = $input['id'];
        if (file_exists("$boardDir/$id.md")) unlink("$boardDir/$id.md");
        if (file_exists("$boardDir/$id.json")) unlink("$boardDir/$id.json");
        return ['status' => 'deleted'];
    }

    protected function actionUpload($input, $boardId, $boardDir) {
        if (!isset($_FILES['file'])) throw new Exception('No file');
        $dir = "$boardDir/uploads";
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $name = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $clean = $this->slugify($name);
        
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
        $text = preg_replace('/[^a-z0-9-]/i', '-', strtolower($text));
        return trim(preg_replace('/-+/', '-', $text), '-');
    }
}

// Instantiate and run
(new App())->run();
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
                            <icon name="chevron-down" class="w-4 h-4 text-slate-500 group-hover:text-white transition" /></icon>
                        </button>
                        <button @click="openRenameModal" class="text-slate-500 hover:text-white p-1 rounded hover:bg-slate-800 transition" title="Rename Board">
                            <icon name="pencil" class="w-4 h-4" /></icon>
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
                                    <icon name="plus" class="w-4 h-4"></icon>
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
                    <icon name="cloud" class="w-3 h-3"></icon> Import
                    <input type="file" @change="handleImportFile" class="hidden" accept=".json">
                </label>

                <div class="relative z-40">
                    <button @click="toggleArchive" class="text-xs bg-slate-800 hover:bg-slate-700 px-3 py-1.5 rounded transition border border-slate-700 flex items-center gap-2 relative">
                        <icon name="archive" class="w-3 h-3"></icon>
                        Archive
                        <span v-if="boardData.archive?.length" class="bg-slate-600 text-white text-[10px] px-1.5 rounded-full">{{ boardData.archive.length }}</span>
                    </button>

                    <div v-if="isArchiveOpen" class="absolute top-full right-0 mt-2 w-80 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden animate-fade-in origin-top-right flex flex-col max-h-[500px]">
                        <div class="p-2 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
                            <input 
                                ref="archiveSearchInput"
                                v-model="archiveSearch" 
                                placeholder="Search archived cards..." 
                                class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 px-3 py-2 rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 text-slate-700 dark:text-slate-200"
                            >
                        </div>
                        <div class="flex-1 overflow-y-auto">
                            <div v-if="filteredArchive.length === 0" class="p-4 text-center text-slate-500 text-sm italic">
                                {{ boardData.archive?.length ? 'No matches found.' : 'Archive is empty.' }}
                            </div>
                            <div v-for="(card, index) in filteredArchive" :key="card.id" 
                                 @click="openCardModal('archive', boardData.archive.indexOf(card)); isArchiveOpen = false"
                                 class="p-3 border-b border-slate-50 dark:border-slate-700 hover:bg-blue-50 dark:hover:bg-slate-700 cursor-pointer group">
                                <div class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-1 group-hover:text-blue-600">{{ card.title }}</div>
                                <div class="text-xs text-slate-400 truncate">{{ card.description?.slice(0, 60) }}...</div>
                            </div>
                        </div>
                    </div>
                    <div v-if="isArchiveOpen" @click="isArchiveOpen = false" class="fixed inset-0 z-[-1] cursor-default"></div>
                </div>

                <button @click="openUsersModal" class="text-xs bg-slate-800 hover:bg-slate-700 px-3 py-1.5 rounded transition border border-slate-700 flex items-center gap-2 relative">
                    <icon name="users" class="w-3 h-3"></icon>
                    Users
                    <span v-if="Object.keys(boardData.users || {}).length" class="bg-slate-600 text-white text-[10px] px-1.5 rounded-full">{{ Object.keys(boardData.users || {}).length }}</span>
                </button>

                <div v-if="isUsersModalOpen" class="fixed inset-0 bg-black/75 flex items-center justify-center z-50 p-4 backdrop-blur-sm" @click.self="isUsersModalOpen = false">
                    <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col h-[70vh]">
                        <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-center">
                            <h3 class="font-bold text-slate-700 dark:text-slate-200">User Management</h3>
                            <button @click="isUsersModalOpen = false" class="text-slate-400 hover:text-red-500"><icon name="close" class="w-5 h-5"></icon></button>
                        </div>
                        
                        <div class="flex-1 flex overflow-hidden">
                            <div class="flex-1 overflow-y-auto p-4 border-r dark:border-slate-700" v-if="!editingUser">
                                <div class="grid grid-cols-1 gap-2">
                                    <div @click="createNewUser" class="p-3 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg cursor-pointer hover:border-blue-500 hover:bg-slate-50 dark:hover:bg-slate-700 flex items-center justify-center gap-2 text-slate-500 transition">
                                        <icon name="plus" class="w-5 h-5"></icon> Create New User
                                    </div>
                                    
                                    <div v-for="u in boardData.users" :key="u.id" class="p-3 bg-slate-50 dark:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-600 flex justify-between items-center group">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-600 flex items-center justify-center text-sm font-bold text-slate-600 dark:text-slate-300 overflow-hidden shrink-0">
                                                <img v-if="u.avatarFile" :src="u.avatarFile" class="w-full h-full object-cover">
                                                <span v-else>{{ u.initials }}</span>
                                            </div>
                                            <div>
                                                <div class="font-bold text-slate-800 dark:text-slate-200 text-sm">{{ u.fullName }}</div>
                                                <div class="text-[10px] text-slate-400 font-mono">{{ u.username || 'No Username' }}</div>
                                            </div>
                                        </div>
                                        <div class="flex gap-2">
                                            <button @click="loginAs(u)" class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded hover:bg-green-200 border border-green-200 transition" v-if="currentUser.id !== u.id">Login As</button>
                                            <span v-else class="text-[10px] bg-slate-200 text-slate-500 px-2 py-1 rounded font-bold">YOU</span>
                                            <button @click="editUser(u)" class="text-xs bg-white dark:bg-slate-600 text-slate-600 dark:text-slate-300 px-2 py-1 rounded border border-slate-200 dark:border-slate-500 hover:border-blue-500 transition">Edit</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex-1 p-6 overflow-y-auto" v-if="editingUser">
                                <h4 class="text-sm font-bold text-slate-500 uppercase mb-4">{{ editingUser.id ? 'Edit User' : 'New User' }}</h4>
                                
                                <div class="flex justify-center mb-6">
                                    <div class="relative group cursor-pointer w-24 h-24" @click="$refs.avatarInput.click()">
                                        <div class="w-24 h-24 rounded-full bg-slate-200 dark:bg-slate-600 flex items-center justify-center text-2xl font-bold text-slate-400 overflow-hidden ring-4 ring-slate-100 dark:ring-slate-700">
                                            <img v-if="editingUser.avatarFile" :src="editingUser.avatarFile" class="w-full h-full object-cover">
                                            <span v-else>{{ editingUser.initials || '?' }}</span>
                                        </div>
                                        <div class="absolute inset-0 bg-black/50 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition text-white text-xs font-bold">
                                            Change
                                        </div>
                                        <input type="file" ref="avatarInput" class="hidden" accept="image/*" @change="handleAvatarUpload">
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Full Name</label>
                                        <input v-model="editingUser.fullName" @input="updateInitials" class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500 outline-none">
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Username</label>
                                            <input v-model="editingUser.username" class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500 outline-none">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Initials</label>
                                            <input v-model="editingUser.initials" class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-sm text-center font-mono text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-blue-500 outline-none">
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-8 flex justify-end gap-2">
                                    <button @click="editingUser = null" class="px-4 py-2 text-sm text-slate-500 hover:text-slate-700 transition">Cancel</button>
                                    <button @click="saveUserEntry" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-6 py-2 rounded shadow-lg text-sm transition">Save User</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button @click="darkMode = !darkMode" class="text-slate-400 hover:text-white transition p-1.5 rounded hover:bg-slate-800 ml-auto">
                    <icon v-if="darkMode" name="sun" class="w-4 h-4"></icon>
                    <icon v-else name="moon" class="w-4 h-4"></icon>
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
                                @contextmenu.prevent="showContextMenu($event, listIndex, cardIndex)" 
                                @dragover.stop.prevent="onCardDragOver($event, listIndex, cardIndex)"
                                @click="openCardModal(listIndex, cardIndex)"
                                @mouseenter="hoveredCard = { l: listIndex, c: cardIndex }"
                                @mouseleave="hoveredCard = { l: null, c: null }"
                                :class="{'ring-2 ring-blue-400 ring-offset-1': hoveredCard.l === listIndex && hoveredCard.c === cardIndex}"
                                class="bg-white dark:bg-slate-700 p-3 rounded shadow-sm border border-slate-200 dark:border-slate-600 hover:shadow-md cursor-pointer transition group relative hover:border-blue-400 mb-2">

                                <div v-if="card.labels?.length" class="flex gap-1 mb-2 flex-wrap">
                                    <span v-for="l in card.labels" :key="l.name || l" 
                                          :class="`bg-${(l.color || l)}-500`" 
                                          class="h-2 w-8 rounded-full block" 
                                          :title="l.name || l">
                                    </span>
                                </div>

                                <div v-if="card.coverImage" class="mb-2 -mx-3 -mt-3 rounded-t overflow-hidden h-32 relative group/cover">
                                    <img :src="card.coverImage" class="w-full h-full object-cover">
                                </div>
                                
                                <div class="text-sm text-slate-800 dark:text-slate-100 mb-2">{{ card.title }}</div>
                                
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3 text-[10px] text-slate-400 font-mono">
                                        <span v-if="card.description"><icon name="text" class="w-3 h-3 inline"></icon></span>
                                        <span v-if="hasAttachment(card.description)" title="Has Attachments">
                                            <icon name="paperclip" class="w-3 h-3 inline"></icon>
                                        </span>
                                        <span v-if="card.commentCount > 0" title="Comments" class="flex items-center gap-0.5">
                                            <icon name="chat" class="w-3 h-3 inline"></icon> {{ card.commentCount }}
                                        </span>
                                        <span v-if="getTaskStats(card).total > 0" :class="{'text-green-600 dark:text-green-400': getTaskStats(card).done === getTaskStats(card).total}">
                                            <icon name="check-circle" class="w-3 h-3 inline"></icon> {{ getTaskStats(card).done }}/{{ getTaskStats(card).total }}
                                        </span>
                                        <span v-if="card.startDate || card.dueDate" class="flex items-center gap-1" :class="card.dueDate ? getDueDateColor(card.dueDate) : 'text-slate-400'">
                                            <icon name="clock" class="w-3 h-3 inline"></icon> 
                                            
                                            <span v-if="card.startDate">
                                                {{ formatDateShort(card.startDate) }} 
                                                <span v-if="card.dueDate" class="mx-0.5">-</span>
                                            </span>

                                            <span v-if="card.dueDate">
                                                {{ formatDateShort(card.dueDate) }}
                                            </span>
                                        </span>
                                    </div>
                                    
                                    <div v-if="card.assignees?.length" class="flex -space-x-2 overflow-hidden">
                                        <div v-for="uid in card.assignees" :key="uid" 
                                             class="inline-block h-5 w-5 rounded-full ring-2 ring-white dark:ring-slate-700 bg-slate-200 dark:bg-slate-600 flex items-center justify-center text-[8px] font-bold text-slate-600 dark:text-slate-300"
                                             :title="getUserName(uid)">
                                            <img v-if="getUserAvatar(uid)" :src="getUserAvatar(uid)" class="h-full w-full rounded-full object-cover">
                                            <span v-else>{{ getUserInitials(uid) }}</span>
                                        </div>
                                    </div>
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
                        <div v-if="activeCard.listIndex === 'archive'" class="mt-2 flex items-center gap-3">
                            <span class="text-xs font-bold text-amber-600 bg-amber-100 px-2 py-1 rounded">ARCHIVED</span>
                            <button @click="restoreArchivedCard" class="text-xs bg-amber-100 hover:bg-amber-200 text-amber-800 font-bold px-3 py-1 rounded border border-amber-200 transition flex items-center gap-1">
                                <icon name="undo" class="w-3 h-3"></icon>
                                Restore to Board
                            </button>
                        </div>
                        <div v-else class="text-xs text-slate-500 mt-1">in list <span class="font-bold underline">{{ boardData.lists[activeCard.listIndex]?.title }}</span></div>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        
                        <div class="relative group">
                            <button @click="toggleView" class="text-slate-400 hover:text-blue-600 transition p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700">
                                <icon v-if="splitPaneRatio === 100" name="pencil" class="h-6 w-6"></icon>
                                <icon v-else-if="splitPaneRatio === 0" name="eye" class="h-6 w-6"></icon>
                                <icon v-else name="code" class="h-6 w-6"></icon>
                            </button>
                            <div class="absolute top-full right-0 mt-2 px-2 py-1 bg-slate-800 text-white text-[10px] font-bold rounded opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none whitespace-nowrap shadow-xl z-50">
                                {{ splitPaneRatio === 100 ? 'Switch to Preview' : (splitPaneRatio === 0 ? 'Switch to Split' : 'Switch to Editor') }}
                                <div class="absolute bottom-full right-2 -mt-[1px] border-4 border-transparent border-b-slate-800"></div>
                            </div>
                        </div>

                        <div class="relative group">
                            <button @click="isSidebarOpen = !isSidebarOpen" class="text-slate-400 hover:text-blue-600 transition p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700">
                                <icon name="sidebar" class="h-6 w-6"></icon>
                            </button>
                            <div class="absolute top-full right-0 mt-2 px-2 py-1 bg-slate-800 text-white text-[10px] font-bold rounded opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none whitespace-nowrap shadow-xl z-50">
                                {{ isSidebarOpen ? 'Close Sidebar' : 'Open Sidebar' }}
                                <div class="absolute bottom-full right-2 -mt-[1px] border-4 border-transparent border-b-slate-800"></div>
                            </div>
                        </div>

                        <button @click="closeModal" class="text-slate-400 hover:text-red-500 transition p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700" title="Close (Esc)">
                            <icon name="close" class="h-8 w-8"></icon>
                        </button>
                    </div>
                </div>

                <div class="flex-1 flex overflow-hidden">
                    
                    <div class="flex-1 flex flex-col min-w-0 overflow-hidden" :class="{'border-r border-slate-200 dark:border-slate-700': isSidebarOpen}">
                        
                        <div v-show="!isActivityMaximized" class="flex-1 flex min-h-0 relative" ref="splitPaneContainer">
                            
                            <div class="flex flex-col border-r border-slate-100 dark:border-slate-700 overflow-hidden" 
                                :style="{ width: splitPaneRatio + '%' }">
                                <div class="bg-slate-50 dark:bg-slate-900 px-4 py-2 border-b dark:border-slate-700 flex justify-between items-center text-xs font-bold text-slate-500 transition-colors shrink-0">
                                    <div class="flex items-center gap-3">
                                        <span>MARKDOWN</span>
                                        <span class="text-[10px] font-mono font-normal text-slate-400 normal-case border-l border-slate-300 dark:border-slate-600 pl-3 transition-opacity duration-300" 
                                            :class="editorStats.words > 0 ? 'opacity-100' : 'opacity-0'">
                                            {{ editorStats.words }} w &bull; {{ editorStats.readTime }} min read
                                        </span>
                                    </div>
                                    <label class="cursor-pointer hover:text-blue-600 flex items-center gap-1">
                                        <icon name="image" class="w-4 h-4"></icon> Img <input type="file" @change="handleFileInput" class="hidden" accept="image/*">
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
                             :class="isActivityMaximized ? 'flex-1 h-full' : (isActivityOpen ? 'h-1/2' : 'h-12 shrink-0')">
                            
                            <div class="px-4 py-2 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-center text-xs font-bold text-slate-500 transition-colors cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800"
                                 @click="isActivityOpen = !isActivityOpen; if(!isActivityOpen) isActivityMaximized = false">
                                
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

                                <div class="flex items-center gap-2">
                                    <button v-if="isActivityOpen" 
                                            @click.stop="isActivityMaximized = !isActivityMaximized"
                                            class="text-slate-400 hover:text-blue-500 p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700"
                                            :title="isActivityMaximized ? 'Minimize' : 'Maximize'">
                                        <icon v-if="!isActivityMaximized" name="minimize" class="w-4 h-4"></icon>
                                        <icon v-else name="maximize" class="w-4 h-4"></icon>
                                    </button>

                                    <button class="text-slate-400 hover:text-blue-500 transition-transform duration-300" 
                                            :class="{'rotate-180': !isActivityOpen}">
                                        <icon name="chevron-down" class="w-4 h-4"></icon>
                                    </button>
                                </div>
                            </div>

                            <div v-show="isActivityOpen" class="flex-1 overflow-y-auto p-4 space-y-4">
                                
                                <div v-if="activityTab==='comments'" class="flex flex-col h-full">
                                    <div class="flex-1 space-y-4 overflow-y-auto mb-4">
                                        <div v-if="!activeCardMeta.comments.length" class="text-center text-slate-400 text-sm mt-4">No comments.</div>
                                        <div v-for="c in activeCardMeta.comments" :key="c.id" class="flex gap-3 text-sm group/comment">
                                            
                                            <div v-if="c.user_id && getUserAvatar(c.user_id)" class="w-8 h-8 rounded-full shrink-0 ring-2 ring-white dark:ring-slate-800 overflow-hidden">
                                                <img :src="getUserAvatar(c.user_id)" :alt="getUserInitials(c.user_id)" class="w-full h-full object-cover">
                                            </div>
                                            <div v-else :class="`bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300`" 
                                                class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs shrink-0 ring-2 ring-white dark:ring-slate-800">
                                                {{ getUserInitials(c.user_id) || c.user?.initials || 'U' }}
                                            </div>
                                            
                                            <div class="flex-1 bg-slate-50 dark:bg-slate-700 p-3 rounded-lg border border-slate-100 dark:border-slate-600 dark:text-slate-200 relative">
                                                <div class="flex justify-between items-baseline mb-2">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-bold text-xs text-slate-700 dark:text-slate-300">
                                                            {{ getUserName(c.user_id) || c.user?.name || 'Unknown' }}
                                                        </span>
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
                                        No revision history yet.
                                        <br><span class="text-xs">Revisions are created when you change a card and close it.</span>
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

                    <div v-if="isSidebarOpen" class="w-72 bg-slate-50 dark:bg-slate-900 border-l border-slate-200 dark:border-slate-700 flex flex-col overflow-y-auto shrink-0 transition-all duration-300">
                        <div class="p-4 space-y-6">
                            
                            <div class="text-xs text-slate-400 font-mono">
                                <div>Created {{ formatTime(activeCard.data.created_at) }}</div>
                                <div class="mt-1">in list <span class="font-bold text-slate-600 dark:text-slate-300">{{ boardData.lists[activeCard.listIndex]?.title }}</span></div>
                            </div>

                            <div>
                                <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Assignees</h3>
                                <div class="flex flex-wrap gap-2 mb-2">
                                    <div v-for="uid in activeCard.data.assignees" :key="uid" 
                                         class="flex items-center gap-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded-full pl-1 pr-3 py-1">
                                        <div class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center text-[10px] font-bold overflow-hidden">
                                            <img v-if="getUserAvatar(uid)" :src="getUserAvatar(uid)" class="w-full h-full object-cover">
                                            <span v-else>{{ getUserInitials(uid) }}</span>
                                        </div>
                                        <span class="text-xs font-bold">{{ getUserName(uid) }}</span>
                                        <button @click="activeCard.data.assignees = activeCard.data.assignees.filter(id => id !== uid); persistLayout()" class="text-slate-400 hover:text-red-500">×</button>
                                    </div>
                                    <button @click="!activeCard.data.assignees.includes(currentUser.id) && (activeCard.data.assignees.push(currentUser.id), persistLayout())" 
                                            v-if="!activeCard.data.assignees?.includes(currentUser.id)"
                                            class="text-xs bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 px-3 py-1.5 rounded-full transition font-bold text-slate-600 dark:text-slate-300">
                                        Join
                                    </button>
                                </div>
                            </div>

                            <div>
                                <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Labels</h3>
                                <div class="flex flex-wrap gap-2">
                                    <button v-for="color in labelColors" :key="color" 
                                            @click="toggleLabel(color)"
                                            class="w-8 h-6 rounded hover:opacity-80 transition relative"
                                            :class="[`bg-${color}-500`, activeCard.data.labels?.find(l=>(l.color||l)===color) ? 'ring-2 ring-offset-1 ring-slate-400' : '']">
                                        <span v-if="activeCard.data.labels?.find(l=>(l.color||l)===color)" class="absolute inset-0 flex items-center justify-center text-white font-bold text-xs">✓</span>
                                    </button>
                                </div>
                            </div>

                            <div class="pt-4 border-t border-slate-200 dark:border-slate-700 mt-2">
                                <div class="flex justify-between items-center mb-3">
                                    <h3 class="text-xs font-bold text-slate-500 uppercase flex justify-between items-center gap-2">
                                        Checklists
                                        <span v-if="activeCard.data.checklistStats" class="text-[10px] bg-slate-200 dark:bg-slate-700 px-1.5 py-0.5 rounded text-slate-600 dark:text-slate-300">
                                            {{ Math.round((activeCard.data.checklistStats?.done || 0) / (activeCard.data.checklistStats?.total || 1) * 100) }}%
                                        </span>
                                    </h3>
                                    <button @click="createChecklist" class="text-[10px] bg-slate-100 dark:bg-slate-800 hover:bg-blue-100 hover:text-blue-600 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-600 px-2 py-0.5 rounded transition font-bold">
                                        + New
                                    </button>
                                </div>
                                
                                <div v-for="(cl, clIdx) in activeCardMeta.checklists" :key="cl.id" class="mb-6 group/list">
                                    <div class="flex justify-between items-end mb-1">
                                        <div class="flex items-center gap-2 overflow-hidden">
                                            <div class="text-xs font-bold text-slate-700 dark:text-slate-200 truncate" :title="cl.name">{{ cl.name }}</div>
                                            <button @click="deleteChecklist(clIdx)" class="text-slate-400 hover:text-red-500 opacity-0 group-hover/list:opacity-100 transition px-1" title="Delete Checklist">×</button>
                                        </div>
                                        <div class="text-[10px] text-slate-400 font-mono shrink-0">
                                            {{ cl.items.filter(i => i.state === 'complete').length }}/{{ cl.items.length }}
                                        </div>
                                    </div>
                                    
                                    <div class="h-1 bg-slate-200 dark:bg-slate-600 rounded-full overflow-hidden mb-2">
                                        <div class="h-full bg-blue-500 transition-all duration-300" 
                                            :style="{ width: (cl.items.length ? (cl.items.filter(i => i.state === 'complete').length / cl.items.length * 100) : 0) + '%' }">
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-1">
                                        <div v-for="(item, itemIdx) in cl.items" :key="item.id" 
                                            class="flex items-start gap-2 text-xs group/item cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded -mx-1.5 transition-colors select-none">
                                            
                                            <div @click="toggleCheckItem(clIdx, itemIdx)" 
                                                class="mt-0.5 w-3.5 h-3.5 border rounded flex items-center justify-center transition-colors shrink-0"
                                                :class="item.state === 'complete' ? 'bg-blue-500 border-blue-500 text-white' : 'border-slate-300 dark:border-slate-500 bg-white dark:bg-slate-900 group-hover/item:border-blue-400'">
                                                <svg v-if="item.state === 'complete'" class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M5 13l4 4L19 7"></path></svg>
                                            </div>
                                            
                                            <span @click="toggleCheckItem(clIdx, itemIdx)" 
                                                class="flex-1 transition-opacity leading-snug break-words" 
                                                :class="{'line-through text-slate-400 dark:text-slate-500': item.state === 'complete', 'text-slate-700 dark:text-slate-300': item.state !== 'complete'}">
                                                {{ item.name }}
                                            </span>
                                            
                                            <button @click.stop="deleteChecklistItem(clIdx, itemIdx)" class="opacity-0 group-hover/item:opacity-100 text-slate-400 hover:text-red-500 transition">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="mt-2 pl-6">
                                        <input 
                                            type="text" 
                                            placeholder="Add item..." 
                                            class="w-full text-xs bg-transparent border-b border-transparent hover:border-slate-300 focus:border-blue-500 dark:hover:border-slate-600 dark:text-slate-300 px-0 py-1 outline-none transition-colors placeholder-slate-400"
                                            @keyup.enter="addChecklistItem($event, clIdx)"
                                        >
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Start Date</h3>
                                <input type="date" v-model="activeCard.data.startDate" @change="handleStartDateChange" 
                                    class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Due Date</h3>
                                <input type="date" v-model="activeCard.data.dueDate" @change="handleDueDateChange" 
                                       class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-sm text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div class="pt-4 border-t border-slate-200 dark:border-slate-700 space-y-2">
                                <h3 class="text-xs font-bold text-slate-500 uppercase mb-2">Actions</h3>
                                
                                <select @change="moveCardToBoard" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded px-3 py-2 text-sm text-slate-700 dark:text-slate-200 mb-2 outline-none">
                                    <option value="" disabled selected>Move to board...</option>
                                    <option v-for="b in availableBoards.filter(b => b.id !== currentBoardId)" :key="b.id" :value="b.id">{{ b.name }}</option>
                                </select>

                                <button @click="archiveActiveCard" class="w-full text-left px-3 py-2 text-sm bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 rounded transition text-slate-700 dark:text-slate-200 font-medium flex items-center gap-2">
                                    <icon name="archive" class="w-4 h-4"></icon>
                                    Archive Card
                                </button>
                                <button @click="deleteActiveCard" class="w-full text-left px-3 py-2 text-sm bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 rounded transition text-red-600 dark:text-red-400 font-medium flex items-center gap-2">
                                    <icon name="trash" class="w-4 h-4"></icon>
                                    Delete Card
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="showBoardSelector" class="fixed inset-0 bg-slate-900 z-[60] flex flex-col animate-fade-in">
            <div class="p-6 flex justify-end">
                <button @click="showBoardSelector = false" class="text-slate-400 hover:text-white transition">
                    <icon name="close" class="w-8 h-8"></icon>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-8">
                <div class="max-w-5xl mx-auto">
                    <h1 class="text-4xl font-bold text-white mb-2 text-center">Your Boards</h1>
                    <p class="text-slate-400 text-center mb-12">Select a workspace or create a new one</p>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        <div @click="showCreateBoardModal = true; showBoardSelector=false" class="aspect-video border-2 border-dashed border-slate-700 hover:border-yellow-400 hover:bg-slate-800 rounded-xl flex flex-col items-center justify-center cursor-pointer transition group text-slate-500 hover:text-blue-400">
                            <icon name="plus" class="w-12 h-12 mb-2 opacity-50 group-hover:scale-110 transition"></icon>
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
                </a>
                <span class="text-slate-300">—</span> 
                <a href="https://github.com/austinginder/beckon" target="_blank" class="text-slate-600 hover:text-slate-400 transition text-xs font-bold tracking-widest uppercase font-mono relative group">
                    Github
                </a>
                <span v-if="updateAvailable" class="text-slate-700">|</span>
                <button v-if="updateAvailable" @click="performUpdate" class="flex items-center gap-2 group relative bg-amber-500/10 hover:bg-amber-500/20 px-2 py-1 rounded transition text-amber-500 animate-pulse">
                    <icon name="cloud" class="w-4 h-4"></icon>
                    <span class="text-xs font-bold tracking-widest uppercase font-mono">Update Available: {{ latestVersion }}</span>
                </button>
            </div>
        </div>
        <div v-if="showCreateBoardModal" class="fixed inset-0 bg-black/75 flex items-center justify-center z-[80] p-4 backdrop-blur-sm" @click.self="showCreateBoardModal = false">
            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-md overflow-hidden animate-fade-in-up border border-slate-200 dark:border-slate-700">
                <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-center">
                    <h3 class="font-bold text-slate-700 dark:text-slate-200">Create New Board</h3>
                    <button @click="showCreateBoardModal = false" class="text-slate-400 hover:text-red-500">
                        <icon name="close" class="w-5 h-5"></icon>
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
                        <icon name="close" class="w-5 h-5"></icon>
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
                            <icon name="warning" class="w-4 h-4"></icon>
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
        <div v-if="contextMenu.show" 
            class="fixed z-[100] bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-slate-200 dark:border-slate-700 w-48 py-1 overflow-hidden animate-fade-in"
            :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
            @click.stop>
            
            <div class="px-3 py-2 border-b border-slate-100 dark:border-slate-700 mb-1">
                <div class="text-xs font-bold text-slate-500 truncate">Actions</div>
            </div>

            <button @click="openCardModal(contextMenu.lIdx, contextMenu.cIdx); closeContextMenu()" 
                    class="w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center gap-2">
                <icon name="pencil" class="w-4 h-4 text-slate-400"></icon>
                Edit Card
            </button>

            <button @click="openCoverModal(contextMenu.lIdx, contextMenu.cIdx); closeContextMenu()" 
                    class="w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center gap-2">
                <icon name="image" class="w-4 h-4 text-slate-400"></icon>
                Change Cover
            </button>

            <button v-if="hasCover(contextMenu.lIdx, contextMenu.cIdx)" 
                    @click="removeCover(contextMenu.lIdx, contextMenu.cIdx); closeContextMenu()" 
                    class="w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center gap-2">
                <icon name="close" class="w-4 h-4 text-slate-400"></icon>
                Remove Cover
            </button>

            <button @click="cloneCard(contextMenu.lIdx, contextMenu.cIdx); closeContextMenu()" 
                    class="w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center gap-2">
                <icon name="duplicate" class="w-4 h-4 text-slate-400"></icon>
                Duplicate
            </button>

            <button @click="archiveCardContext(contextMenu.lIdx, contextMenu.cIdx); closeContextMenu()" 
                    class="w-full text-left px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center gap-2">
                <icon name="archive" class="w-4 h-4 text-slate-400"></icon>
                Archive
            </button>

            <div class="border-t border-slate-100 dark:border-slate-700 my-1"></div>

            <button @click="deleteCardContext(contextMenu.lIdx, contextMenu.cIdx); closeContextMenu()" 
                    class="w-full text-left px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2">
                <icon name="trash" class="w-4 h-4 text-red-400"></icon>
                Delete
            </button>
        </div>

        <div v-if="showCoverModalState" class="fixed inset-0 bg-black/75 flex items-center justify-center z-[110] p-4 backdrop-blur-sm" @click.self="showCoverModalState = false">
            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-2xl h-[70vh] flex flex-col overflow-hidden animate-fade-in-up border border-slate-200 dark:border-slate-700">
                <div class="p-4 border-b dark:border-slate-700 bg-slate-50 dark:bg-slate-900 flex justify-between items-center">
                    <h3 class="font-bold text-slate-700 dark:text-slate-200">Select Cover Image</h3>
                    <button @click="showCoverModalState = false" class="text-slate-400 hover:text-red-500">
                        <icon name="close" class="w-5 h-5"></icon>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-4 bg-slate-100 dark:bg-slate-900/50">
                    <div v-if="availableCovers.length === 0" class="text-center text-slate-500 py-10">
                        No images found in uploads.
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                        <div v-for="img in availableCovers" :key="img" 
                             @click="setCover(img)"
                             class="aspect-square rounded-lg border-2 border-transparent hover:border-blue-500 cursor-pointer overflow-hidden relative group bg-slate-200 dark:bg-slate-700">
                             <img :src="img" class="w-full h-full object-cover">
                             <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="showImportModal" class="fixed inset-0 bg-black/80 flex items-center justify-center z-[90] p-4 backdrop-blur-sm">
            <div class="bg-white dark:bg-slate-800 rounded-lg shadow-2xl w-full max-w-lg overflow-hidden border border-slate-200 dark:border-slate-700">
                
                <div class="p-6">
                    <h3 class="text-xl font-bold text-slate-800 dark:text-white mb-4">
                        Importing: {{ importMeta.boardName }}
                    </h3>

                    <div v-if="importStep === 'preview'" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="bg-slate-100 dark:bg-slate-700 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-blue-600">{{ importMeta.cardCount }}</div>
                                <div class="text-xs text-slate-500 uppercase font-bold">Cards</div>
                            </div>
                            <div class="bg-slate-100 dark:bg-slate-700 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-green-600">{{ importMeta.attachments.length }}</div>
                                <div class="text-xs text-slate-500 uppercase font-bold">Attachments</div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">
                                Private Board Access (Optional)
                            </label>
                            <textarea 
                                v-model="curlInput"
                                class="w-full bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded p-2 text-[10px] font-mono h-20 focus:ring-2 focus:ring-blue-500 outline-none resize-none placeholder-slate-400"
                                placeholder="Paste 'Copy as cURL' command here to import private attachments..."
                            ></textarea>
                            <div class="text-[10px] text-slate-400 mt-1">
                                Go to Network Tab > Right click any Trello request > Copy > Copy as cURL
                            </div>
                        </div>
                        
                        <div class="flex justify-end gap-3 mt-6">
                            <button @click="showImportModal = false" class="px-4 py-2 text-slate-500 hover:text-slate-700">Cancel</button>
                            <button @click="runImportProcess" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-6 py-2 rounded shadow-lg">
                                Start Import
                            </button>
                        </div>
                    </div>

                    <div v-if="importStep === 'importing'" class="space-y-4">
                        <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="h-full bg-blue-500 transition-all duration-300" 
                                :style="{ width: (importProgress.current / importProgress.total * 100) + '%' }"></div>
                        </div>
                        <div class="flex justify-between text-xs font-mono text-slate-500">
                            <span>{{ importProgress.status }}</span>
                            <span>{{ Math.round((importProgress.current / importProgress.total * 100)) }}%</span>
                        </div>
                    </div>

                    <div v-if="importStep === 'complete'" class="text-center py-8">
                        <icon name="check" class="w-16 h-16 text-green-500 mx-auto mb-4"></icon>
                        <div class="text-lg font-bold text-white">Import Complete!</div>
                    </div>

                </div>
            </div>
        </div>
    </div>
<script>
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;

    const iconPaths = {
        // UI Basics
        'close': 'M6 18L18 6M6 6l12 12',
        'chevron-down': 'M19 9l-7 7-7-7',
        'plus': 'M12 4v16m8-8H4',
        'check': 'M5 13l4 4L19 7',
        'trash': 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        'pencil': 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        'warning': 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
        
        // Actions
        'cloud': 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12',
        'archive': 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
        'duplicate': 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z',
        'move': 'M14 5l7 7m0 0l-7 7m7-7H3',
        'undo': 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6',
        
        // Content Types
        'users': 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        'text': 'M4 6h16M4 12h16M4 18h7',
        'paperclip': 'M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13',
        'chat': 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
        'check-circle': 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'clock': 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        'image': 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
        
        // Interface State
        'sun': 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z',
        'moon': 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z',
        'sidebar': 'M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m-2 10V9a2 2 0 012-2h2a2 2 0 012 2v10a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'eye': 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
        'code': 'M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'minimize': 'M5 11l7-7 7 7M5 19l7-7 7 7',
        'maximize': 'M19 13l-7 7-7-7M19 5l-7 7-7-7'
    };

    const Icon = (props) => Vue.h('svg', {
        class: props.class || 'w-4 h-4',
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor'
        }, [Vue.h('path', {
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'stroke-width': '2',
            d: iconPaths[props.name] || ''
        })]
    );

    createApp({
        setup() {
            // --- Configuration & Constants ---
            marked.setOptions({ gfm: true, breaks: true });
            const labelColors = ['red', 'orange', 'yellow', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink', 'slate'];
            const version = '<?php echo BECKON_VERSION; ?>';

            // --- Reactive State: Core ---
            const currentUser = ref(JSON.parse(localStorage.getItem('beckon_user')) || { name: 'Guest', initials: 'G', color: 'slate' });
            const darkMode = ref(localStorage.getItem('beckon_darkMode') === 'true');
            const syncState = ref('synced'); // 'synced', 'saving', 'offline'
            
            // --- Reactive State: Board Data ---
            const currentBoardId = ref(localStorage.getItem('beckon_last_board') || '');
            const availableBoards = ref([]);
            const boardData = ref({ title: 'Loading...', lists: [], archive: [], users: [] });
            
            // --- Reactive State: UI & Modals ---
            const isModalOpen = ref(false);           // Card Edit Modal
            const isSidebarOpen = ref(false);         // Card Edit Sidebar
            const isActivityOpen = ref(localStorage.getItem('beckon_activity_open') !== 'false');
            const isActivityMaximized = ref(false);
            const isUsersModalOpen = ref(false);
            const isBoardSwitcherOpen = ref(false);
            const showBoardSelector = ref(false);     // Fullscreen selector
            const showCreateBoardModal = ref(false);
            const showRenameModal = ref(false);
            const showImportModal = ref(false);
            const showCoverModalState = ref(false);
            const isArchiveOpen = ref(false);
            
            // --- Reactive State: Inputs & Temporary ---
            const boardSearch = ref('');
            const archiveSearch = ref('');
            const newBoardTitle = ref('');
            const tempBoardTitle = ref('');
            const activityTab = ref('comments');
            const newComment = ref('');
            const editCommentText = ref('');
            const editingCommentId = ref(null);
            const revisionIndex = ref(-1);
            const splitPaneRatio = ref(50);
            const editingUser = ref(null);
            const curlInput = ref('');
            
            // --- Reactive State: Drag & Drop / Context ---
            const dragSource = ref(null);
            const dragTarget = ref(null);
            const hoveredCard = ref({ l: null, c: null });
            const isDraggingFile = ref(false);
            const contextMenu = ref({ show: false, x: 0, y: 0, lIdx: null, cIdx: null });
            const activeCoverTarget = ref({ lIdx: null, cIdx: null });
            const availableCovers = ref([]);

            // --- Reactive State: Active Card Context ---
            const activeCard = ref({ listIndex: null, cardIndex: null, data: {} });
            const activeCardMeta = ref({ comments: [], activity: [], revisions: [], assigned_to: [], checklists: [] });
            const originalDescription = ref('');
            
            // --- Reactive State: System/Import ---
            const updateAvailable = ref(false);
            const latestVersion = ref('');
            const importStep = ref('preview');
            const importProgress = ref({ current: 0, total: 0, status: '' });
            const importMeta = ref({ boardName: '', cardCount: 0, lists: [], cards: [], attachments: [], checklists: [], actions: [] });

            // --- Refs for DOM Elements ---
            const boardSearchInput = ref(null);
            const archiveSearchInput = ref(null);
            const splitPaneContainer = ref(null);
            let debounceTimer = null;


            // ====================================================================================
            // 1. API LAYER
            // ====================================================================================
            
            const api = async (action, payload = {}) => {
                syncState.value = 'saving';
                try {
                    const res = await fetch(`?action=${action}&board=${currentBoardId.value}`, { 
                        method: 'POST', 
                        headers: { 'Content-Type': 'application/json' }, 
                        body: JSON.stringify(payload) 
                    });
                    if (!res.ok) throw new Error();
                    setTimeout(() => syncState.value = 'synced', 500);
                    return await res.json();
                } catch (e) { 
                    syncState.value = 'offline'; 
                    throw e; 
                }
            };

            const saveLocal = () => localStorage.setItem(`beckon_${currentBoardId.value}`, JSON.stringify(boardData.value));
            
            const persistLayout = () => { 
                saveLocal(); 
                api('save_layout', boardData.value).then(() => { 
                    const b = availableBoards.value.find(b => b.id === currentBoardId.value); 
                    if(b) b.name = boardData.value.title; 
                }).catch(()=>{}); 
            };

            const persistCardDesc = (c) => { 
                if(c.id) { 
                    saveLocal(); 
                    api('save_card', {id: c.id, description: c.description}).catch(()=>{}); 
                } 
            };

            const persistMeta = (id, meta) => { 
                if(id) api('save_card_meta', {id, meta}).catch(()=>{}); 
            };

            const loadData = async () => {
                // Try network first
                try { 
                    const data = await api('load');
                    if(data.lists) { 
                        boardData.value = data; 
                        saveLocal(); 
                        return; 
                    } 
                } catch(e){}
                // Fallback to local
                const local = localStorage.getItem(`beckon_${currentBoardId.value}`);
                boardData.value = local ? JSON.parse(local) : { lists: [{ id: 'l1', title: 'Start', cards: [] }], users: [] };
            };


            // ====================================================================================
            // 2. COMPUTED PROPERTIES
            // ====================================================================================

            const filteredBoards = computed(() => {
                if (!boardSearch.value) return availableBoards.value;
                const q = boardSearch.value.toLowerCase();
                return availableBoards.value.filter(b => b.name.toLowerCase().includes(q) || b.id.toLowerCase().includes(q));
            });

            const filteredArchive = computed(() => {
                if (!boardData.value.archive) return [];
                const q = archiveSearch.value.toLowerCase();
                return boardData.value.archive.filter(c => c.title.toLowerCase().includes(q) || (c.description || '').toLowerCase().includes(q));
            });

            const compiledMarkdown = computed(() => {
                const text = revisionIndex.value > -1 ? activeCardMeta.value.revisions[revisionIndex.value].text : activeCard.value.data.description;
                return marked.parse(text || '').replace(/disabled=""/g, '').replace(/disabled/g, '');
            });

            const editorStats = computed(() => {
                const text = activeCard.value.data.description || '';
                const words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
                return { words, readTime: Math.ceil(words / 200) };
            });

            const syncStatusColor = computed(() => ({'synced':'bg-green-500','saving':'bg-yellow-500','offline':'bg-red-500'}[syncState.value]));
            const syncMessage = computed(() => syncState.value.toUpperCase());


            // ====================================================================================
            // 3. BOARD ACTIONS
            // ====================================================================================

            const fetchBoards = async () => { 
                try { availableBoards.value = (await (await fetch('?action=list_boards')).json()).boards; } catch(e){} 
            };

            const switchBoard = async () => { 
                clearTimeout(debounceTimer); 
                localStorage.setItem('beckon_last_board', currentBoardId.value); 
                await loadData(); 
            };

            const selectBoard = async (id) => { 
                currentBoardId.value = id;
                boardSearch.value = ''; 
                await switchBoard();
                showBoardSelector.value = false;
                isBoardSwitcherOpen.value = false;
            };

            const createBoard = async () => {
                if (!newBoardTitle.value.trim()) return;
                const title = newBoardTitle.value;
                const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
                
                await api('create_board', {title, slug}); 
                await fetchBoards(); 
                
                currentBoardId.value = slug;
                await switchBoard();
                
                newBoardTitle.value = '';
                showCreateBoardModal.value = false;
                showBoardSelector.value = false;
            };

            const saveBoardTitle = async () => {
                if (!tempBoardTitle.value.trim()) return;
                try {
                    const res = await api('rename_board', { board: currentBoardId.value, title: tempBoardTitle.value });
                    boardData.value.title = res.name;
                    
                    if (res.id !== currentBoardId.value) {
                        const oldId = currentBoardId.value;
                        currentBoardId.value = res.id;
                        // Migrate LocalStorage
                        const oldStore = localStorage.getItem(`beckon_${oldId}`);
                        if (oldStore) {
                            localStorage.setItem(`beckon_${res.id}`, oldStore);
                            localStorage.removeItem(`beckon_${oldId}`);
                        }
                        // Update URL
                        const url = new URL(window.location);
                        url.searchParams.set('board', res.id);
                        window.history.pushState({}, '', url);
                    }
                    // Update list
                    const b = availableBoards.value.find(b => b.id === currentBoardId.value);
                    if(b) { b.id = res.id; b.name = res.name; } // bugfix: look by old ID isn't possible if we just changed currentBoardId, but logic mostly holds for renaming
                    else { await fetchBoards(); }

                    showRenameModal.value = false;
                } catch (e) { alert(e.message); }
            };

            const deleteBoard = async () => { 
                if(confirm("Delete Board?")) { 
                    await api('delete_board', {board: currentBoardId.value});
                    await fetchBoards();
                    if (availableBoards.value.length > 0) {
                        currentBoardId.value = availableBoards.value[0].id;
                        await switchBoard();
                    } else {
                        currentBoardId.value = '';
                        boardData.value = { title: 'No Boards', lists: [] };
                        showBoardSelector.value = true;
                    }
                } 
            };


            // ====================================================================================
            // 4. CARD ACTIONS
            // ====================================================================================

            const addList = () => { boardData.value.lists.push({ id: 'l'+Date.now(), title: 'New List', cards: [] }); persistLayout(); };
            const deleteList = (i) => { if(confirm('Delete list?')) { boardData.value.lists.splice(i, 1); persistLayout(); } };
            
            const addCard = (lIdx) => {
                const now = new Date().toISOString();
                const c = { id: Date.now(), title: 'New Card', description: '', labels: [], dueDate: null, assignees: [], commentCount: 0, created_at: now };
                boardData.value.lists[lIdx].cards.push(c);
                persistLayout(); 
                persistCardDesc(c);
                persistMeta(c.id, { created_at: now, comments: [], activity: [{ text: 'Card created', date: now }], revisions: [], assigned_to: [] });
            };

            const deleteActiveCard = () => {
                if(!confirm("Delete?")) return;
                const { id } = activeCard.value.data;
                if (activeCard.value.listIndex === 'archive') boardData.value.archive.splice(activeCard.value.cardIndex, 1);
                else boardData.value.lists[activeCard.value.listIndex].cards.splice(activeCard.value.cardIndex, 1);
                persistLayout();
                api('delete_card', { id }); 
                isModalOpen.value = false;
            };

            const cloneCard = (lIdx, cIdx) => {
                const original = boardData.value.lists[lIdx].cards[cIdx];
                const newCard = JSON.parse(JSON.stringify(original));
                newCard.id = Date.now();
                newCard.title += ' (Copy)';
                boardData.value.lists[lIdx].cards.splice(cIdx + 1, 0, newCard);
                persistLayout();
                
                if (original.description) {
                    newCard.description = original.description;
                    persistCardDesc(newCard);
                }
                persistMeta(newCard.id, { comments: [], activity: [{ text: 'Card duplicated', date: new Date().toISOString() }], revisions: [], assigned_to: [] });
            };

            const moveCardToBoard = async (e) => {
                const targetBoardId = e.target.value;
                const targetBoardName = availableBoards.value.find(b => b.id === targetBoardId)?.name || 'target board';
                if (!confirm(`Move this card to "${targetBoardName}"?`)) { e.target.value = ""; return; }
                try {
                    await api('move_card_to_board', { id: activeCard.value.data.id, target_board: targetBoardId });
                    if (activeCard.value.listIndex === 'archive') boardData.value.archive.splice(activeCard.value.cardIndex, 1);
                    else boardData.value.lists[activeCard.value.listIndex].cards.splice(activeCard.value.cardIndex, 1);
                    saveLocal();
                    closeModal();
                } catch (err) { alert("Failed: " + err.message); e.target.value = ""; }
            };

            const openCardModal = async (lIdx, cIdx) => {
                const isArchived = lIdx === 'archive';
                const card = isArchived ? boardData.value.archive[cIdx] : boardData.value.lists[lIdx].cards[cIdx];
                activeCard.value = { listIndex: lIdx, cardIndex: cIdx, data: card };
                originalDescription.value = card.description || ''; 
                revisionIndex.value = -1;
                isSidebarOpen.value = false; 
                isModalOpen.value = true;
                activeCardMeta.value = { comments: [], activity: [], revisions: [], assigned_to: [], checklists: [] };
                
                if(card.id) try { 
                    const m = await api('load_card_meta', {id: card.id});
                    activeCardMeta.value = { 
                        comments: m.comments||[], activity: m.activity||[], revisions: m.revisions||[], assigned_to: m.assigned_to||[], checklists: m.checklists||[]
                    };
                } catch(e){}
            };

            const debouncedSaveCard = () => { 
                saveLocal(); 
                syncState.value='saving'; 
                clearTimeout(debounceTimer); 
                debounceTimer = setTimeout(() => { persistCardDesc(activeCard.value.data); syncState.value='synced'; }, 1000); 
            };

            const closeModal = () => { 
                // Revision Logic
                if (activeCard.value.data.description !== originalDescription.value) {
                    const newRev = { id: Date.now().toString(), date: new Date().toISOString(), text: originalDescription.value, user: currentUser.value.name };
                    activeCardMeta.value.revisions = activeCardMeta.value.revisions || [];
                    activeCardMeta.value.revisions.unshift(newRev);
                    if (activeCardMeta.value.revisions.length > 50) activeCardMeta.value.revisions = activeCardMeta.value.revisions.slice(0, 50);
                    activeCardMeta.value.activity.unshift({ text: 'Modified description', date: new Date().toISOString() });
                    persistMeta(activeCard.value.data.id, activeCardMeta.value);
                }
                isModalOpen.value = false;
                persistLayout(); 
                persistCardDesc(activeCard.value.data); 
            };


            // ====================================================================================
            // 5. CHECKLISTS, COMMENTS, & ATTACHMENTS
            // ====================================================================================

            const createChecklist = () => {
                const name = prompt("New Checklist Name:", "Checklist");
                if (!name) return;
                activeCardMeta.value.checklists = activeCardMeta.value.checklists || [];
                activeCardMeta.value.checklists.push({ id: Date.now().toString(), name: name, items: [] });
                persistMeta(activeCard.value.data.id, activeCardMeta.value);
                updateCardStats();
            };

            const updateCardStats = () => {
                let total = 0, done = 0;
                if (activeCardMeta.value.checklists) {
                    activeCardMeta.value.checklists.forEach(cl => cl.items.forEach(i => { total++; if(i.state === 'complete') done++; }));
                }
                activeCard.value.data.checklistStats = total > 0 ? { total, done } : null;
                persistLayout();
            };

            const addComment = () => {
                if (!newComment.value.trim()) return;
                activeCardMeta.value.comments.unshift({ 
                    id: Date.now(), text: newComment.value, date: new Date().toISOString(), 
                    user_id: currentUser.value.id, user: { ...currentUser.value } 
                });
                activeCard.value.data.commentCount = (activeCard.value.data.commentCount || 0) + 1;
                newComment.value = ''; 
                persistMeta(activeCard.value.data.id, activeCardMeta.value);
            };

            const uploadFile = (file) => {
                if (!file) return;
                isDraggingFile.value = false;
                const fd = new FormData(); fd.append('file', file);
                fetch(`?action=upload&board=${currentBoardId.value}`, {method:'POST', body:fd})
                    .then(r=>r.json())
                    .then(res => {
                        if(res.url) {
                            const el = document.getElementById('editor-textarea');
                            const v = activeCard.value.data.description || '';
                            const start = el ? el.selectionStart : v.length;
                            const end = el ? el.selectionEnd : v.length;
                            activeCard.value.data.description = v.slice(0, start) + `\n![Image](${res.url})\n` + v.slice(end);
                            debouncedSaveCard();
                        }
                    });
            };


            // ====================================================================================
            // 6. DRAG & DROP
            // ====================================================================================

            const startDrag = (e, l, c) => { dragSource.value = { l, c }; e.dataTransfer.effectAllowed = 'move'; };
            
            const onCardDragOver = (e, l, c) => {
                if (!dragSource.value || (dragSource.value.l === l && dragSource.value.c === c)) { dragTarget.value = null; return; }
                const rect = e.currentTarget.getBoundingClientRect();
                dragTarget.value = { l, c, pos: e.clientY < (rect.top + rect.height/2) ? 'top' : 'bottom' };
            };
            
            const onListDragOver = (e, l) => { if (dragSource.value && !(dragTarget.value?.l === l && dragTarget.value?.c !== null)) dragTarget.value = { l, c: null, pos: 'bottom' }; };
            
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


            // ====================================================================================
            // 7. USER MANAGEMENT
            // ====================================================================================

            const getUser = (id) => boardData.value.users?.[id] || null;
            const getUserAvatar = (id) => {
                const u = getUser(id);
                if (!u) return null;
                return u.avatarFile || (u.avatarHash ? `https://trello-members.s3.amazonaws.com/${u.id}/${u.avatarHash}/170.png` : null);
            };
            const getUserInitials = (id) => getUser(id)?.initials || null;
            const getUserName = (id) => getUser(id)?.fullName || null;

            const saveUser = () => {
                const parts = currentUser.value.name.trim().split(' ');
                currentUser.value.initials = parts.length > 1 ? (parts[0][0] + parts[parts.length-1][0]).toUpperCase() : currentUser.value.name.slice(0,2).toUpperCase();
                localStorage.setItem('beckon_user', JSON.stringify(currentUser.value));
            };

            const saveUserEntry = async () => {
                if (!editingUser.value.fullName) return alert("Name required");
                if (!boardData.value.users) boardData.value.users = {};
                boardData.value.users[editingUser.value.id] = editingUser.value;
                await api('save_users', { users: boardData.value.users });
                editingUser.value = null;
            };

            
            // ====================================================================================
            // 7b. CONTEXT & ARCHIVE HELPERS (Defined here so onMounted can use them)
            // ====================================================================================

            const archiveCardContext = (l, c) => {
                const card = boardData.value.lists[l].cards[c];
                if(!confirm(`Archive "${card.title}"?`)) return;
                
                // 1. Move to Archive
                boardData.value.archive.unshift(card);
                boardData.value.lists[l].cards.splice(c, 1);
                persistLayout();
                
                // 2. Log Activity
                api('load_card_meta', {id: card.id}).then(m => {
                    m.activity = m.activity || [];
                    m.activity.unshift({ text: 'Archived card (Shortcut)', date: new Date().toISOString() });
                    persistMeta(card.id, m);
                });
            };

            // ====================================================================================
            // 8. LIFECYCLE & WATCHERS
            // ====================================================================================

            watch(darkMode, (v) => { document.documentElement.classList.toggle('dark', v); localStorage.setItem('beckon_darkMode', v); }, { immediate: true });
            watch(isActivityOpen, (v) => localStorage.setItem('beckon_activity_open', v));

            onMounted(async () => { 
                await fetchBoards();
                // Check updates
                try {
                    const res = await api('check_updates');
                    if (res.update_available) { updateAvailable.value = true; latestVersion.value = res.latest_version; }
                } catch (e) { console.error("Update check failed", e); }

                if (availableBoards.value.length === 0) {
                    showBoardSelector.value = true;
                } else {
                    if (!currentBoardId.value || !availableBoards.value.find(b => b.id === currentBoardId.value)) {
                        currentBoardId.value = availableBoards.value[0].id;
                    }
                    await switchBoard(); 
                }

                window.addEventListener('click', () => { if (contextMenu.value.show) contextMenu.value.show = false; });
                
                // Keyboard Shortcuts
                window.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        if (showCreateBoardModal.value) showCreateBoardModal.value = false;
                        else if (showRenameModal.value) showRenameModal.value = false;
                        else if (isBoardSwitcherOpen.value) isBoardSwitcherOpen.value = false;
                        else if (isModalOpen.value) closeModal();
                        else if (showBoardSelector.value) showBoardSelector.value = false;
                    }
                    if (['INPUT','TEXTAREA'].includes(document.activeElement.tagName) || document.activeElement.isContentEditable) return;
                    if (isModalOpen.value || showCreateBoardModal.value || showRenameModal.value || showBoardSelector.value || isUsersModalOpen.value) return;

                    if (hoveredCard.value.l !== null && hoveredCard.value.c !== null) {
                        if (e.key === 'Enter') { e.preventDefault(); openCardModal(hoveredCard.value.l, hoveredCard.value.c); }
                        if (e.key.toLowerCase() === 'c') { 
                            e.preventDefault(); 
                            const c = boardData.value.lists[hoveredCard.value.l].cards[hoveredCard.value.c];
                            if(confirm(`Archive "${c.title}"?`)) {
                                boardData.value.archive.unshift(c);
                                boardData.value.lists[hoveredCard.value.l].cards.splice(hoveredCard.value.c, 1);
                                persistLayout();
                                hoveredCard.value = { l: null, c: null };
                            }
                        }
                    }
                });
            });


            // ====================================================================================
            // 9. EXPORT
            // ====================================================================================
            return {
                // Config & State
                version, currentUser, darkMode, syncState, syncStatusColor, syncMessage,
                boardData, currentBoardId, availableBoards, 
                
                // UI Controls
                isModalOpen, isSidebarOpen, isActivityOpen, isActivityMaximized, activityTab,
                showBoardSelector, isBoardSwitcherOpen, showCreateBoardModal, showRenameModal, 
                showImportModal, showCoverModalState, isArchiveOpen, isUsersModalOpen,
                
                // Search & Filters
                boardSearch, boardSearchInput, filteredBoards, 
                archiveSearch, archiveSearchInput, filteredArchive,
                
                // Card Editor Data
                activeCard, activeCardMeta, editorStats, compiledMarkdown, originalDescription, revisionIndex,
                splitPaneRatio, splitPaneContainer, isDraggingFile,
                
                // Forms
                newBoardTitle, tempBoardTitle, newComment, editingCommentId, editCommentText,
                editingUser, curlInput,
                
                // Drag & Drop
                dragTarget, startDrag, onDrop, onCardDragOver, onListDragOver, hoveredCard,

                // Trello Import
                importMeta, importStep, importProgress,

                // Methods: Board
                selectBoard, switchBoard, createBoard, deleteBoard, saveBoardTitle, openRenameModal: () => { tempBoardTitle.value = boardData.value.title; showRenameModal.value = true; },
                toggleBoardSwitcher: () => { isBoardSwitcherOpen.value = !isBoardSwitcherOpen.value; if(isBoardSwitcherOpen.value) nextTick(() => boardSearchInput.value?.focus()); },

                // Methods: Lists
                addList, deleteList, persistLayout,

                // Methods: Cards
                addCard, deleteActiveCard, openCardModal, closeModal, moveCardToBoard, debouncedSaveCard, 
                cloneCard, 
                
                // Methods: Comments/Activity
                addComment, deleteComment: (id) => { 
                    if(!confirm('Delete?')) return; 
                    activeCardMeta.value.comments = activeCardMeta.value.comments.filter(c=>c.id!==id); 
                    activeCard.value.data.commentCount--; 
                    persistMeta(activeCard.value.data.id, activeCardMeta.value); 
                },
                startEditComment: (c) => { editingCommentId.value = c.id; editCommentText.value = c.text; },
                saveEditComment: () => { 
                    const c = activeCardMeta.value.comments.find(c=>c.id===editingCommentId.value);
                    if(c){ c.text=editCommentText.value; c.editedDate=new Date().toISOString(); persistMeta(activeCard.value.data.id, activeCardMeta.value); }
                    editingCommentId.value=null; 
                },
                restoreRevision: () => { 
                    if(revisionIndex.value>-1 && confirm("Restore?")) { 
                        activeCard.value.data.description = activeCardMeta.value.revisions[revisionIndex.value].text; 
                        revisionIndex.value=-1; 
                        debouncedSaveCard(); 
                    } 
                },

                // Methods: Users
                saveUser, createNewUser: () => { editingUser.value = { id: 'u_'+Date.now(), fullName: '', username: '', initials: '', avatarFile: null }; },
                editUser: (u) => editingUser.value = JSON.parse(JSON.stringify(u)),
                saveUserEntry,
                updateInitials: () => { 
                    if(!editingUser.value.fullName) return; 
                    const p = editingUser.value.fullName.trim().split(' '); 
                    editingUser.value.initials = p.length>1 ? (p[0][0]+p[p.length-1][0]).toUpperCase() : editingUser.value.fullName.slice(0,2).toUpperCase(); 
                },
                handleAvatarUpload: async (e) => { 
                    const fd = new FormData(); fd.append('file', e.target.files[0]);
                    const res = await (await fetch(`?action=upload_avatar&board=${currentBoardId.value}`, {method:'POST', body:fd})).json();
                    if(res.url) editingUser.value.avatarFile = res.url; 
                },
                loginAs: (u) => { currentUser.value = { name: u.fullName, initials: u.initials, color: 'blue', id: u.id }; saveUser(); isUsersModalOpen.value = false; alert(`Logged in as ${u.fullName}`); },
                openUsersModal: () => { isUsersModalOpen.value = true; editingUser.value = null; },
                getUserAvatar, getUserInitials, getUserName,

                // Methods: Utils & Formatting
                labelColors, 
                formatTime: (iso) => dayjs(iso).fromNow(),
                formatDateShort: (iso) => dayjs(iso).format('MMM D'),
                getDueDateColor: (d) => { const diff = dayjs(d).diff(dayjs(), 'day'); return diff < 0 ? 'bg-red-100 text-red-600' : diff <= 2 ? 'bg-yellow-100 text-yellow-600' : 'bg-slate-200 text-slate-500'; },
                hasAttachment: (text) => text && text.indexOf('/uploads/') !== -1,
                getTaskStats: (card) => card.checklistStats || { total: (card.description||'').match(/- \[[ xX]\]/g)?.length||0, done: (card.description||'').match(/- \[[xX]\]/g)?.length||0 },
                renderMarkdown: (t) => marked.parse(t || '').replace(/disabled=""/g, ''),
                
                // Methods: Uploads
                handleFileInput: (e) => uploadFile(e.target.files[0]),
                handleImageDrop: (e) => { isDraggingFile.value=false; const f=e.dataTransfer.files[0]; if(f&&f.type.startsWith('image/')) uploadFile(f); },
                
                // Methods: Checklists
                createChecklist, 
                deleteChecklist: (i) => { if(confirm("Delete checklist?")) { activeCardMeta.value.checklists.splice(i,1); persistMeta(activeCard.value.data.id, activeCardMeta.value); updateCardStats(); } },
                addChecklistItem: (e, i) => { 
                    const v=e.target.value.trim(); if(!v)return; 
                    activeCardMeta.value.checklists[i].items.push({ id:Date.now().toString(), name:v, state:'incomplete' }); 
                    e.target.value=''; persistMeta(activeCard.value.data.id, activeCardMeta.value); updateCardStats(); 
                },
                toggleCheckItem: (cI, iI) => { 
                    const item = activeCardMeta.value.checklists[cI].items[iI]; 
                    item.state = item.state==='complete'?'incomplete':'complete'; 
                    persistMeta(activeCard.value.data.id, activeCardMeta.value); 
                    updateCardStats(); 
                },
                deleteChecklistItem: (cI, iI) => { 
                    activeCardMeta.value.checklists[cI].items.splice(iI, 1); 
                    persistMeta(activeCard.value.data.id, activeCardMeta.value); 
                    updateCardStats(); 
                },

                // Methods: Archive
                toggleArchive: () => { isArchiveOpen.value = !isArchiveOpen.value; if(isArchiveOpen.value) nextTick(() => archiveSearchInput.value?.focus()); },
                restoreArchivedCard: () => {
                    const c = boardData.value.archive[activeCard.value.cardIndex];
                    boardData.value.archive.splice(activeCard.value.cardIndex, 1);
                    boardData.value.lists[0].cards.unshift(c);
                    activeCard.value.listIndex = 0; activeCard.value.cardIndex = 0;
                    persistLayout();
                    activeCardMeta.value.activity.unshift({ text: 'Restored from archive', date: new Date().toISOString() });
                    persistMeta(c.id, activeCardMeta.value);
                },
                archiveActiveCard: () => {
                    if (!confirm("Archive?")) return;
                    boardData.value.archive.unshift(activeCard.value.data);
                    boardData.value.lists[activeCard.value.listIndex].cards.splice(activeCard.value.cardIndex, 1);
                    persistLayout();
                    activeCardMeta.value.activity.unshift({ text: 'Archived', date: new Date().toISOString() });
                    persistMeta(activeCard.value.data.id, activeCardMeta.value);
                    isModalOpen.value = false;
                },

                // Methods: Context Menu & Covers
                contextMenu, 
                showContextMenu: (e, l, c) => { contextMenu.value = { show: true, x: Math.min(e.clientX, window.innerWidth-200), y: Math.min(e.clientY, window.innerHeight-200), lIdx: l, cIdx: c }; },
                closeContextMenu: () => contextMenu.value.show = false,
                deleteCardContext: (l, c) => { 
                    if(!confirm("Delete?")) return; 
                    const id = boardData.value.lists[l].cards[c].id; 
                    boardData.value.lists[l].cards.splice(c, 1); 
                    persistLayout(); 
                    api('delete_card', { id }); 
                },
                archiveCardContext: (l, c) => {
                    const card = boardData.value.lists[l].cards[c];
                    if(!confirm(`Archive "${card.title}"?`)) return;
                    boardData.value.archive.unshift(card);
                    boardData.value.lists[l].cards.splice(c, 1);
                    persistLayout();
                    api('load_card_meta', {id: card.id}).then(m => {
                        m.activity = m.activity || [];
                        m.activity.unshift({ text: 'Archived', date: new Date().toISOString() });
                        persistMeta(card.id, m);
                    });
                },
                hasCover: (l, c) => l!==null && c!==null && !!boardData.value.lists[l].cards[c].coverImage,
                openCoverModal: async (l, c) => { 
                    activeCoverTarget.value = { lIdx: l, cIdx: c }; 
                    try { availableCovers.value = (await api('list_uploads')).files || []; showCoverModalState.value = true; } catch(e){ alert("Load failed"); } 
                },
                setCover: (url) => { 
                    boardData.value.lists[activeCoverTarget.value.lIdx].cards[activeCoverTarget.value.cIdx].coverImage = url; 
                    persistLayout(); 
                    showCoverModalState.value = false; 
                },
                removeCover: (l, c) => { 
                    boardData.value.lists[l].cards[c].coverImage = null; 
                    persistLayout(); 
                },
                
                // Methods: Labels & Dates
                toggleLabel: (c) => { 
                    const l = activeCard.value.data.labels || []; 
                    const idx = l.findIndex(x => (x.color||x) === c); 
                    if (idx > -1) l.splice(idx, 1); else l.push({ color: c, name: c.charAt(0).toUpperCase() + c.slice(1) }); 
                    activeCard.value.data.labels = l; 
                    persistLayout(); 
                },
                handleStartDateChange: () => { activeCardMeta.value.activity.unshift({ text: `Start date changed`, date: new Date().toISOString() }); persistMeta(activeCard.value.data.id, activeCardMeta.value); persistLayout(); },
                handleDueDateChange: () => { activeCardMeta.value.activity.unshift({ text: `Due date changed`, date: new Date().toISOString() }); persistMeta(activeCard.value.data.id, activeCardMeta.value); persistLayout(); },

                // Methods: View & Resize
                toggleView: () => { if(splitPaneRatio.value === 50) splitPaneRatio.value = 100; else if(splitPaneRatio.value === 100) splitPaneRatio.value = 0; else splitPaneRatio.value = 50; },
                startResize: () => { 
                    document.body.style.cursor = 'col-resize'; document.body.style.userSelect = 'none';
                    const stop = () => { document.body.style.cursor = ''; document.body.style.userSelect = ''; window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', stop); };
                    const move = (e) => { 
                        const r = splitPaneContainer.value.getBoundingClientRect(); 
                        let nr = ((e.clientX - r.left) / r.width) * 100; 
                        splitPaneRatio.value = Math.max(0, Math.min(100, nr)); 
                    };
                    window.addEventListener('mousemove', move); window.addEventListener('mouseup', stop);
                },
                handlePreviewClick: (e) => {
                    if (revisionIndex.value > -1) return;
                    if (e.target.tagName === 'INPUT' && e.target.type === 'checkbox') {
                        const all = Array.from(document.querySelectorAll('.markdown-body input[type="checkbox"]'));
                        let c = 0; const idx = all.indexOf(e.target);
                        activeCard.value.data.description = activeCard.value.data.description.replace(/^(\s*[-*]\s\[)([ xX])(\])/gm, (m,p,s,sf) => c++ === idx ? p + (s === ' ' ? 'x' : ' ') + sf : m);
                        persistCardDesc(activeCard.value.data);
                    }
                },

                // Methods: Import (Trello)
                handleImportFile: (e) => {
                    const f = e.target.files[0]; if(!f) return;
                    const r = new FileReader();
                    r.onload = (evt) => {
                        try {
                            const j = JSON.parse(evt.target.result);
                            const atts = [];
                            j.cards.forEach(c => (c.attachments||[]).forEach(a => { if(a.url) atts.push({ cardId: c.id, url: a.url, name: a.name, id: a.id }); }));
                            importMeta.value = { boardName: j.name||'Imported', cardCount: j.cards.length, lists: j.lists, cards: j.cards, checklists: j.checklists||[], actions: j.actions||[], attachments: atts };
                            importStep.value = 'preview'; showImportModal.value = true;
                        } catch(err) { alert("Invalid JSON"); }
                        e.target.value = '';
                    };
                    r.readAsText(f);
                },
                runImportProcess: async () => {
                    importStep.value = 'importing';
                    importProgress.value = { current: 0, total: importMeta.value.attachments.length + 1, status: 'Creating Board Structure...' };
                    let cookies = '';
                    const m = curlInput.value.match(/(?:-b|--cookie)\s+'([^']+)'/); if(m) cookies = m[1];

                    try {
                        const blob = new Blob([JSON.stringify({ 
                            name: importMeta.value.boardName, lists: importMeta.value.lists, cards: importMeta.value.cards, 
                            checklists: importMeta.value.checklists, actions: importMeta.value.actions, members: importMeta.value.members||[], labelNames: importMeta.value.labelNames||[] 
                        })], { type: "application/json" });
                        
                        const fd = new FormData(); fd.append('file', blob, 'import.json');
                        const res = await (await fetch(`?action=import_trello`, { method:'POST', body:fd })).json();
                        if (!res.board) throw new Error("Creation failed");
                        
                        importProgress.value.current++;
                        for (const [i, a] of importMeta.value.attachments.entries()) {
                            importProgress.value.status = `Downloading attachment ${i+1}/${importMeta.value.attachments.length}...`;
                            await api('import_attachment', { board: res.board, card: a.cardId, url: a.url, name: a.name, attachmentId: a.id, cookies });
                            importProgress.value.current++;
                        }
                        
                        importStep.value = 'complete'; importProgress.value.status = 'Done!';
                        await fetchBoards(); currentBoardId.value = res.board; await switchBoard();
                        setTimeout(() => { showImportModal.value = false; curlInput.value = ''; }, 1500);
                    } catch (err) { alert("Import Error: " + err.message); showImportModal.value = false; }
                },

                // System
                updateAvailable, latestVersion, 
                performUpdate: async () => { 
                    if(!confirm(`Install update ${latestVersion.value}?`)) return;
                    try { await api('perform_update', { version: latestVersion.value }); alert("Done! Reloading..."); window.location.reload(); } catch(e) { alert("Failed: "+e.message); }
                }
            };
        }
    })
    .component('icon', Icon)
    .mount('#app');
</script>
</body>
</html>