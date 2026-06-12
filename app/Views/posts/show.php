<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?><?= esc($post->title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <article class="post">
        <h1><?= esc($post->title) ?></h1>

        <?php if ($post->created_at !== null): ?>
            <time datetime="<?= esc($post->created_at->format('Y-m-d')) ?>">
                <?= esc($post->created_at->format('Y-m-d')) ?>
            </time>
        <?php endif ?>

        <?php // 본문은 지금은 평문이다. 마크다운 렌더링은 ep26에서 다룬다. ?>
        <div class="post-body">
            <?= nl2br(esc($post->body)) ?>
        </div>
    </article>

    <p><a href="<?= site_url('posts') ?>">← 목록으로</a></p>
<?= $this->endSection() ?>
