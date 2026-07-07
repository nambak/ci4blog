<?php

use CodeIgniter\Pager\PagerRenderer;

/**
 * 블로그용 페이지네이션 템플릿.
 *
 * CI4 기본(default_full)과 달리:
 * - '← 이전 / 다음 →' 을 항상 노출하고, 끝 페이지에서는 disabled(클릭 불가)로 표시
 * - 현재 페이지를 aria-current + .active 로 강조해 위치를 알 수 있게 함
 * - 한국어 라벨
 * 마크업(nav>ul.pagination>li)은 public/assets/css/app.css 의 .pagination 규칙과 맞춘다.
 *
 * @var PagerRenderer $pager
 */
$pager->setSurroundCount(2);
?>
<nav class="pager" aria-label="페이지 이동">
    <ul class="pagination">
        <li class="page-prev<?= $pager->hasPreviousPage() ? '' : ' disabled' ?>">
            <?php if ($pager->hasPreviousPage()): ?>
                <a href="<?= $pager->getPreviousPage() ?>" rel="prev">← 이전</a>
            <?php else: ?>
                <span>← 이전</span>
            <?php endif ?>
        </li>

        <?php foreach ($pager->links() as $link): ?>
            <li class="<?= $link['active'] ? 'active' : '' ?>">
                <?php if ($link['active']): ?>
                    <span aria-current="page"><?= $link['title'] ?></span>
                <?php else: ?>
                    <a href="<?= $link['uri'] ?>"><?= $link['title'] ?></a>
                <?php endif ?>
            </li>
        <?php endforeach ?>

        <li class="page-next<?= $pager->hasNextPage() ? '' : ' disabled' ?>">
            <?php if ($pager->hasNextPage()): ?>
                <a href="<?= $pager->getNextPage() ?>" rel="next">다음 →</a>
            <?php else: ?>
                <span>다음 →</span>
            <?php endif ?>
        </li>
    </ul>
</nav>
