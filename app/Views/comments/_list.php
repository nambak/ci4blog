<?php // 글 상세에 끼워 넣는 댓글 목록 부분 뷰. $comments(Comment[])와 $post 를 받는다. ?>
<section class="comments">
    <h2 class="comments-title">댓글 <span class="comments-count"><?= count($comments) ?></span></h2>

    <?php if ($comments === []): ?>
        <p class="comments-empty">아직 댓글이 없습니다.</p>
    <?php else: ?>
        <ul class="comment-list">
            <?php foreach ($comments as $comment): ?>
                <li class="comment">
                    <?php // 아바타 색은 작성자명 해시로 고정 선택해 같은 사람은 항상 같은 색을 갖는다. ?>
                    <span class="comment-avatar" style="background:hsl(<?= abs(crc32((string) $comment->authorName)) % 360 ?>,38%,82%)">
                        <?= esc(mb_strtoupper(mb_substr((string) $comment->authorName, 0, 1))) ?>
                    </span>

                    <div class="comment-main">
                        <div class="comment-meta">
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

                        <?php // 댓글 작성자 본인·글 작성자·관리자에게만 삭제 버튼을 노출한다(acl 헬퍼). ?>
                        <?php if (is_owner_or_admin($comment->user_id) || is_owner_or_admin($post->user_id)): ?>
                            <div class="comment-foot">
                                <form action="<?= site_url('comments/' . $comment->id . '/delete') ?>" method="post"
                                      onsubmit="return confirm('댓글을 삭제하시겠습니까?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="comment-delete">삭제</button>
                                </form>
                            </div>
                        <?php endif ?>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>
