<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>새 글 작성<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="page-title">새 글 작성</h1>

    <?php // 검증 실패 시 컨트롤러가 flashdata 로 넘긴 에러를 보여 준다. ?>
    <?php $errors = session('errors') ?? []; ?>
    <?php if ($errors !== []): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <form class="form" action="<?= site_url('posts') ?>" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div>
            <label for="title">제목</label>
            <?php // old() 로 직전 입력값을 되살린다(withInput 과 짝). ?>
            <input type="text" name="title" id="title" value="<?= esc(old('title')) ?>">
        </div>

        <div>
            <label for="category_id">카테고리 <small>(선택)</small></label>
            <?php // 미선택은 빈 값 → 컨트롤러가 null 로 저장. 검증 실패 시 old() 로 선택 복원. ?>
            <select name="category_id" id="category_id">
                <option value="">— 카테고리 없음 —</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= esc($category->id) ?>"<?= (string) old('category_id') === (string) $category->id ? ' selected' : '' ?>><?= esc($category->name) ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <div>
            <label for="image">대표 이미지 <small>(선택)</small></label>
            <p class="field-hint">JPG·PNG·WebP, 2MB 이하. 목록에는 썸네일로 보입니다.</p>
            <input type="file" name="image" id="image" accept="image/png,image/jpeg,image/webp">
        </div>

        <div>
            <label for="body">본문</label>
            <p class="field-hint">마크다운으로 작성할 수 있습니다 — <code># 제목</code>, <code>**굵게**</code>, <code>[링크](https://…)</code></p>
            <textarea name="body" id="body" rows="12"><?= esc(old('body')) ?></textarea>
        </div>

        <button type="submit" class="btn">저장</button>
        <a class="btn btn-ghost" href="<?= site_url('posts') ?>">취소</a>
    </form>
<?= $this->endSection() ?>
