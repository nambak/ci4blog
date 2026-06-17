<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>글 목록<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="page-title">
        <?= isset($activeCategory) && $activeCategory !== null ? esc($activeCategory->name) : '글 목록' ?>
    </h1>

    <?= $this->include('partials/category_menu') ?>

    <?php if (empty($posts)): ?>
        <p>아직 작성된 글이 없습니다.</p>
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
