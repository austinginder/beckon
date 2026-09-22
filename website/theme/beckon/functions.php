<?php
/**
 * Beckon marketing theme.
 *
 * The changelog on this site is the repository's changelog.md, fetched from GitHub main
 * and rendered by the small Markdown parser below. Both the /changelog/ page and the
 * "what's new" popup on the front page read from beckon_changelog_releases().
 */

define('BECKON_SITE_VERSION', '2.0.0');
define('BECKON_CHANGELOG_URL', 'https://raw.githubusercontent.com/austinginder/beckon/refs/heads/main/changelog.md');

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('html5', ['script', 'style']);
    remove_action('wp_head', 'wp_generator');
});

add_action('wp_enqueue_scripts', function () {
    $dir = get_template_directory();
    wp_enqueue_style('beckon', get_stylesheet_uri(), [], BECKON_SITE_VERSION . '.' . filemtime("$dir/style.css"));
    wp_enqueue_script('beckon', get_template_directory_uri() . '/assets/theme.js', [], BECKON_SITE_VERSION . '.' . filemtime("$dir/assets/theme.js"), true);
});

// Paint the saved theme before first render so a pinned light/dark never flashes.
add_action('wp_head', function () {
    echo '<meta name="color-scheme" content="light dark">' . "\n";
    echo "<script>(function(){try{var t=localStorage.getItem('beckon_site_theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>\n";
    echo '<link rel="icon" href="' . esc_url(get_template_directory_uri() . '/assets/img/beckon-icon.webp') . '">' . "\n";
    if (is_front_page()) {
        $img = get_template_directory_uri() . '/assets/img/shot-dark.webp';
        echo '<meta name="description" content="Beckon is a self-hosted Kanban board in a single PHP file. Boards are folders, cards are Markdown, and there is no database, no build step and no third-party JavaScript.">' . "\n";
        echo '<meta property="og:title" content="Beckon - a Kanban board in one PHP file"><meta property="og:description" content="Boards are folders, cards are Markdown. No database, no build step, no third-party JavaScript."><meta property="og:image" content="' . esc_url($img) . '"><meta property="og:url" content="' . esc_url(home_url('/')) . '"><meta name="twitter:card" content="summary_large_image">' . "\n";
    }
}, 1);

add_filter('document_title_parts', function ($parts) {
    if (is_front_page()) { $parts['title'] = 'Beckon'; $parts['tagline'] = 'Where Markdown charts the course'; }
    return $parts;
});
add_filter('document_title_separator', fn() => '-');

/* ---------- Markdown (a small GFM subset: enough for changelog.md and readme-style text) ---------- */

class Beckon_Markdown {
    public static function render(string $text): string {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        return self::blocks($lines);
    }

