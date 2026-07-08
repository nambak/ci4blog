<?php
/**
 * 아바타 썸네일. $avatar(경로|null), $name(이니셜 폴백용), $size('sm'|'md'|'lg')를 받는다.
 * 파일이 있으면 이미지를, 없으면 이름 첫 글자 원을 그린다.
 */
$size = $size ?? 'md';
?>
<?php if (! empty($avatar)): ?>
    <img class="avatar avatar-<?= esc($size, 'attr') ?>" src="<?= site_url('uploads/' . $avatar) ?>" alt="<?= esc($name) ?>">
<?php else: ?>
    <span class="avatar avatar-<?= esc($size, 'attr') ?> avatar-initial"
          style="background:hsl(<?= abs(crc32((string) $name)) % 360 ?>,38%,82%)">
        <?= esc(mb_strtoupper(mb_substr((string) $name, 0, 1))) ?>
    </span>
<?php endif ?>
