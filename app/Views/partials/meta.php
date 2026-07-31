<?php
/**
 * 검색·SNS 미리보기용 메타태그. (#113)
 *
 * 컨트롤러가 넘긴 $meta 배열(전부 선택)을 읽고, 없는 값은 사이트 기본값으로
 * 채운다. 그래서 meta 를 아예 넘기지 않은 페이지도 정확한 태그를 갖는다.
 *
 *   title       원문 문자열(이스케이프 전)   기본: Blog->title
 *   description 원문 문자열                 기본: Blog->description
 *   image       절대 URL                    없으면 og:image 를 내지 않는다
 *   type        'website' | 'article'       기본: 'website'
 *
 * 이스케이프는 여기서 한 번만 한다. 컨트롤러가 esc() 해서 넘기면 이중
 * 이스케이프(`&` → `&amp;amp;`)가 되므로 원문을 받는 것이 계약이다.
 *
 * esc($v, 'attr') 을 쓰지 않는 이유가 있다 — attr 컨텍스트는 영숫자 외 모든
 * 문자를 숫자 엔티티로 바꾼다(공백조차 `&#x20;`). 한국어 설명이 5배로 부풀어
 * 156자 description 이 1000자를 넘기고, 파서에 따라 잘려 나간다. content 는
 * 큰따옴표로 감싸므로 esc($v) 가 `"` 를 `&quot;` 로 바꿔 주는 것으로 안전하다.
 *
 * 제목을 $this->renderSection('title') 로 가져오지 않는 이유가 있다 —
 * renderSection 은 읽으면서 섹션을 지우고(View.php:463), 그 내용은 뷰가 이미
 * esc() 한 HTML 이라 attribute 에 다시 넣으면 이중 이스케이프된다.
 */
$blog = config('Blog');

$metaTitle       = $meta['title'] ?? $blog->title;
$metaDescription = $meta['description'] ?? $blog->description;
$metaImage       = $meta['image'] ?? null;
$metaType        = $meta['type'] ?? 'website';
?>
<meta name="description" content="<?= esc($metaDescription) ?>">
<meta property="og:type" content="<?= esc($metaType) ?>">
<meta property="og:site_name" content="<?= esc($blog->title) ?>">
<meta property="og:title" content="<?= esc($metaTitle) ?>">
<meta property="og:description" content="<?= esc($metaDescription) ?>">
<?php // canonical 과 같은 값을 쓴다 — 정본 URL 이 갈라지면 중복 콘텐츠가 된다. ?>
<meta property="og:url" content="<?= esc(base_url(uri_string())) ?>">
<meta property="og:locale" content="ko_KR">
<?php if ($metaImage !== null): ?>
<meta property="og:image" content="<?= esc($metaImage) ?>">
<?php endif ?>
<?php // X 는 og: 태그를 폴백으로 읽으므로 카드 종류만 지정한다. ?>
<meta name="twitter:card" content="<?= $metaImage !== null ? 'summary_large_image' : 'summary' ?>">
