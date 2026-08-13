<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>글 목록<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="page-title">
        <?php // 같은 뷰가 /posts · /categories/{slug} · /tags/{slug} 를 모두 그린다(#114). ?>
        <?php if (isset($activeTag) && $activeTag !== null): ?>
            <?= esc($activeTag->name) ?> 태그
        <?php elseif (isset($activeCategory) && $activeCategory !== null): ?>
            <?= esc($activeCategory->name) ?>
        <?php else: ?>
            글 목록
        <?php endif ?>
    </h1>

    <?= $this->include('partials/category_menu') ?>

    <?php // 카테고리 페이지에서 검색해도 카테고리가 풀리지 않도록 현재 카테고리로 보낸다. ?>
    <form class="search-form" method="get" action="<?= esc(isset($activeCategory) && $activeCategory !== null ? $activeCategory->url : site_url('posts')) ?>" role="search">
        <input type="search" name="q" value="<?= esc($search ?? '', 'attr') ?>"
               placeholder="제목·본문 검색" aria-label="검색어">
        <button class="btn" type="submit">검색</button>
    </form>

    <?php if (empty($posts)): ?>
        <?php if (! empty($search)): ?>
            <p class="empty">'<?= esc($search) ?>'에 대한 검색 결과가 없습니다.</p>
        <?php else: ?>
            <p class="empty">아직 작성된 글이 없습니다.</p>
        <?php endif ?>
    <?php else: ?>
        <ul class="post-list">
            <?php foreach ($posts as $post): ?>
                <li<?= $post->image !== null && $post->image !== '' ? ' class="has-thumb"' : '' ?>>
                    <?php if ($post->image !== null && $post->image !== ''): ?>
                        <a class="post-thumb" href="<?= $post->url ?>"
                           aria-label="<?= esc($post->title) ?>">
                            <img src="<?= esc(site_url('uploads/thumb_' . $post->image), 'attr') ?>" alt="" loading="lazy">
                        </a>
                    <?php endif ?>
                    <div class="post-summary">
                    <?php // 검색어가 있으면 강조한다(#114). 없으면 esc 만 하므로 기존과 같다. ?>
                    <h2><a href="<?= $post->url ?>"><?= highlight_matches($post->title, $search) ?></a></h2>
                    <p><?= highlight_matches(search_snippet($post->body_text, $search), $search) ?></p>
                    <?php if ($post->created_at !== null): ?>
                        <time datetime="<?= esc($post->created_at->format('Y-m-d')) ?>">
                            <?= esc($post->created_at->format('Y-m-d')) ?>
                        </time>
                    <?php endif ?>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>

        <?= $pager->links('default', 'blog') ?>
    <?php endif ?>

    <?php // 전체 글 색인(#GSC). 컨트롤러가 실을 자리에서만 값을 채운다. ?>
    <?= $this->include('posts/_archive_index', ['archive' => $archive]) ?>
<?= $this->endSection() ?>
