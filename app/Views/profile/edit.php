<?= $this->extend('layouts/default') ?>

<?= $this->section('content') ?>
<section class="profile-wrap">
    <header class="profile-head">
        <h1>프로필 관리</h1>
        <p class="profile-sub">공개 프로필과 계정 정보를 관리합니다.</p>
    </header>

    <?php if (session('errors')): ?>
        <ul class="form-errors">
            <?php foreach ((array) session('errors') as $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <?php if (session('message')): ?>
        <p class="form-message"><?= esc(session('message')) ?></p>
    <?php endif ?>

    <?php // 프로필 사진 ?>
    <div class="profile-card">
        <h2>프로필 사진</h2>
        <div class="avatar-row">
            <?= view('partials/avatar', ['avatar' => $user->avatar, 'name' => $user->username, 'size' => 'lg']) ?>
            <p class="avatar-hint">JPG, PNG 또는 GIF · 최대 2MB · 정사각형 이미지를 권장합니다.</p>
        </div>
    </div>

    <?php // 계정 정보 + 비밀번호를 한 폼으로 저장. 비밀번호 칸은 비우면 유지. ?>
    <form action="<?= site_url('profile') ?>" method="post" enctype="multipart/form-data" class="profile-card">
        <?= csrf_field() ?>

        <h2>계정 정보</h2>

        <label class="field">
            <span>사용자 이름</span>
            <input type="text" name="username" value="<?= esc(old('username', $user->username)) ?>" required>
            <small>블로그와 댓글에 표시되는 이름입니다.</small>
        </label>

        <label class="field">
            <span>프로필 사진 변경</span>
            <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif">
        </label>

        <h2>비밀번호 변경</h2>
        <p class="field-note">바꾸지 않으려면 비워 두세요.</p>

        <label class="field">
            <span>현재 비밀번호</span>
            <input type="password" name="current_password" autocomplete="current-password">
        </label>

        <label class="field">
            <span>새 비밀번호</span>
            <input type="password" name="new_password" autocomplete="new-password" placeholder="새 비밀번호 입력">
        </label>

        <label class="field">
            <span>새 비밀번호 확인</span>
            <input type="password" name="new_password_confirm" autocomplete="new-password" placeholder="다시 입력">
        </label>

        <div class="profile-actions">
            <button type="submit" class="btn">변경사항 저장</button>
        </div>
    </form>

    <?php // 아바타 삭제(별도 폼 — 현재 사진 제거) ?>
    <?php if ($user->avatar): ?>
        <form action="<?= site_url('profile/avatar/delete') ?>" method="post" class="profile-card"
              onsubmit="return confirm('프로필 사진을 삭제할까요?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost">프로필 사진 삭제</button>
        </form>
    <?php endif ?>
</section>
<?= $this->endSection() ?>
