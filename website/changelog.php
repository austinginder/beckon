<?php
/**
 * Beckon Changelog Generator
 * * Usage:
 * - Web: Visit in browser to see the changelog (cached for 24h).
 * - CLI: Run "php changelog.php" to generate a static "changelog.html" file.
 */

// Configuration
$sourceUrl = 'https://raw.githubusercontent.com/austinginder/beckon/refs/heads/main/changelog.md';
$cacheFile = __DIR__ . '/cache/changelog.json';
$outputFile = __DIR__ . '/changelog.html';
$cacheDuration = 86400; // 24 hours

// Ensure cache directory exists
if (!is_dir(dirname($cacheFile))) {
    mkdir(dirname($cacheFile), 0755, true);
}

// Function to fetch and parse markdown
function fetchAndParseChangelog($url) {
    // Suppress errors and use a context for safer fetching
    $context = stream_context_create(['http' => ['user_agent' => 'BeckonChangelogFetcher/1.0']]);
    $raw = @file_get_contents($url, false, $context);
    
    if ($raw === false) return null;

    // Remove Top Level Heading
    $raw = preg_replace('/^# Changelog\s+/', '', $raw);

    // Split by Versions (## [1.0.0] - 2025-12-15)
    $versions = [];
    preg_match_all('/## \[(.*?)\] - (\d{4}-\d{2}-\d{2})(.*?)(?=## \[|$)/s', $raw, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $versionNum = $match[1];
        $date = $match[2];
        $body = $match[3];
        $sections = [];
        
        // Split by Sections (### Title)
        preg_match_all('/### (.*?)\n(.*?)(?=### |$)/s', $body, $sectionMatches, PREG_SET_ORDER);

        foreach ($sectionMatches as $section) {
            $title = trim($section[1]);
            $content = trim($section[2]);
            
            // Parse list items
            preg_match_all('/\*\s+(.*?)(?=\n\*|\n$|$)/s', $content, $listMatches);
            $items = array_map(function($item) {
                // Formatting: Bold
                $item = preg_replace('/\*\*(.*?)\*\*/', '<strong class="text-white font-semibold">$1</strong>', $item);
                // Formatting: Inline Code
                $item = preg_replace('/`(.*?)`/', '<code class="bg-slate-700 px-1 py-0.5 rounded text-yellow-400 text-xs font-mono">$1</code>', $item);
                return trim($item);
            }, $listMatches[1]);

            $sections[] = [
                'title' => $title,
                'items' => $items
            ];
        }

        $versions[] = [
            'version' => $versionNum,
            'date' => $date,
            'sections' => $sections
        ];
    }

    return $versions;
}

// Logic: Check Cache vs Source
$data = null;
$needsUpdate = true;

// 1. Try to load from cache
if (file_exists($cacheFile)) {
    $cacheContent = json_decode(file_get_contents($cacheFile), true);
    if ($cacheContent && (time() - $cacheContent['timestamp'] < $cacheDuration)) {
        $data = $cacheContent['data'];
        $needsUpdate = false;
    }
}

// 2. If CLI mode, force check if cache is missing, but otherwise respect cache to be efficient.
//    (If you want CLI to ALWAYS fetch fresh, set $needsUpdate = true here)
if (php_sapi_name() === 'cli' && !file_exists($cacheFile)) {
    $needsUpdate = true;
}

// 3. Fetch if needed
if ($needsUpdate) {
    $parsed = fetchAndParseChangelog($sourceUrl);
    if ($parsed) {
        $data = $parsed;
        file_put_contents($cacheFile, json_encode([
            'timestamp' => time(),
            'data' => $data
        ]));
        if (php_sapi_name() === 'cli') echo "Authenticated fetch from GitHub successful.\n";
    } elseif ($data === null && file_exists($cacheFile)) {
        // Fallback to old cache if fetch fails
        $cacheContent = json_decode(file_get_contents($cacheFile), true);
        $data = $cacheContent['data'];
        if (php_sapi_name() === 'cli') echo "Fetch failed. Using cached data.\n";
    }
} else {
    if (php_sapi_name() === 'cli') echo "Using cached data (expires in " . round(($cacheDuration - (time() - $cacheContent['timestamp']))/3600, 1) . "h).\n";
}

// Start HTML Buffering
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changelog - Beckon</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="https://beckon.run/content/16/beckon-icon.webp">
    <script src="/content/16/mu-plugins/captaincore-analytics.js" data-site="MKGSCHCT" defer></script>