    public static function inline(string $s): string {
        $slots = [];
        $keep = function ($html) use (&$slots) { $slots[] = $html; return "\0" . (count($slots) - 1) . "\0"; };
        $s = preg_replace_callback('/(`+)(.+?)\1/s', fn($m) => $keep('<code>' . esc_html(trim($m[2])) . '</code>'), $s);
        $s = esc_html($s);
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', fn($m) => $keep('<img src="' . esc_url($m[2]) . '" alt="' . $m[1] . '">'), $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', fn($m) => $keep('<a href="' . esc_url($m[2]) . '">' . $m[1] . '</a>'), $s);
        $s = preg_replace_callback('~(^|[\s(])(https?://[^\s<]+[^\s<.,:;"\')\]!?])~', fn($m) => $m[1] . $keep('<a href="' . esc_url($m[2]) . '">' . $m[2] . '</a>'), $s);
        $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![A-Za-z0-9])__(?=\S)(.+?)(?<=\S)__(?![A-Za-z0-9])/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<!\*)\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\*)/', '<em>$1</em>', $s);
        $s = preg_replace('/(?<![A-Za-z0-9_])_(?=\S)([^_\n]+?)(?<=\S)_(?![A-Za-z0-9_])/', '<em>$1</em>', $s);
        $s = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $s);
        return preg_replace_callback("/\0(\d+)\0/", fn($m) => $slots[(int) $m[1]], $s);
    }

    private static function blocks(array $lines): string {
        $out = []; $i = 0; $n = count($lines);
        $isBlock = fn($l) => preg_match('/^\s{0,3}(#{1,6}\s|>|```|~~~|[-*+]\s|\d+[.)]\s|([-*_])(\s*\2){2,}\s*$)/', $l);
        while ($i < $n) {
            $l = $lines[$i];
            if (trim($l) === '') { $i++; continue; }
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})\s*(\S*)/', $l, $m)) {
                $buf = []; $i++;
                while ($i < $n && strpos(trim($lines[$i]), $m[1]) !== 0) $buf[] = $lines[$i++];
                $i++;
                $out[] = '<pre><code' . ($m[2] ? ' class="lang-' . esc_attr($m[2]) . '"' : '') . '>' . esc_html(implode("\n", $buf)) . "\n</code></pre>";
                continue;
            }
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $l, $m)) { $h = strlen($m[1]); $out[] = "<h$h>" . self::inline($m[2]) . "</h$h>"; $i++; continue; }
            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $l)) { $out[] = '<hr>'; $i++; continue; }
            if (preg_match('/^\s{0,3}>/', $l)) {
                $buf = [];
                while ($i < $n && preg_match('/^\s{0,3}>/', $lines[$i])) $buf[] = preg_replace('/^\s{0,3}>\s?/', '', $lines[$i++]);
                $out[] = '<blockquote>' . self::blocks($buf) . '</blockquote>';
                continue;
            }
            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $l, $m)) {
                $indent = strlen($m[1]); $ordered = (bool) preg_match('/\d/', $m[2]); $items = [];
                while ($i < $n) {
                    $cur = $lines[$i];
                    if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $cur, $mm) && strlen($mm[1]) === $indent && ((bool) preg_match('/\d/', $mm[2])) === $ordered) { $items[] = [$mm[3]]; $i++; continue; }
                    if ($items && trim($cur) !== '' && !preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $cur) && !$isBlock($cur)) { $items[count($items) - 1][] = trim($cur); $i++; continue; }
                    if ($items && preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $cur, $mm) && strlen($mm[1]) > $indent) { $items[count($items) - 1][] = $cur; $i++; continue; }
                    break;
                }
                $html = '';
                foreach ($items as $it) {
                    $first = array_shift($it);
                    $nested = array_filter($it, fn($x) => preg_match('/^\s+([-*+]|\d+[.)])\s+/', $x));
                    $text = implode(' ', array_filter($it, fn($x) => !preg_match('/^\s+([-*+]|\d+[.)])\s+/', $x)));
                    $body = self::inline(trim($first . ' ' . $text));
                    if ($nested) $body .= self::blocks(array_map(fn($x) => preg_replace('/^\s{1,4}/', '', $x), array_values($nested)));
                    $html .= "<li>$body</li>";
                }
                $tag = $ordered ? 'ol' : 'ul';
                $out[] = "<$tag>$html</$tag>";
                continue;
            }
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== '' && (!$buf || !$isBlock($lines[$i]))) $buf[] = trim($lines[$i++]);
            $out[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $buf)) . '</p>';
        }
        return implode("\n", $out);
    }
}

/* ---------- Changelog ---------- */

/** Raw changelog.md: a local file when BECKON_CHANGELOG_FILE points at one (dev), else GitHub main cached for six hours. */
function beckon_changelog_md(): string {
    if (defined('BECKON_CHANGELOG_FILE') && is_readable(BECKON_CHANGELOG_FILE)) return (string) file_get_contents(BECKON_CHANGELOG_FILE);
    $cached = get_transient('beckon_changelog_md');
    if (is_string($cached) && $cached !== '') return $cached;
    $res = wp_remote_get(BECKON_CHANGELOG_URL, ['timeout' => 10, 'user-agent' => 'beckon.run/' . BECKON_SITE_VERSION]);
    $body = (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) ? (string) wp_remote_retrieve_body($res) : '';
    if ($body !== '') { set_transient('beckon_changelog_md', $body, 6 * HOUR_IN_SECONDS); update_option('beckon_changelog_md_last_good', $body, false); return $body; }
    return (string) get_option('beckon_changelog_md_last_good', '');
}

/**
 * Releases, newest first: [version, label, date, unreleased, intro (html), sections => [[title, html]]].
 * Heading forms accepted: "## [2.0.0] - 2026-10-01", "## [2.0.0] - Unreleased", "## [Unreleased]".
 */
