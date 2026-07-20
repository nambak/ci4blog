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
                        <?php
                        // 공개/숨김 토글(#67). 버튼 라벨은 현재 상태를 보여 주고, 누르면 반전한다.
                        // 숨기는 방향일 때만 확인창을 띄운다 — 발행된 글이 공개 화면에서
                        // 사라지는 건 되돌리기는 쉬워도 모르고 하면 놀라운 동작이다.
                        $hideConfirm = sprintf(
                            'return confirm(\'이 카테고리의 글 %d개가 공개 사이트에서 보이지 않게 됩니다. 계속할까요?\');',
                            (int) $category->post_count
                        );
                        ?>
                        <span class="cat-visibility">
                            <form action="<?= site_url('admin/categories/' . $category->id . '/visibility') ?>" method="post"
                                  <?= $category->is_visible ? 'onsubmit="' . esc($hideConfirm, 'attr') . '"' : '' ?>>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost"
                                        aria-label="<?= $category->is_visible ? '숨기기' : '공개하기' ?>">
                                    <?= $category->is_visible ? '공개' : '숨김' ?>
                                </button>
                            </form>
                        </span>
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
                    <?php // 미분류는 카테고리 레코드가 아니라 category_id = NULL 이라 숨길 대상이 없다. ?>
                    <span class="cat-visibility"><small>항상 공개</small></span>
                    <span class="cat-actions"><small>기본값 · 삭제 불가</small></span>
                </li>
            </ul>
        </section>
    </div>
<?= $this->endSection() ?>
