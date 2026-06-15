<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>새 글 작성<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1>새 글 작성</h1>

    <?php // 검증 실패 시 컨트롤러가 flashdata 로 넘긴 에러를 보여 준다. ?>
    <?php $errors = session('errors') ?? []; ?>
    <?php if ($errors !== []): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <form action="<?= site_url('posts') ?>" method="post">
        <?= csrf_field() ?>

        <div>
            <label for="title">제목</label>
            <?php // old() 로 직전 입력값을 되살린다(withInput 과 짝). ?>
            <input type="text" name="title" id="title" value="<?= esc(old('title')) ?>">
        </div>

        <div>
            <label for="body">본문</label>
            <textarea name="body" id="body" rows="12"><?= esc(old('body')) ?></textarea>
        </div>

        <button type="submit">저장</button>
        <a href="<?= site_url('posts') ?>">취소</a>
    </form>
<?= $this->endSection() ?>
