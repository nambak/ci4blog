<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>글 목록<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1>글 목록</h1>

    <?php if (empty($posts)): ?>
        <p>아직 작성된 글이 없습니다.</p>
    <?php else: ?>
        <ul class="post-list">
            <?php foreach ($posts as $post): ?>
                <li>
                    <h2><?= esc($post->title) ?></h2>
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
