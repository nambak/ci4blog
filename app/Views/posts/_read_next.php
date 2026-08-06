<?php

/**
 * "이어 읽기" — 발행일 순서로 인접한 이전 글 / 다음 글. (#114)
 *
 * 목업 Post 의 related 섹션을 옮긴 것이다
 * (docs/design/source/Post (Tailwind v4).html:158-176). 목업은 full-width 배경을
 * 쓰지만 이 저장소의 상세 화면은 720px 중앙 정렬 안에서 구분선으로 영역을 나눈다
 * (.engagement-bar 와 같은 방식). 그래서 배경 대신 border-top 을 쓴다.
 *
 * @var \App\Entities\Post|null $previous   더 오래된 글
 * @var \App\Entities\Post|null $next       더 최신 글
 * @var array<int, object>      $categories category_id => Category. 미분류 이웃은 키가 없다.
 */

// 이웃이 하나도 없으면 빈 상자를 띄우지 않는다(글이 한 건뿐인 블로그).
if ($previous === null && $next === null) {
    return;
}
?>
<section class="read-next">
    <h2 class="read-next-label">이어 읽기</h2>

    <div class="read-next-grid">
        <?php foreach ([$previous, $next] as $neighbor): ?>
            <?php if ($neighbor === null) {
                continue;
            } ?>
            <a class="read-next-card" href="<?= $neighbor->url ?>">
                <?php if ($neighbor->image !== null && $neighbor->image !== ''): ?>
                    <img class="read-next-cover" src="<?= esc(site_url('uploads/thumb_' . $neighbor->image), 'attr') ?>" alt="">
                <?php else: ?>
                    <div class="cover read-next-cover" style="background:<?= $neighbor->cover_gradient ?>"><?= esc($neighbor->cover_initial) ?></div>
                <?php endif ?>

                <?php // 미분류 이웃은 카테고리 줄 자체를 내지 않는다 — null 을 그대로 넘기면 화면이 터진다. ?>
                <?php $neighborCategory = $categories[(int) $neighbor->category_id] ?? null; ?>
                <?php if ($neighborCategory !== null): ?>
                    <div class="read-next-cat"><?= esc($neighborCategory->name) ?></div>
                <?php endif ?>

                <h3 class="read-next-heading"><?= esc($neighbor->title) ?></h3>
            </a>
        <?php endforeach ?>
    </div>
</section>
