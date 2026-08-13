<?php
/**
 * Shield 인증 화면의 공통 레이아웃. (#GSC 색인)
 *
 * vendor/codeigniter4/shield/src/Views/layout.php 를 발행한 것이다.
 * Config\Auth::$views['layout'] 이 이 파일을 가리킨다.
 *
 * 발행한 이유는 하나다 — 로그인·회원가입·매직링크 화면에 noindex 를 넣기 위해서다.
 * Shield 뷰들은 모두 이 레이아웃을 extend 하므로, 여기 한 곳이면 인증 화면 전체가
 * 덮인다. 화면별로 넣으면 새 인증 화면이 늘 때마다 빠뜨린다.
 *
 * 나머지는 원본 그대로다. Bootstrap CDN 도 유지했다 — 이 화면의 디자인을 바꾸는
 * 것은 별개의 일이고, 지금 손대면 무엇이 색인에 영향을 줬는지 알 수 없게 된다.
 * 원본의 lang="en" 만 ko 로 고쳤다. 이제 우리 파일이므로 틀린 값을 남길 이유가 없다.
 */
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <?php // 인증 화면은 검색 대상이 아니다. 링크도 따라갈 필요가 없다. ?>
    <meta name="robots" content="noindex,nofollow">

    <title><?= $this->renderSection('title') ?></title>

    <!-- Bootstrap core CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <?= $this->renderSection('pageStyles') ?>
</head>

<body class="bg-light">

    <main role="main" class="container">
        <?= $this->renderSection('main') ?>
    </main>

<?= $this->renderSection('pageScripts') ?>
</body>
</html>
