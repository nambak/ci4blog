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
                        // 공개/숨김(#67). 목업처럼 상태는 이 열에 글자로 두고, 바꾸는 동작은
                        // 작업 열의 눈 아이콘이 맡는다 — 버튼 하나에 상태와 동작을 겹쳐 두면
                        // "공개" 라는 라벨이 "지금 공개" 인지 "공개하기" 인지 헷갈린다.
                        $hideConfirm = sprintf(
                            'return confirm(\'이 카테고리의 글 %d개가 공개 사이트에서 보이지 않게 됩니다. 계속할까요?\');',
                            (int) $category->post_count
                        );
                        ?>
                        <span class="cat-visibility<?= $category->is_visible ? '' : ' is-hidden' ?>">
                            <?= $category->is_visible ? '공개' : '숨김' ?>
                        </span>
                        <span class="cat-actions">
                            <?php // 눈 아이콘: 공개/숨김 토글. 숨기는 방향일 때만 확인창을 띄운다. ?>
                            <form action="<?= site_url('admin/categories/' . $category->id . '/visibility') ?>" method="post"
                                  <?= $category->is_visible ? 'onsubmit="' . esc($hideConfirm, 'attr') . '"' : '' ?>>
                                <?= csrf_field() ?>
                                <button type="submit" class="icon-btn"
                                        title="<?= $category->is_visible ? '숨기기' : '공개하기' ?>"
                                        aria-label="<?= esc($category->name, 'attr') ?> <?= $category->is_visible ? '숨기기' : '공개하기' ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="2.5" />
                                    </svg>
                                </button>
                            </form>

                            <?php // 연필 아이콘: 수정 ?>
                            <a class="icon-btn" href="<?= site_url('admin/categories/' . $category->id . '/edit') ?>"
                               title="수정" aria-label="<?= esc($category->name, 'attr') ?> 수정">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                </svg>
                            </a>

                            <?php // 휴지통 아이콘: 삭제 ?>
                            <form action="<?= site_url('admin/categories/' . $category->id . '/delete') ?>" method="post" onsubmit="return confirm('삭제하면 이 카테고리의 글은 미분류로 옮겨집니다. 계속할까요?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="icon-btn icon-btn-danger"
                                        title="삭제" aria-label="<?= esc($category->name, 'attr') ?> 삭제">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M4 7h16" /><path d="M9 7V4h6v3" /><path d="M6 7l1 13h10l1-13" />
                                    </svg>
                                </button>
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
