<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>글 수정<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1>글 수정</h1>

    <?php $errors = session('errors') ?? []; ?>
    <?php if ($errors !== []): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <form action="<?= site_url('posts/' . $post->id) ?>" method="post">
        <?= csrf_field() ?>

        <div>
            <label for="title">제목</label>
            <?php // old() 가 없으면(검증 통과 직후 첫 진입) 기존 값으로 바인딩한다. ?>
            <input type="text" name="title" id="title" value="<?= esc(old('title', $post->title)) ?>">
        </div>

        <div>
            <label for="body">본문</label>
            <textarea name="body" id="body" rows="12"><?= esc(old('body', $post->body)) ?></textarea>
        </div>

        <button type="submit">수정</button>
        <a href="<?= site_url('posts/' . $post->slug) ?>">취소</a>
    </form>
<?= $this->endSection() ?>
