<?php
/**
 * 블로그 홈. docs/design/source/Home (Tailwind v4).html 목업을 순수 CSS(app.css)로
 * 옮긴 화면. 공유 레이아웃(layouts/default)을 쓰지 않고 자체 헤더/푸터를 둔다.
 *
 * @var \App\Entities\Post|null  $featured    히어로(추천)로 보여 줄 최신 글
 * @var string|null              $authorName  히어로 작성자명
 * @var \App\Entities\Post[]     $posts       "최근 글" 그리드
 * @var \App\Entities\Category[] $categories  태그 레일
 */

// 카테고리를 id 로 색인해 글의 category_id 로 즉시 찾는다(N+1 회피).
$catById = [];
foreach ($categories as $c) {
    $catById[$c->id] = $c;
}
$siteTitle = config('Blog')->title;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&display=swap">
    <?php // 파일 수정 시각을 버전 파라미터로 붙여, CSS 수정이 브라우저 캐시에 막히지 않게 한다. ?>
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= filemtime(FCPATH . 'assets/css/app.css') ?>">
</head>
<body class="home">

    <!-- ================= Header (공유 partial) ================= -->
    <?= $this->include('partials/header') ?>

    <!-- ================= Tag rail ================= -->
    <?php if ($categories !== []): ?>
        <div class="tag-rail">
            <div class="tag-rail-inner">
                <a class="chip chip-ghost is-active" href="<?= site_url('posts') ?>">전체</a>
                <?php foreach ($categories as $cat): ?>
                    <a class="chip chip-ghost" href="<?= esc($cat->url) ?>"><?= esc($cat->name) ?></a>
                <?php endforeach ?>
            </div>
        </div>
    <?php endif ?>

    <?php if ($featured === null): ?>
        <!-- 글이 한 건도 없을 때 -->
        <section class="home-wrap home-empty">
            <p class="empty">아직 작성된 글이 없습니다.</p>
        </section>
    <?php else: ?>
        <!-- ================= Hero feature ================= -->
        <section class="home-wrap hero">
            <div class="hero-grid">
                <div class="hero-text">
                    <div class="hero-kicker">
                        <?php $fc = $featured->category_id !== null ? ($catById[$featured->category_id] ?? null) : null; ?>
                        <?php if ($fc !== null): ?>
                            <a class="chip" href="<?= esc($fc->url) ?>"><?= esc($fc->name) ?></a>
                        <?php endif ?>
                        <span class="hero-kicker-label">· 추천 글</span>
                    </div>
                    <h1 class="hero-title text-pretty">
                        <a href="<?= site_url('posts/' . $featured->slug) ?>"><?= esc($featured->title) ?></a>
                    </h1>
                    <p class="hero-excerpt text-pretty"><?= esc($featured->getExcerpt(120)) ?></p>
                    <div class="hero-meta">
                        <?php if ($authorName !== null): ?>
                            <span class="hero-avatar"><?= esc(mb_strtoupper(mb_substr($authorName, 0, 1))) ?></span>
                            <span class="hero-author"><?= esc($authorName) ?></span>
                            <span>·</span>
                        <?php endif ?>
                        <?php if ($featured->created_at !== null): ?>
                            <span><?= esc($featured->created_at->format('Y.m.d')) ?></span>
                            <span>·</span>
                        <?php endif ?>
                        <span><?= esc((string) $featured->read_time) ?>분 읽기</span>
                    </div>
                </div>
                <a class="cover hero-cover" href="<?= site_url('posts/' . $featured->slug) ?>"
                   <?php if ($featured->image !== null && $featured->image !== ''): ?>
                       style="background-image:url('<?= site_url('uploads/thumb_' . $featured->image) ?>');background-size:cover;background-position:center"
                   <?php else: ?>
                       style="background:<?= $featured->cover_gradient ?>"
                   <?php endif ?>>
                    <?php if ($featured->image === null || $featured->image === ''): ?><?= esc($featured->cover_initial) ?><?php endif ?>
                </a>
            </div>
        </section>

        <?php if ($posts !== []): ?>
            <hr class="home-rule">

            <!-- ================= Recent posts grid ================= -->
            <section class="home-wrap recent">
                <h2 class="recent-title">최근 글</h2>
                <div class="recent-grid">
                    <?php foreach ($posts as $post): ?>
                        <?php $pc = $post->category_id !== null ? ($catById[$post->category_id] ?? null) : null; ?>
                        <article class="post-card">
                            <div class="post-card-body">
                                <div class="post-card-meta">
                                    <?php if ($pc !== null): ?><span class="post-card-tag"><?= esc($pc->name) ?></span> · <?php endif ?>
                                    <?php if ($post->created_at !== null): ?><?= esc($post->created_at->format('Y.m.d')) ?><?php endif ?>
                                </div>
                                <h3 class="post-card-title">
                                    <a href="<?= site_url('posts/' . $post->slug) ?>"><?= esc($post->title) ?></a>
                                </h3>
                                <p class="post-card-excerpt line-clamp-2"><?= esc($post->getExcerpt(90)) ?></p>
                                <div class="read-time">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    <?= esc((string) $post->read_time) ?>분
                                </div>
                            </div>
                            <a class="cover post-card-cover" href="<?= site_url('posts/' . $post->slug) ?>"
                               <?php if ($post->image !== null && $post->image !== ''): ?>
                                   style="background-image:url('<?= site_url('uploads/thumb_' . $post->image) ?>');background-size:cover;background-position:center"
                               <?php else: ?>
                                   style="background:<?= $post->cover_gradient ?>"
                               <?php endif ?>>
                                <?php if ($post->image === null || $post->image === ''): ?><?= esc($post->cover_initial) ?><?php endif ?>
                            </a>
                        </article>
                    <?php endforeach ?>
                </div>
            </section>
        <?php endif ?>
    <?php endif ?>

    <!-- ================= Footer (공유 partial) ================= -->
    <?= $this->include('partials/footer') ?>
</body>
</html>