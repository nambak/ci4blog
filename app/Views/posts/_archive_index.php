<?php

/**
 * 전체 글 색인 — 목록 1페이지 하단. (#GSC 색인)
 *
 * 카드 목록이 아니라 제목 한 줄짜리 링크 묶음이다. 목적이 읽는 재미가 아니라
 * **도달성**이기 때문이다. 카드에 이미 보인 글은 빼고 그 나머지만 싣는다(#148) —
 * 같은 화면에 같은 글이 두 번 보이면 읽는 사람에게는 혼란만 준다. 라이브 실측에서 글 31건 중 20건이 홈으로부터 3클릭,
 * 1건이 4클릭이었고, 페이지네이션 링크를 빼면 12건만 닿았다. /posts 가 홈에서
 * 1클릭이므로 여기 전부를 링크하면 모든 글이 2클릭 안에 들어온다.
 *
 * 이전/다음 글 사슬(#114)로는 이 문제가 풀리지 않는다. 사슬은 31개를 잇지만
 * 끝에서 끝까지 30클릭이라 깊이가 줄지 않는다.
 *
 * 글이 수백 건이 되면 이 방식은 한계가 온다. 그때는 연도별로 나누고 연도 페이지를
 * 여기에 링크하면 된다 — 깊이는 2클릭 그대로다.
 *
 * @var list<\App\Entities\Post> $archive 카드에 없는 발행 글(최신순). 비면 아무것도 그리지 않는다.
 */

if ($archive === []) {
    return;
}
?>
<nav class="archive-index" aria-label="이전 글 목록">
    <h2 class="archive-index-label">이전 글 <?= count($archive) ?>편</h2>

    <ul class="archive-index-list">
        <?php foreach ($archive as $item): ?>
            <li>
                <a href="<?= $item->url ?>"><?= esc($item->title) ?></a>
                <?php if ($item->created_at !== null): ?>
                    <time datetime="<?= esc($item->created_at->format('Y-m-d')) ?>"><?= esc($item->created_at->format('Y-m-d')) ?></time>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>
</nav>
