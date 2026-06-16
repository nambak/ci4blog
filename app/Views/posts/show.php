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

    <?php // 작성자 본인 또는 관리자에게만 수정/삭제를 노출한다. ?>
    <?php $canModify = auth()->loggedIn()
        && ((int) $post->user_id === (int) auth()->id() || auth()->user()->inGroup('admin')); ?>
    <?php if ($canModify): ?>
        <p class="post-actions">
            <a class="btn btn-ghost" href="<?= site_url('posts/' . $post->id . '/edit') ?>">수정</a>
            <?php // 삭제는 되돌릴 수 없으므로 제출 직전에 한 번 더 확인한다. ?>
            <form action="<?= site_url('posts/' . $post->id . '/delete') ?>" method="post"
                  onsubmit="return confirm('삭제하면 되돌릴 수 없습니다. 정말 삭제하시겠습니까?');" style="display:inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">삭제</button>
            </form>
        </p>
    <?php endif ?>

    <p><a class="nav-link" href="<?= site_url('posts') ?>">← 목록으로</a></p>
<?= $this->endSection() ?>
