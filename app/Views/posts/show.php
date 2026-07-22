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

    <?php // 좋아요(#64). 목업 Post 의 engagement bar — 위아래 경계선 안에 하트 아이콘과 숫자만 둔다.
          // 컨트롤러가 posts/{slug}#like 로 되돌리므로 앵커도 여기에 둔다.
          // 하트는 목업의 것을 그대로 쓰고, 누른 상태는 색(is-liked)과 채움(fill) 두 가지로 알린다. ?>
    <?php $heart = '<svg class="icon-heart" viewBox="0 0 24 24" fill="' . ($liked ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'; ?>
    <div class="engagement-bar" id="like">
        <?php if (auth()->loggedIn()): ?>
            <form action="<?= site_url('posts/' . $post->id . '/like') ?>" method="post">
                <?= csrf_field() ?>
                <?php // 화면에 글자가 없으므로 상태는 aria-label·aria-pressed 가 알린다. ?>
                <button type="submit" class="engagement-btn<?= $liked ? ' is-liked' : '' ?>"
                    aria-pressed="<?= $liked ? 'true' : 'false' ?>"
                    aria-label="<?= $liked ? '좋아요 취소' : '좋아요' ?>">
                    <?= $heart ?><span class="like-count"><?= (int) $likeCount ?></span>
                </button>
            </form>
        <?php else: ?>
            <?php // 비로그인도 카운트는 보이되, 누르면 로그인으로 보낸다(댓글 영역과 같은 유도). ?>
            <a class="engagement-btn" href="<?= site_url('login') ?>" aria-label="로그인하고 좋아요">
                <?= $heart ?><span class="like-count"><?= (int) $likeCount ?></span>
            </a>
        <?php endif ?>

        <?php // 목업은 좋아요 옆에 댓글 수를 같이 둔다. 누르면 아래 댓글 영역으로 내려간다. ?>
        <a class="engagement-btn" href="#comments" aria-label="댓글 <?= (int) $commentCount ?>개">
            <svg class="icon-bubble" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg><span class="comment-count"><?= (int) $commentCount ?></span>
        </a>
    </div>

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