</head>
<body class="bg-slate-900 text-slate-300 font-sans antialiased selection:bg-yellow-400 selection:text-slate-900 flex flex-col min-h-screen">

    <nav class="fixed w-full z-50 bg-slate-900/90 backdrop-blur border-b border-slate-800">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-3 group">
                    <img src="https://beckon.run/content/16/uploads/2025/12/beckon-icon.webp" alt="Beckon Logo" class="h-8 w-8 rounded-md shadow-lg shadow-yellow-500/20">
                    <span class="font-bold text-white tracking-tight text-lg group-hover:text-yellow-400 transition">Beckon</span>
                </a>
                
                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-4">
                        <a href="/#features" class="hover:text-white px-3 py-2 rounded-md text-sm font-medium transition">Features</a>
                        <a href="/#install" class="hover:text-white px-3 py-2 rounded-md text-sm font-medium transition">Install</a>
                        <a href="https://beckon.run/changelog/" class="hover:text-white px-3 py-2 rounded-md text-sm font-medium transition">Changelog</a>
                        <a href="https://github.com/austinginder/beckon" class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-md text-sm font-bold transition border border-slate-700">GitHub</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="pt-32 pb-12 sm:pt-40 text-center px-4 relative overflow-hidden">
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-full max-w-4xl opacity-20 pointer-events-none">
             <div class="absolute top-20 left-1/2 -translate-x-1/2 w-96 h-96 bg-blue-500 rounded-full mix-blend-screen filter blur-[100px] opacity-30"></div>
        </div>
        <h1 class="text-4xl sm:text-5xl font-extrabold text-white tracking-tight mb-4 relative z-10">Ship Log</h1>
        <p class="text-lg text-slate-400 max-w-2xl mx-auto relative z-10">Tracking the evolution of Beckon, one commit at a time.</p>
    </div>

    <div class="flex-grow max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pb-24 w-full relative z-10">
        <?php if (!$data): ?>
            <div class="text-center py-20 text-slate-500 bg-slate-800/50 rounded-lg border border-slate-700 border-dashed">
                <p>Unable to load changelog data.</p>
            </div>
        <?php else: ?>
            <div class="space-y-12 relative before:absolute before:inset-0 before:ml-5 md:before:ml-[8.5rem] before:-translate-x-px md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-slate-800 before:to-transparent">
                <?php foreach ($data as $release): ?>
                    <div class="relative flex flex-col md:flex-row gap-8 md:gap-12 group">
                        <div class="md:w-32 flex-shrink-0 flex md:flex-col items-center md:items-end md:text-right pt-1.5 pl-12 md:pl-0">
                            <div class="absolute left-0 md:left-32 md:-ml-1.5 mt-1.5 w-3 h-3 rounded-full border-2 border-slate-600 bg-slate-900 group-hover:border-blue-500 group-hover:bg-blue-500 transition shadow-[0_0_0_4px_#0f172a]"></div>
                            <span class="font-mono text-sm text-blue-400 font-bold bg-blue-900/30 px-2 py-0.5 rounded border border-blue-500/20 mb-1">
                                v<?php echo htmlspecialchars($release['version']); ?>
                            </span>
                            <span class="text-xs text-slate-500 ml-3 md:ml-0">
                                <?php echo date('M j, Y', strtotime($release['date'])); ?>
                            </span>
                        </div>
                        <div class="flex-grow bg-slate-800/40 rounded-xl border border-slate-700/50 p-6 md:p-8 hover:border-slate-600 transition shadow-lg shadow-black/20">
                            <?php foreach ($release['sections'] as $index => $section): ?>
                                <div class="<?php echo $index > 0 ? 'mt-8' : ''; ?>">
                                    <h3 class="text-lg font-bold text-white mb-4 flex items-center gap-2">
                                        <?php echo htmlspecialchars($section['title']); ?>
                                    </h3>
                                    <ul class="space-y-3">
                                        <?php foreach ($section['items'] as $item): ?>
                                            <li class="flex items-start gap-3 text-slate-400 text-sm leading-relaxed group/item">
                                                <svg class="w-1.5 h-1.5 mt-2 rounded-full bg-slate-600 group-hover/item:bg-yellow-400 transition flex-shrink-0" viewBox="0 0 6 6"></svg>
                                                <span><?php echo $item; ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <footer class="bg-slate-950 border-t border-slate-900 pt-16 pb-8 mt-auto">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col items-center">
            <img src="https://beckon.run/content/16/uploads/2025/12/beckon-icon.webp" alt="Beckon" class="h-10 w-10 rounded-lg mb-6 shadow-lg shadow-yellow-500/20">
            <p class="text-slate-500 text-sm mb-8 text-center max-w-md">Beckon is a simple, self-hosted project management tool maintained by <a href="https://austinginder.com" class="hover:text-blue-400 transition">Austin Ginder</a>.</p>
            <div class="flex space-x-6 mb-8">
                <a href="https://github.com/austinginder/beckon" class="text-slate-400 hover:text-white transition">GitHub</a>
                <a href="https://twitter.com/austinginder" class="text-slate-400 hover:text-white transition">Twitter</a>
            </div>
            <div class="text-slate-600 text-xs text-center">
                &copy; 2025 <a href="https://austinginder.com" class="hover:text-blue-400 transition">Austin Ginder</a>. Licensed under MIT.
            </div>
        </div>
    </footer>
</body>
</html>
<?php
// Capture the buffer
$html = ob_get_clean();

// Mode Detection
if (php_sapi_name() === 'cli') {
    // CLI Mode: Save to file
    if (file_put_contents($outputFile, $html)) {
        echo "✔ Successfully generated: " . basename($outputFile) . "\n";
    } else {
        echo "✘ Error writing file.\n";
    }
} else {
    // Web Mode: Serve content
    echo $html;
}