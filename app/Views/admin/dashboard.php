<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>관리자 대시보드<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="dash">
        <h1 class="page-title">대시보드</h1>

        <section class="kpi-grid" aria-label="요약 지표">
            <div class="kpi-card">
                <span class="kpi-label">전체 글</span>
                <strong class="kpi-value" id="kpi-posts"><?= esc((string) $stats['posts']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">전체 댓글</span>
                <strong class="kpi-value" id="kpi-comments"><?= esc((string) $stats['comments']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">카테고리</span>
                <strong class="kpi-value" id="kpi-categories"><?= esc((string) $stats['categories']) ?></strong>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">이번 달 새 글</span>
                <strong class="kpi-value" id="kpi-month"><?= esc((string) $stats['postsThisMonth']) ?></strong>
            </div>
        </section>

        <div class="dash-cols">
            <section class="card" aria-label="최근 글">
                <?php // 게시글 관리로 가는 길은 이 카드 안에 둔다(카테고리 분포 카드와 같은 자리). ?>
                <div class="card-head">
                    <h2>최근 글</h2>
                    <a class="card-link" href="<?= site_url('admin/posts') ?>">게시글 관리 →</a>
                </div>
                <?php if (empty($recentPosts)): ?>
                    <p class="card-empty">아직 글이 없습니다.</p>
                <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($recentPosts as $post): ?>
                            <li>
                                <a href="<?= site_url('posts/' . $post->slug) ?>" class="dash-list-title"><?= esc($post->title) ?></a>
                                <span class="dash-list-meta"><?= esc($post->created_at) ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </section>

            <section class="card" aria-label="최근 댓글">
                <div class="card-head"><h2>최근 댓글</h2></div>
                <?php if (empty($recentComments)): ?>
                    <p class="card-empty">아직 댓글이 없습니다.</p>
                <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($recentComments as $comment): ?>
                            <li>
                                <a href="<?= site_url('posts/' . $comment->post_slug) ?>" class="dash-list-title"><?= esc($comment->body) ?></a>
                                <span class="dash-list-meta"><?= esc($comment->post_title) ?> · <?= esc($comment->created_at) ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </section>

            <section class="card" aria-label="카테고리 분포">
                <div class="card-head">
                    <h2>카테고리 분포</h2>
                    <a class="card-link" href="<?= site_url('admin/categories') ?>">카테고리 관리 →</a>
                </div>
                <?php if (empty($categoryDist)): ?>
                    <p class="card-empty">글이 없습니다.</p>
                <?php else: ?>
                    <ul class="dash-dist">
                        <?php foreach ($categoryDist as $row): ?>
                            <li>
                                <span class="dash-dist-name"><?= esc($row->name) ?></span>
                                <span class="dash-dist-cnt"><?= esc((string) $row->cnt) ?></span>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </section>
        </div>

        <section class="dash-actions" aria-label="빠른 작업">
            <a class="btn" href="<?= site_url('posts/new') ?>">새 글 쓰기</a>
            <a class="btn btn-ghost" href="<?= site_url('posts') ?>">아카이브 보기</a>
        </section>
    </div>
<?= $this->endSection() ?>
