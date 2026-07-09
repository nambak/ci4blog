<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>게시글 관리<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?php
    // 뷰에서는 `use` 임포트를 쓰지 않는다(뷰 파일은 View::render 안에서 include 된다).
    // 탭 키는 곧 status 질의 문자열이므로 리터럴로 충분하다('all' 은 필터 없음).
    $total = array_sum($totals);

    /** 현재 검색어를 유지한 채 status 만 바꾼 URL. */
    $tabUrl = static function (string $tab) use ($search): string {
        $query = ['status' => $tab];
        if ($search !== '') {
            $query['q'] = $search;
        }

        return site_url('admin/posts') . '?' . http_build_query($query);
    };

    $tabs = [
        'all'       => ['전체', $total],
        'published' => ['발행됨', $counts['published']],
        'draft'     => ['임시저장', $counts['draft']],
        'private'   => ['비공개', $counts['private']],
    ];
    ?>

    <div class="dash">
        <div class="posts-head">
            <h1 class="page-title">게시글 관리</h1>
            <div class="posts-head-meta">
                총 <strong><?= esc((string) $total) ?></strong>개 글 ·
                발행 <strong><?= esc((string) $totals['published']) ?></strong>
            </div>
        </div>
        <p class="posts-sub">발행·임시저장·비공개 글을 한 곳에서. 상태를 바꾸고, 카테고리를 옮깁니다.</p>

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
                <span class="kpi-label">발행된 글</span>
                <strong class="kpi-value" id="kpi-published"><?= esc((string) $totals['published']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">임시저장</span>
                <strong class="kpi-value" id="kpi-draft"><?= esc((string) $totals['draft']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">비공개</span>
                <strong class="kpi-value" id="kpi-private"><?= esc((string) $totals['private']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">받은 댓글 <small>지난 30일</small></span>
                <strong class="kpi-value" id="kpi-comments-30"><?= esc((string) $commentsLast30) ?></strong>
            </div>
        </section>

        <form class="posts-search" method="get" action="<?= site_url('admin/posts') ?>" role="search">
            <input type="hidden" name="status" value="<?= esc($status, 'attr') ?>">
            <input type="search" name="q" value="<?= esc($search, 'attr') ?>" placeholder="제목 검색" aria-label="제목 검색">
            <button type="submit" class="btn btn-ghost">검색</button>
        </form>

        <section class="card">
            <div class="posts-tabs">
                <?php foreach ($tabs as $key => [$label, $count]): ?>
                    <a class="tab<?= $status === $key ? ' is-active' : '' ?>" href="<?= esc($tabUrl($key), 'attr') ?>">
                        <?= esc($label) ?> <span class="tab-count"><?= esc((string) $count) ?></span>
                    </a>
                <?php endforeach ?>
            </div>

            <?php if ($posts === []): ?>
                <p class="card-empty">이 상태의 글이 없습니다.</p>
            <?php else: ?>
                <table class="posts-table">
                    <thead>
                        <tr>
                            <th class="col-title">제목</th>
                            <th class="col-category">카테고리</th>
                            <th class="col-status">상태</th>
                            <th class="col-date">날짜</th>
                            <th class="col-actions">작업</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post): ?>
                            <tr>
                                <td class="col-title">
                                    <div class="posts-title-cell">
                                        <?php if ($post->image !== null && $post->image !== ''): ?>
                                            <img class="posts-cover" src="<?= site_url('uploads/thumb_' . $post->image) ?>" alt="">
                                        <?php else: ?>
                                            <div class="posts-cover cover" style="background:<?= $post->cover_gradient ?>"><?= esc($post->cover_initial) ?></div>
                                        <?php endif ?>
                                        <div class="posts-title-text">
                                            <span class="posts-title"><?= esc($post->title) ?></span>
                                            <span class="posts-meta">댓글 <?= esc((string) $post->comment_count) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="col-category">
                                    <span class="chip"><?= esc($post->category_name ?? '미분류') ?></span>
                                </td>
                                <td class="col-status">
                                    <span class="badge badge-<?= esc($post->status, 'attr') ?>"><?= esc($post->statusLabel()) ?></span>
                                </td>
                                <td class="col-date">
                                    <?= $post->created_at !== null ? esc($post->created_at->format('Y.m.d')) : '—' ?>
                                </td>
                                <td class="col-actions">
                                    <a class="posts-action" href="<?= site_url('posts/' . $post->id . '/edit') ?>">수정</a>
                                    <a class="posts-action" href="<?= site_url('posts/' . $post->slug) ?>">보기</a>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            <?php endif ?>
        </section>

        <?= $pager->links('default', 'blog') ?>
    </div>
<?= $this->endSection() ?>
