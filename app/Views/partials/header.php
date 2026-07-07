<?php
/**
 * 공통 헤더. 홈 디자인(docs/design/source/Home)을 사이트 전체에 적용한 것.
 * 풀폭(1100px) 바 + 검색 + 로그인 상태 분기.
 */
?>
<header class="home-header">
    <div class="home-bar">
        <a class="home-brand" href="<?= site_url('/') ?>"><span class="dot">·</span> <?= esc(config('Blog')->title) ?></a>
        <nav class="home-nav">
            <a class="nav-link" href="<?= site_url('/') ?>">홈</a>
            <a class="nav-link" href="<?= site_url('posts') ?>">아카이브</a>
            <a class="nav-link" href="<?= site_url('about') ?>">About</a>
            <?php if (auth()->loggedIn() && auth()->user()->inGroup('admin', 'superadmin')): ?>
                <a class="nav-link" href="<?= site_url('admin') ?>">관리자</a>
            <?php endif ?>
        </nav>
        <span class="home-bar-spacer"></span>
        <form class="home-search" method="get" action="<?= site_url('posts') ?>" role="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
            </svg>
            <input name="q" placeholder="검색" aria-label="검색어">
        </form>
        <?php if (auth()->loggedIn()): ?>
            <a class="btn" href="<?= site_url('posts/new') ?>">글쓰기</a>
            <span class="nav-user"><?= esc(auth()->user()->username) ?>님</span>
            <a class="nav-link" href="<?= site_url('logout') ?>">로그아웃</a>
        <?php else: ?>
            <a class="nav-link" href="<?= site_url('login') ?>">로그인</a>
        <?php endif ?>
    </div>
</header>
