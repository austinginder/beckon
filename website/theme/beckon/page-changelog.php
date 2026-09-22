<?php
/**
 * Template for the /changelog/ page (matched by slug).
 */
get_header();
$releases = beckon_changelog_releases();
?>
<div class="head">
    <span class="eyebrow">Ship log</span>
    <h1>Every release, newest first.</h1>
    <p>Beckon updates itself from GitHub. This is what each release changed.</p>
</div>

<main>
    <div class="wrap">
    <?php if (!$releases): ?>
        <div class="empty">The changelog could not be loaded. It also lives in the <a href="https://github.com/austinginder/beckon/blob/main/changelog.md">repository</a>.</div>
    <?php else: ?>
        <div class="log">
        <?php foreach ($releases as $r): ?>
            <div class="rel" id="v<?php echo esc_attr($r['version']); ?>">
                <span class="dot"></span>
                <div class="v">
                    <b class="<?php echo $r['unreleased'] ? 'un' : ''; ?>"><?php echo esc_html($r['label']); ?></b>
                    <small><?php echo $r['unreleased'] ? 'Unreleased' : esc_html(date_i18n('M j, Y', strtotime($r['date']))); ?></small>
                </div>
                <div class="card">
                    <?php if ($r['intro']): ?><div class="cl-intro"><?php echo $r['intro']; ?></div><?php endif; ?>
                    <?php foreach ($r['sections'] as $s): ?>
                        <h3><?php echo esc_html($s['title']); ?></h3>
                        <div class="md"><?php echo $s['html']; ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
</main>
<?php get_footer(); ?>
