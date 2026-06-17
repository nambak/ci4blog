<?php
/**
 * 글 목록의 분류 메뉴.
 *
 * @var \App\Entities\Category[]      $categories     전체 카테고리(이름순)
 * @var \App\Entities\Category|null   $activeCategory  현재 필터 중인 카테고리(없으면 전체)
 */
$activeSlug = isset($activeCategory) && $activeCategory !== null ? $activeCategory->slug : null;
?>
<nav class="category-menu" aria-label="카테고리">
    <a class="chip<?= $activeSlug === null ? ' is-active' : '' ?>" href="<?= site_url('posts') ?>">전체</a>
    <?php foreach ($categories as $category): ?>
        <a class="chip<?= $activeSlug === $category->slug ? ' is-active' : '' ?>"
           href="<?= esc($category->url, 'attr') ?>"><?= esc($category->name) ?></a>
    <?php endforeach ?>
</nav>
