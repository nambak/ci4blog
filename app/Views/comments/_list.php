<?php // 글 상세에 끼워 넣는 댓글 목록 부분 뷰. $comments(Comment[])를 받는다. ?>
<section class="comments">
    <h2 class="comments-title">댓글 <?= count($comments) ?></h2>

    <?php if ($comments === []): ?>
        <p class="comments-empty">아직 댓글이 없습니다.</p>
    <?php else: ?>
        <ul class="comment-list">
            <?php foreach ($comments as $comment): ?>
                <li class="comment">
                    <div class="comment-meta">
                        <span class="comment-author"><?= esc($comment->authorName) ?></span>
                        <?php if ($comment->created_at !== null): ?>
                            <time datetime="<?= esc($comment->created_at->format('Y-m-d')) ?>">
                                <?= esc($comment->created_at->format('Y-m-d')) ?>
                            </time>
                        <?php endif ?>
                    </div>
                    <div class="comment-body"><?= nl2br(esc($comment->body)) ?></div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
