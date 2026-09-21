<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>개인정보처리방침<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="page-title">개인정보처리방침</h1>

    <p>
        이 방침은 <?= esc(config('Blog')->title) ?>(이하 "블로그")가 어떤 정보를 어떤 목적으로
        다루는지, 이용자가 무엇을 요구할 수 있는지를 밝힙니다. 실제로 저장하는 것만 적었고,
        하지 않는 일은 적지 않았습니다.
    </p>

    <h2>1. 수집하는 항목과 목적</h2>

    <p>글을 읽기만 할 때는 계정이 필요 없고, 아래 항목도 수집하지 않습니다.</p>

    <ul>
        <li><strong>회원가입</strong> — 이메일 주소, 사용자명, 비밀번호. 비밀번호는 복원할 수 없는
            형태로 변환해 저장하며 원문은 보관하지 않습니다. 로그인과 본인 글·댓글 식별에 씁니다.</li>
        <li><strong>프로필 이미지</strong>(선택) — 올린 경우에만 저장하며, 댓글과 글에 표시됩니다.</li>
        <li><strong>댓글</strong> — 작성한 내용과 작성 시각. 작성자 계정과 연결해 저장합니다.</li>
        <li><strong>좋아요·신고</strong> — 어떤 글이나 댓글에 눌렀는지. 중복을 막고 집계하는 데 씁니다.</li>
        <li><strong>로그인 기록</strong> — 로그인 시도 시각과 성공 여부, <strong>IP 주소</strong>,
            브라우저 정보. 계정 도용을 확인하기 위한 것으로, 인증 라이브러리(CodeIgniter Shield)가
            자동으로 남깁니다.</li>
        <li><strong>요청 빈도 제한</strong> — 댓글·좋아요 등의 연속 요청을 막기 위해 IP 주소를
            되돌릴 수 없는 해시로 바꿔 짧은 시간 동안만 보관합니다. 원래 IP 주소는 저장하지 않습니다.</li>
    </ul>

    <h2>2. 쿠키와 제3자 서비스</h2>

    <p>이 블로그는 아래 쿠키를 씁니다.</p>

    <ul>
        <li><strong>필수 쿠키</strong> — 로그인 상태 유지(세션)와 위조 요청 차단(CSRF)에 필요합니다.
            이 쿠키가 없으면 로그인과 댓글 작성이 동작하지 않습니다.</li>
        <li><strong>분석·광고 쿠키</strong> — 아래 구글 서비스가 설정합니다.</li>
    </ul>

    <p>다음 제3자 서비스를 사용합니다. 각 서비스는 자체 정책에 따라 정보를 처리합니다.</p>

    <ul>
        <li><strong>Google Analytics</strong> · <strong>Google 태그 관리자</strong> — 어떤 글이 얼마나
            읽히는지 집계합니다. 개인을 식별하는 용도로는 쓰지 않습니다.</li>
        <li><strong>Google AdSense</strong> — 광고를 게재합니다. 구글을 포함한 제3자 공급업체는
            쿠키를 사용해 이용자가 이 사이트나 다른 사이트를 방문한 기록을 바탕으로 광고를 게재할 수
            있습니다.</li>
    </ul>

    <p>
        개인 최적화 광고는 이용자가 직접 끌 수 있습니다.
        <a href="https://myadcenter.google.com/" rel="noopener nofollow" target="_blank">Google 광고 센터</a>에서
        설정을 바꾸거나,
        <a href="https://www.aboutads.info/choices/" rel="noopener nofollow" target="_blank">aboutads.info</a>에서
        여러 업체의 설정을 한 번에 조정할 수 있습니다. Google Analytics 수집은
        <a href="https://tools.google.com/dlpage/gaoptout" rel="noopener nofollow" target="_blank">차단 브라우저 부가기능</a>으로
        막을 수 있습니다. 브라우저 설정에서 쿠키를 거부해도 되지만, 그 경우 로그인과 댓글 작성은
        동작하지 않습니다.
    </p>

    <h2>3. 보관 기간과 파기</h2>

    <p>
        계정 정보는 탈퇴를 요청할 때까지 보관하고, 요청을 받으면 지체 없이 지웁니다.
        댓글은 작성자가 직접 삭제할 수 있습니다.
    </p>

    <p>
        로그인 기록은 계정이 유지되는 동안 보관하며, 탈퇴나 삭제 요청을 받으면 계정
        정보와 함께 지웁니다. 요청 빈도 제한용 해시는 제한 시간이 지나면 자동으로
        만료됩니다.
    </p>

    <h2>4. 제3자 제공과 위탁</h2>

    <p>
        수집한 정보를 판매하거나 다른 곳에 넘기지 않습니다. 다만 위 2항의 구글 서비스는
        광고·분석을 위해 이용자의 브라우저에서 직접 정보를 수집하며, 이는 각 서비스의
        정책에 따릅니다. 법령에 근거한 요구가 있을 때는 그 범위에서만 따릅니다.
    </p>

    <h2>5. 이용자의 권리</h2>

    <p>
        언제든지 자신의 정보에 대한 열람·정정·삭제·처리정지를 요구할 수 있습니다.
        아래 연락처로 알려 주시면 확인 후 처리하고 결과를 회신합니다. 요구했다는 이유로
        불이익을 드리지 않습니다.
    </p>

    <h2>6. 개인정보 보호책임자</h2>

    <p>
        문의: <a href="mailto:support@unwanted.me">support@unwanted.me</a>
    </p>

    <h2>7. 방침의 변경</h2>

    <p>
        내용이 바뀌면 이 페이지에 반영하고 아래 시행일을 고칩니다. 중요한 변경은 별도로 알립니다.
    </p>

    <p><strong>시행일: <?= esc(config('Blog')->privacyUpdatedAt) ?></strong></p>
<?= $this->endSection() ?>
