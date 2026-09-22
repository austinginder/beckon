<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

    <nav>
        <div class="wrap">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="logo" aria-label="Beckon home">
                <svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#f2b134"/><path d="M13 24h6l-1.2-9h-3.6z" fill="#1a1f2b"/><path d="M12.5 13.5h7" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/><circle cx="16" cy="10.5" r="2.2" fill="#1a1f2b"/><path d="M9.5 9l2.4 1.2M22.5 9l-2.4 1.2M8.2 13.2l2.6-.4M23.8 13.2l-2.6-.4" stroke="#1a1f2b" stroke-width="1.6" stroke-linecap="round"/><path d="M10 25.5h12" stroke="#1a1f2b" stroke-width="2" stroke-linecap="round"/></svg>
                Beckon
            </a>
            <div class="links">
                <a href="#new">What's new</a>
                <a href="#features">Features</a>
                <a href="#install">Install</a>
                <a href="<?php echo esc_url(home_url('/changelog/')); ?>">Changelog</a>
                <button id="theme-toggle" title="Toggle light / dark. Right-click for system" aria-label="Toggle theme"></button>
                <a href="https://github.com/austinginder/beckon" class="gh">GitHub</a>
            </div>
        </div>
    </nav>
