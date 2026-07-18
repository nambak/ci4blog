<?php
/**
 * 지난주 대비 증감 배지. $delta: 양수=증가, 음수=감소, 0=변화 없음.
 *
 * 화살표·색만으로 의미를 전하지 않도록 aria-label 에 전체 문구를 담고,
 * 눈에 보이는 화살표·숫자는 aria-hidden 으로 보조 표시만 한다.
 */
$magnitude = abs($delta);
?>
<?php if ($delta > 0): ?>
    <span class="kpi-delta kpi-delta-up" aria-label="지난주 대비 <?= esc((string) $magnitude, 'attr') ?> 증가">
        <span aria-hidden="true">▲ <?= esc((string) $magnitude) ?></span>
    </span>
<?php elseif ($delta < 0): ?>
    <span class="kpi-delta kpi-delta-down" aria-label="지난주 대비 <?= esc((string) $magnitude, 'attr') ?> 감소">
        <span aria-hidden="true">▼ <?= esc((string) $magnitude) ?></span>
    </span>
<?php else: ?>
    <span class="kpi-delta kpi-delta-flat" aria-label="지난주 대비 변화 없음">
        <span aria-hidden="true">–</span>
    </span>
<?php endif ?>
