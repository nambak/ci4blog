<?php
/**
 * 공통 푸터. 홈 디자인을 사이트 전체에 적용한 것.
 */
?>
<footer class="home-footer">
    <div class="home-footer-inner">
        <div>&copy; <?= esc(date('Y')) ?> <?= esc(config('Blog')->title) ?></div>
        <nav class="home-footer-nav">
            <a class="nav-link" href="<?= site_url('posts') ?>">아카이브</a>
            <a class="nav-link" href="<?= site_url('about') ?>">About</a>
            <?php // 개인정보처리방침은 모든 화면에서 닿을 수 있어야 한다(#182). ?>
            <a class="nav-link" href="<?= site_url('privacy') ?>">개인정보처리방침</a>
            <a class="nav-link" href="<?= site_url('feed') ?>">RSS</a>
            <a class="nav-link" href="https://github.com/nambak/ci4blog" rel="noopener" target="_blank">GitHub</a>
        </nav>
    </div>
</footer>
