<?php // 글 상세에 끼워 넣는 댓글 목록 부분 뷰. $comments(Comment[])·$commentCount·$post 와 좋아요 집계($likeCounts·$likedIds)를 받는다. ?>
<?php
// 좋아요 하트(#100). 목업의 댓글 하트와 같은 13px svg 다.
// 누른 상태는 색만이 아니라 채움(fill)으로도 알린다 — 색 하나로만 알리면 색각 이상
// 사용자가 구분하지 못한다.
$heart = static fn (bool $filled): string => '<svg class="icon-heart-sm" viewBox="0 0 24 24" fill="'
    . ($filled ? 'currentColor' : 'none')
    . '" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    . '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';

// 댓글 한 건을 그린다. 최상위와 답글이 같은 마크업을 쓰므로 한 번만 정의한다.
// $isReply 면 들여쓰기 클래스를 붙인다.
$renderComment = static function ($comment, bool $isReply) use ($post, $likeCounts, $likedIds, $heart): void {
    ?>
    <li class="comment<?= $isReply ? ' comment-reply' : '' ?>" id="comment-<?= (int) $comment->id ?>">
        <?= view('partials/avatar', ['avatar' => $comment->authorAvatar, 'name' => $comment->authorName, 'size' => 'sm']) ?>

        <div class="comment-main">
            <div class="comment-meta">
                <?php if ($isReply): ?>
                    <span class="comment-reply-mark" aria-hidden="true">↳</span>
                <?php endif ?>
                <span class="comment-author"><?= esc($comment->authorName) ?></span>
                <?php if ($comment->user_id !== null && (int) $comment->user_id === (int) $post->user_id): ?>
                    <span class="badge badge-accent">작성자</span>
                <?php endif ?>
                <?php if ($comment->created_at !== null): ?>
                    <time datetime="<?= esc($comment->created_at->format('Y-m-d')) ?>">
                        · <?= esc($comment->created_at->format('Y.m.d')) ?>
                    </time>
                <?php endif ?>
            </div>

            <div class="comment-body"><?= nl2br(esc($comment->body)) ?></div>

            <?php
            // 삭제: 댓글 작성자 본인·글 작성자·관리자. 신고: 로그인했고 남의 최상위 visible 댓글일 때.
            $canDelete = is_owner_or_admin($comment->user_id) || is_owner_or_admin($post->user_id);
            $canReport = auth()->loggedIn()
                && (int) auth()->id() !== (int) $comment->user_id
                && ! $comment->isHidden()
                && ! $isReply;

            // 좋아요는 숨김 댓글에만 닫는다(컨트롤러 가드와 같은 규칙).
            $canLike    = ! $comment->isHidden();
            $likeCount  = $likeCounts[(int) $comment->id] ?? 0;
            $hasLiked   = isset($likedIds[(int) $comment->id]);
            ?>
            <?php // 좋아요는 항상 보여야 하므로 푸터를 조건 없이 그린다(목업 순서: 좋아요 → 삭제·신고). ?>
                <div class="comment-foot">
                    <?php if ($canLike && auth()->loggedIn()): ?>
                        <form action="<?= site_url('comments/' . $comment->id . '/like') ?>" method="post"
                              class="comment-like" data-comment="<?= (int) $comment->id ?>">
                            <?= csrf_field() ?>
                            <?php // 아이콘만 남아 글자가 없으므로 상태는 aria 로 알린다. ?>
                            <button type="submit"
                                    class="comment-like-btn<?= $hasLiked ? ' is-liked' : '' ?>"
                                    aria-pressed="<?= $hasLiked ? 'true' : 'false' ?>"
                                    aria-label="<?= $hasLiked ? '좋아요 취소' : '좋아요' ?>">
                                <?= $heart($hasLiked) ?><span class="comment-like-count"><?= (int) $likeCount ?></span>
                            </button>
                        </form>
                    <?php elseif ($canLike): ?>
                        <?php // 비로그인은 로그인으로 보낸다(게시글 좋아요와 같은 규칙). ?>
                        <a class="comment-like" data-comment="<?= (int) $comment->id ?>"
                           href="<?= site_url('login') ?>" aria-label="로그인하고 좋아요">
                            <span class="comment-like-btn">
                                <?= $heart(false) ?><span class="comment-like-count"><?= (int) $likeCount ?></span>
                            </span>
                        </a>
                    <?php endif ?>

                    <?php if ($canDelete): ?>
                        <form action="<?= site_url('comments/' . $comment->id . '/delete') ?>" method="post"
                              onsubmit="return confirm('댓글을 삭제하시겠습니까?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="comment-delete">삭제</button>
                        </form>
                    <?php endif ?>

                    <?php if ($canReport): ?>
                        <?php // <details> 라 JS 없이 펼쳐진다. 사유는 CommentReportModel 상수와 공유. ?>
                        <details class="comment-report">
                            <summary>신고</summary>
                            <form action="<?= site_url('comments/' . $comment->id . '/report') ?>" method="post">
                                <?= csrf_field() ?>
                                <select name="reason" aria-label="신고 사유">
                                    <?php foreach (\App\Models\CommentReportModel::REASONS as $value => $label): ?>
                                        <option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option>
                                    <?php endforeach ?>
                                </select>
                                <button type="submit" class="comment-report-btn">신고</button>
                            </form>
                        </details>
                    <?php endif ?>
                </div>
        </div>
    </li>
    <?php
};
?>
<?php // id 는 글 상세의 engagement bar 에서 "댓글 N" 을 눌렀을 때의 앵커 대상이다. ?>
<section class="comments" id="comments">
    <h2 class="comments-title">댓글 <span class="comments-count"><?= esc((string) $commentCount) ?></span></h2>

    <?php if ($comments === []): ?>
        <p class="comments-empty">아직 댓글이 없습니다.</p>
    <?php else: ?>
        <ul class="comment-list">
            <?php foreach ($comments as $comment): ?>
                <?php $renderComment($comment, false) ?>

                <?php // 답글은 부모 바로 아래에 들여쓰기로. 관리자만 달 수 있고 여기서는 읽기 전용이다. ?>
                <?php foreach ($comment->replies as $reply): ?>
                    <?php $renderComment($reply, true) ?>
                <?php endforeach ?>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
