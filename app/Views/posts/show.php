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

        <?php // 본문은 마크다운 원문으로 저장하고, 표시할 때 HTML 로 변환한다.
              // 변환은 엔티티(body_html)가 XSS 안전 설정으로 처리하므로 여기선 그대로 출력한다. ?>
        <div class="post-body prose">
            <?= $post->body_html ?>
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

    <?php // 댓글 목록(부분 뷰). ?>
    <?= $this->include('comments/_list', ['comments' => $comments, 'post' => $post]) ?>

    <?php // 댓글 작성 폼은 로그인 사용자에게만 노출한다(비로그인은 로그인 유도). ?>
    <?php if (auth()->loggedIn()): ?>
        <?= $this->include('comments/_form', ['post' => $post]) ?>
    <?php else: ?>
        <p class="comment-login"><a class="nav-link" href="<?= site_url('login') ?>">로그인</a> 후 댓글을 남길 수 있습니다.</p>
    <?php endif ?>

    <p><a class="nav-link" href="<?= site_url('posts') ?>">← 목록으로</a></p>
<?= $this->endSection() ?>
