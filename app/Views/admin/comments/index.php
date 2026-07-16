<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>댓글 관리<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?php
    // 뷰에서는 `use` 임포트를 쓰지 않는다(뷰 파일은 View::render 안에서 include 된다).
    // 탭 카운트는 검색 결과 안의 분포이고, 카드는 전체 기준이다.
    $visible = array_sum($counts);

    /** 현재 검색어·정렬을 유지한 채 status 만 바꾼 URL. */
    $tabUrl = static function (string $tab) use ($search, $sort): string {
        $query = ['status' => $tab];
        if ($search !== '') {
            $query['q'] = $search;
        }
        if ($sort !== 'newest') {
            $query['sort'] = $sort;
        }

        return site_url('admin/comments') . '?' . http_build_query($query);
    };

    $tabs = [
        'all'      => ['전체', $visible],
        'reported' => ['신고', $reportedCount],
        'hidden'   => ['숨김', $counts['hidden']],
    ];

    // 정렬 드롭다운 옵션. 키는 ?sort= 값(컨트롤러 화이트리스트와 일치).
    $sortOptions = [
        'newest' => '최신순',
        'oldest' => '오래된순',
    ];
    ?>

    <div class="dash">
        <div class="posts-head">
            <h1 class="page-title">댓글 관리</h1>
            <div class="posts-head-meta">
                지난 7일 · <strong><?= esc((string) $cards['week']) ?></strong>개의 새 댓글
            </div>
        </div>
        <p class="posts-sub">모든 글에 달린 댓글을 한 곳에서. 답글, 숨김, 삭제는 여기서 한 번에.</p>

        <?php $errors = session('errors') ?? []; ?>
        <?php if ($errors !== []): ?>
            <ul class="form-errors">
                <?php foreach ($errors as $error): ?>
                    <li><?= esc($error) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <?php // 값 검증용 id 는 대시보드(admin/dashboard.php)의 #kpi-* 관례를 따른다. ?>
        <section class="kpi-grid" aria-label="요약 지표">
            <div class="kpi-card">
                <span class="kpi-label">이번 주 새 댓글</span>
                <strong class="kpi-value" id="kpi-week"><?= esc((string) $cards['week']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">전체 댓글</span>
                <strong class="kpi-value" id="kpi-total"><?= esc((string) $cards['total']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">이번 달 총 댓글</span>
                <strong class="kpi-value" id="kpi-month"><?= esc((string) $cards['month']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">숨김 처리</span>
                <strong class="kpi-value" id="kpi-hidden"><?= esc((string) $cards['hidden']) ?></strong>
            </div>
        </section>

        <div class="ct-filters">
            <form class="posts-search" method="get" action="<?= site_url('admin/comments') ?>" role="search">
                <input type="hidden" name="status" value="<?= esc($status, 'attr') ?>">
                <input type="hidden" name="sort" value="<?= esc($sort, 'attr') ?>">
                <input type="search" name="q" value="<?= esc($search, 'attr') ?>" placeholder="댓글 또는 작성자 검색" aria-label="댓글 또는 작성자 검색">
                <button type="submit" class="btn btn-ghost">검색</button>
            </form>

            <?php // 정렬 드롭다운. GET 폼이라 JS 없이도 '정렬' 버튼으로 적용된다.
                  // 현재 status·q 를 hidden 으로 실어, 정렬을 바꿔도 탭·검색을 잃지 않는다. ?>
            <form class="ct-sort" method="get" action="<?= site_url('admin/comments') ?>">
                <input type="hidden" name="status" value="<?= esc($status, 'attr') ?>">
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="q" value="<?= esc($search, 'attr') ?>">
                <?php endif ?>
                <label class="ct-sort-label" for="ct-sort">정렬</label>
                <select name="sort" id="ct-sort" class="ct-sort-select" data-autosubmit>
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>"<?= $sort === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach ?>
                </select>
                <noscript><button type="submit" class="btn btn-ghost">적용</button></noscript>
            </form>
        </div>

        <section class="card">
            <div class="posts-tabs">
                <?php foreach ($tabs as $key => [$label, $count]): ?>
                    <a class="tab<?= $status === $key ? ' is-active' : '' ?>" href="<?= esc($tabUrl($key), 'attr') ?>">
                        <?= esc($label) ?> <span class="tab-count"><?= esc((string) $count) ?></span>
                    </a>
                <?php endforeach ?>
            </div>

            <?php if ($comments === []): ?>
                <p class="card-empty">이 조건의 댓글이 없습니다.</p>
            <?php else: ?>
                <?php // 일괄 폼은 이 바 하나뿐이다. 행의 체크박스는 form="bulk-form" 으로 여기에 붙는다.
                      // 행 안에 답글 <form> 이 들어가야 해서 테이블을 폼으로 감쌀 수 없다(폼 중첩은 무효). ?>
                <form id="bulk-form" method="post" action="<?= site_url('admin/comments/bulk') ?>">
                    <?= csrf_field() ?>

                    <div class="bulkbar" id="bulkbar">
                        <span class="bulkbar-count"><strong id="sel-count">0</strong>개 선택됨</span>
                        <span class="bulkbar-sep">|</span>
                        <button type="submit" class="bulk-btn" name="action" value="hide">숨김</button>
                        <button type="submit" class="bulk-btn" name="action" value="restore">복원</button>
                        <button type="submit" class="bulk-btn" name="action" value="review_reports">신고 처리</button>
                        <button type="submit" class="bulk-btn bulk-btn-danger" name="action" value="delete"
                                data-confirm="선택한 댓글을 삭제합니다. 답글도 함께 지워지고 되돌릴 수 없습니다. 계속할까요?">삭제</button>
                        <div class="bulkbar-spacer"></div>
                        <label class="bulkbar-all">
                            <input type="checkbox" id="check-all" aria-label="전체 선택"> 전체 선택
                        </label>
                    </div>
                </form>

                <ul class="ct-list">
                    <?php foreach ($comments as $comment): ?>
                        <li class="ct-row<?= $comment->isHidden() ? ' is-hidden' : '' ?>">
                            <input type="checkbox" class="row-check" form="bulk-form" name="ids[]"
                                   value="<?= esc($comment->id, 'attr') ?>"
                                   aria-label="<?= esc(mb_substr($comment->body, 0, 20), 'attr') ?> 선택">

                            <?= view('partials/avatar', ['avatar' => $comment->authorAvatar, 'name' => $comment->authorName, 'size' => 'sm']) ?>

                            <div class="ct-main">
                                <div class="ct-meta">
                                    <span class="ct-author"><?= esc($comment->authorName) ?></span>
                                    <?php if ($comment->isHidden()): ?>
                                        <span class="badge badge-hidden">숨김 처리됨</span>
                                    <?php endif ?>
                                    <?php $rc = $reportCounts[(int) $comment->id] ?? 0; ?>
                                    <?php if ($rc > 0): ?>
                                        <span class="badge badge-report">신고 <?= esc((string) $rc) ?></span>
                                    <?php endif ?>
                                    <?php if ($comment->created_at !== null): ?>
                                        <span class="ct-time">· <?= esc($comment->created_at->format('Y.m.d')) ?></span>
                                    <?php endif ?>
                                </div>

                                <div class="ct-post">
                                    <a href="<?= site_url('posts/' . $comment->post_slug) ?>"><?= esc($comment->post_title ?? '(삭제된 글)') ?></a>
                                </div>

                                <p class="ct-body"><?= nl2br(esc($comment->body)) ?></p>

                                <?php // 이 댓글에 달린 관리자 답글. 없으면 아무것도 그리지 않는다. ?>
                                <?php foreach ($replies[(int) $comment->id] ?? [] as $reply): ?>
                                    <?php // 답글은 여러 관리자가 달 수 있으므로, 내 답글이 아니면 실제 작성자명을 보여준다. ?>
                                    <div class="ct-reply">
                                        <span class="ct-reply-mark">↳ <?= (int) $reply->user_id === (int) auth()->id() ? '내 답글' : esc($reply->authorName) . ' 답글' ?></span> · <?= esc($reply->body) ?>
                                    </div>
                                <?php endforeach ?>

                                <?php // 빠른 답글. <details> 라 JS 없이 펼쳐진다.
                                      // 숨긴 댓글에는 답글을 달 수 없으므로 폼을 아예 그리지 않는다. ?>
                                <?php if (! $comment->isHidden()): ?>
                                    <details class="ct-replybox">
                                        <summary>답글</summary>
                                        <form method="post" action="<?= site_url('admin/comments/' . $comment->id . '/reply') ?>">
                                            <?= csrf_field() ?>
                                            <textarea name="body" rows="3"
                                                      placeholder="<?= esc($comment->authorName, 'attr') ?>님에게 답글…"
                                                      aria-label="답글 내용"></textarea>
                                            <div class="ct-replybox-foot">
                                                <button type="submit" class="btn">답글 남기기</button>
                                            </div>
                                        </form>
                                    </details>
                                <?php endif ?>
                            </div>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </section>

        <?= $pager->links('default', 'blog') ?>
    </div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
    <script src="<?= base_url('assets/js/admin-comments.js') ?>?v=<?= filemtime(FCPATH . 'assets/js/admin-comments.js') ?>" defer></script>
<?= $this->endSection() ?>
