<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>카테고리 수정<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="dash">
        <h1 class="page-title">카테고리 수정</h1>

        <?php $errors = session('errors') ?? []; ?>
        <?php if ($errors !== []): ?>
            <ul class="form-errors">
                <?php foreach ($errors as $error): ?>
                    <li><?= esc($error) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <section class="card">
            <form class="cat-form" action="<?= site_url('admin/categories/' . $category->id) ?>" method="post">
                <?= csrf_field() ?>
                <?= $this->include('admin/categories/_form') ?>
                <div class="cat-actions">
                    <button type="submit" class="btn">저장</button>
                    <a class="btn btn-ghost" href="<?= site_url('admin/categories') ?>">취소</a>
                </div>
            </form>
        </section>
    </div>
<?= $this->endSection() ?>
