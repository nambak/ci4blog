<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>카테고리 관리<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="dash">
        <h1 class="page-title">카테고리</h1>

        <?php $errors = session('errors') ?? []; ?>
        <?php if ($errors !== []): ?>
            <ul class="form-errors">
                <?php foreach ($errors as $error): ?>
                    <li><?= esc($error) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <section class="card cat-add">
            <div class="card-head"><h2>새 카테고리</h2></div>
            <form class="cat-form" action="<?= site_url('admin/categories') ?>" method="post">
                <?= csrf_field() ?>
                <?= $this->include('admin/categories/_form') ?>
                <button type="submit" class="btn">추가</button>
            </form>
        </section>

        <form class="cat-search" method="get" action="<?= site_url('admin/categories') ?>" role="search">
            <input type="search" name="q" value="<?= esc($search ?? '', 'attr') ?>" placeholder="카테고리 검색" aria-label="카테고리 검색">
            <button type="submit" class="btn btn-ghost">검색</button>
        </form>

        <section class="card">
            <div class="card-head"><h2>전체 카테고리</h2></div>
            <ul class="cat-list">
                <?php foreach ($categories as $category): ?>
                    <li>
                        <span class="cat-name"><?= esc($category->name) ?></span>
                        <span class="cat-slug"><?= esc($category->slug) ?></span>
                        <span class="cat-count"><?= esc((string) $category->post_count) ?>개 글</span>
                        <span class="cat-actions">
                            <a class="btn btn-ghost" href="<?= site_url('admin/categories/' . $category->id . '/edit') ?>">수정</a>
                            <form action="<?= site_url('admin/categories/' . $category->id . '/delete') ?>" method="post" onsubmit="return confirm('삭제하면 이 카테고리의 글은 미분류로 옮겨집니다. 계속할까요?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost">삭제</button>
                            </form>
                        </span>
                    </li>
                <?php endforeach ?>

                <?php // 미분류: 가상 행(수정/삭제 없음). ?>
                <li class="cat-uncategorized">
                    <span class="cat-name">미분류</span>
                    <span class="cat-slug">—</span>
                    <span class="cat-count"><?= esc((string) $uncategorized) ?>개 글</span>
                    <span class="cat-actions"><small>기본값 · 삭제 불가</small></span>
                </li>
            </ul>
        </section>
    </div>
<?= $this->endSection() ?>
