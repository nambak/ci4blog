<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->renderSection('title') ?> · <?= esc(config('Blog')->title) ?></title>
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
</body>
</html>
