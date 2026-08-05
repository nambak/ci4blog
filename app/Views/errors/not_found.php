<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>페이지를 찾을 수 없습니다<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="error-page">
    <p class="error-code">404</p>
    <h1 class="error-title">찾는 글이 없습니다</h1>
    <p class="error-desc">주소가 바뀌었거나 삭제된 글일 수 있습니다. 검색으로 찾아보세요.</p>

    <?php // 글 목록과 같은 검색폼. GET 이므로 CSRF 토큰이 필요 없다. ?>
    <form class="search-form" method="get" action="<?= site_url('posts') ?>" role="search">
        <input type="search" name="q" placeholder="제목·본문 검색" aria-label="검색어">
        <button class="btn" type="submit">검색</button>
    </form>

    <p class="error-links">
        <a class="nav-link" href="<?= site_url('/') ?>">홈으로</a>
        <a class="nav-link" href="<?= site_url('posts') ?>">글 목록</a>
    </p>

    <?php // 개발 중에는 예외 메시지를 보여 준다(운영에서는 노출하지 않는다). ?>
    <?php if (ENVIRONMENT !== 'production' && isset($message) && $message !== ''): ?>
        <pre class="error-debug"><?= nl2br(esc($message)) ?></pre>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
