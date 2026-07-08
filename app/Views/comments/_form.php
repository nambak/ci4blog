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

    <?php $me = (string) (auth()->user()->username ?? ''); ?>
    <div class="comment-composer">
        <?= view('partials/avatar', ['avatar' => auth()->user()->avatar, 'name' => $me, 'size' => 'sm']) ?>
        <div class="composer-main">
            <textarea name="body" rows="3" placeholder="이 글에 대한 생각을 남겨주세요…"><?= esc(old('body')) ?></textarea>
            <div class="composer-foot">
                <span class="composer-hint">줄바꿈은 Enter</span>
                <button type="submit" class="btn">남기기</button>
            </div>
        </div>
    </div>
</form>
