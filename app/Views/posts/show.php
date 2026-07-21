<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?><?= esc($post->title) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <article class="post">
        <?php // 본인·관리자에게만 보이는 미리보기 안내(공개 화면에는 이 글이 없다). ?>
        <?php if (! $post->isPublished()): ?>
            <div class="preview-banner">
                이 글은 아직 발행되지 않았습니다 · <strong><?= esc($post->statusLabel()) ?></strong>
            </div>
        <?php endif ?>

        <?php if ($category !== null): ?>
            <div class="post-chip-row"><a class="chip" href="<?= esc($category->url) ?>"><?= esc($category->name) ?></a></div>
        <?php endif ?>

        <h1><?= esc($post->title) ?></h1>

        <?php // 작성자 아바타 + 날짜·읽기 시간 바이라인(디자인 목업의 author row). ?>
        <div class="post-byline">
            <?php if ($authorName !== null): ?>
                <?= view('partials/avatar', ['avatar' => $authorAvatar, 'name' => $authorName, 'size' => 'md']) ?>
                <div>
                    <div class="byline-name"><?= esc($authorName) ?></div>
                    <div class="byline-meta">
                        <?php if ($post->created_at !== null): ?>
                            <time datetime="<?= esc($post->created_at->format('Y-m-d')) ?>"><?= esc($post->created_at->format('Y.m.d')) ?></time> ·
                        <?php endif ?>
                        <?= esc((string) $post->read_time) ?>분 읽기
                    </div>
                </div>
            <?php else: ?>
                <div class="byline-meta">
                    <?php if ($post->created_at !== null): ?>
                        <time datetime="<?= esc($post->created_at->format('Y-m-d')) ?>"><?= esc($post->created_at->format('Y.m.d')) ?></time> ·
                    <?php endif ?>
                    <?= esc((string) $post->read_time) ?>분 읽기
                </div>
            <?php endif ?>
        </div>

        <?php // 대표 이미지가 없으면 홈과 같은 그라데이션 커버를 깐다. ?>
        <?php if ($post->image !== null && $post->image !== ''): ?>
            <img class="post-cover" src="<?= esc(site_url('uploads/' . $post->image), 'attr') ?>" alt="<?= esc($post->title) ?>">
        <?php else: ?>
            <div class="cover post-cover" style="background:<?= $post->cover_gradient ?>"><?= esc($post->cover_initial) ?></div>
        <?php endif ?>

        <?php // 본문은 마크다운 원문으로 저장하고, 표시할 때 HTML 로 변환한다.
              // 변환은 엔티티(body_html)가 XSS 안전 설정으로 처리하므로 여기선 그대로 출력한다. ?>
        <div class="post-body prose">
            <?= $post->body_html ?>
        </div>
    </article>

    <?php if (is_owner_or_admin($post->user_id)): ?>
        <div class="post-actions">
            <a class="btn btn-ghost" href="<?= site_url('posts/' . $post->id . '/edit') ?>">수정</a>
            <form action="<?= site_url('posts/' . $post->id . '/delete') ?>"
                method="post"
                onsubmit="return confirm('삭제하면 되돌릴 수 없습니다. 정말 삭제하시겠습니까?');"
            >
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger">삭제</button>
            </form>
        </div>
    <?php endif ?>

    <?php // 좋아요(#64). 컨트롤러가 posts/{slug}#like 로 되돌리므로 앵커를 여기에 둔다. ?>
    <section class="post-like" id="like">
        <?php if (auth()->loggedIn()): ?>
            <form action="<?= site_url('posts/' . $post->id . '/like') ?>" method="post">
                <?= csrf_field() ?>
                <?php // 버튼 라벨과 aria-pressed 가 현재 상태를 함께 알린다(아이콘만으론 스크린리더가 못 읽는다). ?>
                <button type="submit" class="btn btn-like<?= $liked ? ' is-liked' : '' ?>" aria-pressed="<?= $liked ? 'true' : 'false' ?>">
                    <?= $liked ? '♥ 좋아요 취소' : '♡ 좋아요' ?>
                </button>
            </form>
        <?php else: ?>
            <p class="like-login"><a class="nav-link" href="<?= site_url('login') ?>">로그인</a> 후 좋아요를 누를 수 있습니다.</p>
        <?php endif ?>
        <span class="like-count"><?= (int) $likeCount ?>명이 좋아합니다</span>
    </section>

    <?php // 댓글 목록(부분 뷰). ?>
    <?= $this->include('comments/_list', ['comments' => $comments, 'commentCount' => $commentCount, 'post' => $post]) ?>

    <?php // 댓글 작성 폼은 로그인 사용자에게만 노출한다(비로그인은 로그인 유도). ?>
    <?php if (auth()->loggedIn()): ?>
        <?= $this->include('comments/_form', ['post' => $post]) ?>
    <?php else: ?>
        <p class="comment-login"><a class="nav-link" href="<?= site_url('login') ?>">로그인</a> 후 댓글을 남길 수 있습니다.</p>
    <?php endif ?>

    <p class="post-back"><a class="nav-link" href="<?= site_url('posts') ?>">← 목록으로</a></p>
<?= $this->endSection() ?>