function beckon_changelog_releases(): array {
    $raw = preg_replace('/^# Changelog\s+/', '', beckon_changelog_md());
    if (!preg_match_all('/^## \[([^\]]+)\](?:\s*-\s*(\S+))?[^\n]*\n(.*?)(?=^## \[|\z)/ms', $raw, $m, PREG_SET_ORDER)) return [];
    $out = [];
    foreach ($m as $r) {
        $version = trim($r[1]); $date = trim($r[2] ?? '');
        $unreleased = strcasecmp($version, 'Unreleased') === 0 || strcasecmp($date, 'Unreleased') === 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
        $body = $r[3];
        $parts = preg_split('/^### /m', $body);
        $intro = trim(array_shift($parts));
        $sections = [];
        foreach ($parts as $p) {
            [$title, $rest] = array_pad(explode("\n", $p, 2), 2, '');
            $sections[] = ['title' => trim($title), 'html' => Beckon_Markdown::render($rest)];
        }
        $out[] = [
            'version' => strcasecmp($version, 'Unreleased') === 0 ? 'Unreleased' : $version,
            'label' => strcasecmp($version, 'Unreleased') === 0 ? 'Next' : 'v' . $version,
            'date' => $unreleased ? '' : $date,
            'unreleased' => $unreleased,
            'intro' => $intro !== '' ? Beckon_Markdown::render($intro) : '',
            'sections' => $sections,
        ];
    }
    return $out;
}

/** The newest versioned heading in the changelog (an unreleased 2.0.0 still reads 2.0.0), for the hero badge. */
function beckon_latest_version(): string {
    foreach (beckon_changelog_releases() as $r) if ($r['version'] !== 'Unreleased') return $r['version'];
    return BECKON_SITE_VERSION;
}

/** The changelog popup: rendered on the front page, opened by the hero pill. */
function beckon_changelog_popup(): void {
    $releases = beckon_changelog_releases();
    if (!$releases) return;
    ?>
    <div class="cl-layer" id="cl" hidden>
        <div class="cl-win" role="dialog" aria-modal="true" aria-labelledby="cl-title">
            <div class="cl-head">
                <b id="cl-title">What's new</b>
                <a href="<?php echo esc_url(home_url('/changelog/')); ?>">Full changelog</a>
                <button class="xbtn" data-cl-close aria-label="Close"><svg class="i" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
            <div class="cl-body">
                <nav class="cl-nav" aria-label="Versions">
                    <?php foreach ($releases as $i => $r): ?>
                        <button data-cl-v="<?php echo esc_attr($r['version']); ?>" class="<?php echo $i === 0 ? 'on' : ''; ?>"><b><?php echo esc_html($r['label']); ?></b><small><?php echo $r['unreleased'] ? 'Unreleased' : esc_html(date_i18n('M j, Y', strtotime($r['date']))); ?></small></button>
                    <?php endforeach; ?>
                </nav>
                <div class="cl-main">
                    <?php foreach ($releases as $i => $r): ?>
                        <section data-cl-rel="<?php echo esc_attr($r['version']); ?>" <?php echo $i === 0 ? '' : 'hidden'; ?>>
                            <div class="cl-rel-head"><span class="vtag <?php echo $r['unreleased'] ? 'un' : ''; ?>"><?php echo esc_html($r['label']); ?></span><small><?php echo $r['unreleased'] ? 'Unreleased' : esc_html(date_i18n('F j, Y', strtotime($r['date']))); ?></small></div>
                            <?php if ($r['intro']): ?><div class="cl-intro"><?php echo $r['intro']; ?></div><?php endif; ?>
                            <?php foreach ($r['sections'] as $s): ?><h3><?php echo esc_html($s['title']); ?></h3><div class="md"><?php echo $s['html']; ?></div><?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/* ---------- WP-CLI: wp beckon changelog-refresh ---------- */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('beckon changelog-refresh', function () {
        delete_transient('beckon_changelog_md');
        $n = count(beckon_changelog_releases());
        WP_CLI::success("Changelog refreshed: $n release" . ($n === 1 ? '' : 's') . " parsed" . (defined('BECKON_CHANGELOG_FILE') ? ' (from BECKON_CHANGELOG_FILE)' : ' (from GitHub)') . '.');
    });
}
