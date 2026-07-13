<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php // Google AdSense ?>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3760455502657641"
         crossorigin="anonymous"></script>
    <title><?= $this->renderSection('title') ?> · <?= esc(config('Blog')->title) ?></title>
    <?php // 파비콘: SVG 우선, PNG 폴백, iOS 홈화면용 apple-touch-icon. ?>
    <link rel="icon" href="<?= base_url('favicon/favicon.svg') ?>" type="image/svg+xml">
    <link rel="icon" href="<?= base_url('favicon/favicon-32.png') ?>" sizes="32x32" type="image/png">
    <link rel="icon" href="<?= base_url('favicon/favicon-16.png') ?>" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="<?= base_url('favicon/apple-touch-icon.png') ?>">
    <?php // apex(unwanted.me)에서도 같은 글을 서빙하므로, 정본 URL은 항상 baseURL(blog.unwanted.me) 기준으로 고정해 중복 콘텐츠를 막는다. ?>
    <link rel="canonical" href="<?= base_url(uri_string()) ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&display=swap">
    <?php // 파일 수정 시각을 버전 파라미터로 붙여, CSS 수정이 브라우저 캐시에 막히지 않게 한다. ?>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= filemtime(FCPATH . 'assets/css/app.css') ?>">
</head>
<body>
    <?= $this->include('partials/header') ?>

    <main class="page-main">
        <?php // 직전 요청에서 남긴 1회성 플래시 메시지(성공 알림 등)를 보여 준다. ?>
        <?php if (session()->getFlashdata('message') !== null): ?>
            <div class="flash"><?= esc(session()->getFlashdata('message')) ?></div>
        <?php endif ?>

        <?= $this->renderSection('content') ?>
    </main>

    <?= $this->include('partials/footer') ?>

    <?php // 페이지별 스크립트. 이 섹션을 쓰지 않는 뷰에서는 아무것도 출력되지 않는다. ?>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
