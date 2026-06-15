<header class="site-header">
    <nav class="nav container">
        <a class="brand" href="<?= site_url('/') ?>"><span class="dot">·</span> 지환의 노트</a>
        <a class="nav-link" href="<?= site_url('posts') ?>">글</a>
        <a class="nav-link" href="<?= site_url('about') ?>">소개</a>

        <span class="nav-spacer"></span>

        <?php if (auth()->loggedIn()): ?>
            <a class="btn" href="<?= site_url('posts/new') ?>">글쓰기</a>
            <span class="nav-user"><?= esc(auth()->user()->username) ?>님</span>
            <a class="nav-link" href="<?= site_url('logout') ?>">로그아웃</a>
        <?php else: ?>
            <a class="nav-link" href="<?= site_url('login') ?>">로그인</a>
            <a class="btn btn-ghost" href="<?= site_url('register') ?>">회원가입</a>
        <?php endif ?>
    </nav>
</header>
