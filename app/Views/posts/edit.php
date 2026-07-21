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
            <label for="category_id">카테고리 <small>(선택)</small></label>
            <?php // old() 가 없으면 기존 글의 category_id 로 미리 선택한다. ?>
            <?php $selectedCategory = old('category_id', $post->category_id); ?>
            <select name="category_id" id="category_id">
                <option value="">— 카테고리 없음 —</option>
                <?php // 이 글이 속한 카테고리가 숨김이어도 목록에 있어야 선택이 유지된다(#67). ?>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= esc($category->id) ?>"<?= (string) $selectedCategory === (string) $category->id ? ' selected' : '' ?>><?= esc($category->name) ?><?= $category->is_visible ? '' : ' (숨김)' ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <div>
            <label for="status">상태</label>
            <p class="field-hint">임시저장·비공개 글은 목록에 나오지 않고, 나와 관리자만 볼 수 있습니다.</p>
            <?php $selectedStatus = (string) old('status', $post->status); ?>
            <select name="status" id="status">
                <option value="published"<?= $selectedStatus === 'published' ? ' selected' : '' ?>>발행됨</option>
                <option value="draft"<?= $selectedStatus === 'draft' ? ' selected' : '' ?>>임시저장</option>
                <option value="private"<?= $selectedStatus === 'private' ? ' selected' : '' ?>>비공개</option>
            </select>
        </div>

        <div>
            <label for="image">대표 이미지 <small>(선택)</small></label>
            <?php if ($post->image !== null && $post->image !== ''): ?>
                <p><img class="image-current" src="<?= esc(site_url('uploads/thumb_' . $post->image), 'attr') ?>" alt="현재 대표 이미지"></p>
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
