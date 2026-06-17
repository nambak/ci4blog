<?php
/**
 * 글 목록의 분류 메뉴.
 *
 * @var \App\Entities\Category[]      $categories      전체 카테고리(이름순)
 * @var \App\Entities\Category|null   $activeCategory  현재 필터 중인 카테고리(없으면 전체)
 * @var string|null                   $search          현재 검색어(있으면 링크에 유지)
 */
$activeSlug = isset($activeCategory) && $activeCategory !== null ? $activeCategory->slug : null;

// 검색 중이면 카테고리를 바꿔도 검색어가 유지되도록 링크에 ?q= 를 붙인다.
$query = isset($search) && $search !== '' ? '?' . http_build_query(['q' => $search]) : '';
?>
<nav class="category-menu" aria-label="카테고리">
    <a class="chip<?= $activeSlug === null ? ' is-active' : '' ?>"
       href="<?= esc(site_url('posts') . $query) ?>">전체</a>
    <?php foreach ($categories as $category): ?>
        <a class="chip<?= $activeSlug === $category->slug ? ' is-active' : '' ?>"
           href="<?= esc($category->url . $query) ?>"><?= esc($category->name) ?></a>
    <?php endforeach ?>
</nav>
