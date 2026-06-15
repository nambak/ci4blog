<header>
    <nav>
        <a href="<?= site_url('/') ?>">CI4 Blog</a>
        <a href="<?= site_url('about') ?>">소개</a>

        <?php if (auth()->loggedIn()): ?>
            <span>안녕하세요, <?= esc(auth()->user()->username) ?>님</span>
            <a href="<?= site_url('logout') ?>">로그아웃</a>
        <?php else: ?>
            <a href="<?= site_url('login') ?>">로그인</a>
            <a href="<?= site_url('register') ?>">회원가입</a>
        <?php endif ?>
    </nav>
</header>
