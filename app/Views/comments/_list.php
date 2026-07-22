<?php // 글 상세에 끼워 넣는 댓글 목록 부분 뷰. $comments(Comment[])·$commentCount·$post 를 받는다. ?>
<?php
// 댓글 한 건을 그린다. 최상위와 답글이 같은 마크업을 쓰므로 한 번만 정의한다.
// $isReply 면 들여쓰기 클래스를 붙인다.
$renderComment = static function ($comment, bool $isReply) use ($post): void {
    ?>
    <li class="comment<?= $isReply ? ' comment-reply' : '' ?>">
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
            ?>
            <?php if ($canDelete || $canReport): ?>
                <div class="comment-foot">
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
            <?php endif ?>
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
