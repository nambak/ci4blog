<?php // 댓글 작성 폼 부분 뷰. $post 를 받는다(action URL 용). 로그인 시에만 노출. ?>
<form class="comment-form" action="<?= site_url('posts/' . $post->id . '/comments') ?>" method="post">
    <?= csrf_field() ?>

    <?php $errors = session('errors') ?? []; ?>
    <?php if ($errors !== []): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <textarea name="body" rows="3" placeholder="댓글을 남겨 보세요"><?= esc(old('body')) ?></textarea>
    <button type="submit" class="btn">댓글 등록</button>
</form>
