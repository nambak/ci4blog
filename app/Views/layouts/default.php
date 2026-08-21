<!DOCTYPE html>
<html lang="ko">
<head>
    <?php // Google Tag Manager(GTM-NVTRSRQD, #151). head 최대한 위, 다른 스크립트보다 앞에 둔다. ?>
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-NVTRSRQD');</script>
    <?php // End Google Tag Manager ?>
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
    <link rel="canonical" href="<?= canonical_url() ?>">
    <?php // RSS 자동 발견 — 리더·브라우저 확장이 이 링크로 피드를 찾는다. href 는 피드의 atom:link rel="self" 와 같은 함수로 만들어 정본이 갈라지지 않게 한다. ?>
    <link rel="alternate" type="application/rss+xml" title="<?= esc(config('Blog')->title) ?>" href="<?= absolute_url('feed') ?>">
    <?php // 검색·SNS 미리보기용 메타태그. $meta 를 안 넘긴 페이지는 사이트 기본값으로 채워진다. ?>
    <?= $this->include('partials/meta', ['meta' => $meta ?? []]) ?>
    <?php // 구조화 데이터(#GSC). 부분 뷰가 $meta['jsonld'] 를 직접 읽는다 —
          // include() 의 두 번째 인자는 데이터가 아니라 캐시 옵션이라 전달되지 않는다. ?>
    <?= $this->include('partials/jsonld') ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&display=swap">
    <?php // 파일 수정 시각을 버전 파라미터로 붙여, CSS 수정이 브라우저 캐시에 막히지 않게 한다. ?>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= filemtime(FCPATH . 'assets/css/app.css') ?>">
</head>
<body>
    <?php // Google Tag Manager (noscript, #151) ?>
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NVTRSRQD"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <?php // End Google Tag Manager (noscript) ?>
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
