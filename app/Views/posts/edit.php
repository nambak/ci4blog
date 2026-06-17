<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>글 수정<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="page-title">글 수정</h1>

    <?php $errors = session('errors') ?? []; ?>
    <?php if ($errors !== []): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <form class="form" action="<?= site_url('posts/' . $post->id) ?>" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div>
            <label for="title">제목</label>
            <?php // old() 가 없으면(검증 통과 직후 첫 진입) 기존 값으로 바인딩한다. ?>
            <input type="text" name="title" id="title" value="<?= esc(old('title', $post->title)) ?>">
        </div>

        <div>
            <label for="image">대표 이미지 <small>(선택)</small></label>
            <?php if ($post->image !== null && $post->image !== ''): ?>
                <p><img class="image-current" src="<?= site_url('uploads/thumb_' . $post->image) ?>" alt="현재 대표 이미지"></p>
            <?php endif ?>
            <p class="field-hint">새 파일을 올리면 기존 이미지를 교체합니다. JPG·PNG·WebP, 2MB 이하.</p>
            <input type="file" name="image" id="image" accept="image/png,image/jpeg,image/webp">
        </div>

        <div>
            <label for="body">본문</label>
            <p class="field-hint">마크다운으로 작성할 수 있습니다 — <code># 제목</code>, <code>**굵게**</code>, <code>[링크](https://…)</code></p>
            <textarea name="body" id="body" rows="12"><?= esc(old('body', $post->body)) ?></textarea>
        </div>

        <button type="submit" class="btn">수정</button>
        <a class="btn btn-ghost" href="<?= site_url('posts/' . $post->slug) ?>">취소</a>
    </form>
<?= $this->endSection() ?>
