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
    </div>
<?= $this->endSection() ?>
