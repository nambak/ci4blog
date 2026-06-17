<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>글 목록<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="page-title">
        <?= isset($activeCategory) && $activeCategory !== null ? esc($activeCategory->name) : '글 목록' ?>
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
                <li>
                    <h2><a href="<?= site_url('posts/' . $post->slug) ?>"><?= esc($post->title) ?></a></h2>
                    <p><?= esc($post->excerpt) ?></p>
                    <?php if ($post->created_at !== null): ?>
                        <time datetime="<?= esc($post->created_at->format('Y-m-d')) ?>">
                            <?= esc($post->created_at->format('Y-m-d')) ?>
                        </time>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>

        <?= $pager->links() ?>
    <?php endif ?>
<?= $this->endSection() ?>
