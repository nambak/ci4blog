<?php
/**
 * 카테고리 입력 필드(name, slug). 추가/수정 폼이 공용으로 include.
 * 수정 시 $category(Category)가 넘어오고, old()가 있으면 old()가 우선한다.
 */
$nameValue = old('name') ?? (isset($category) ? $category->name : '');
$slugValue = old('slug') ?? (isset($category) ? $category->slug : '');
?>
<div>
    <label for="name">이름</label>
    <input type="text" name="name" id="name" value="<?= esc($nameValue, 'attr') ?>">
</div>
<div>
    <label for="slug">슬러그 <small>(선택 — 비우면 이름으로 자동 생성)</small></label>
    <input type="text" name="slug" id="slug" value="<?= esc($slugValue, 'attr') ?>">
</div>
