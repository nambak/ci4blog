<?= $this->extend('layouts/default') ?>

<?= $this->section('title') ?>소개<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="page-title">소개</h1>

    <p>CodeIgniter 4로 블로그를 처음부터 만들어 가는 과정을 기록하는 곳입니다.</p>

    <p>
        프레임워크 설치에서 시작해 라우팅, 모델과 엔티티, 인증, 댓글, 검색, 배포까지
        한 회차에 하나씩 쌓아 올렸습니다. 각 글은 무엇을 만들었는지만이 아니라 왜
        그렇게 만들었는지, 어디서 막혔고 무엇을 잘못 짚었는지까지 남기려 했습니다.
        정리된 결과보다 막힌 자리가 더 오래 남기 때문입니다.
    </p>

    <h2>읽는 순서</h2>

    <p>
        처음이라면 1회차부터 순서대로 읽는 편이 낫습니다. 앞 회차에서 만든 것을 다음
        회차가 그대로 이어 쓰기 때문입니다. 필요한 주제만 찾는다면
        <a href="<?= site_url('posts') ?>">글 목록</a> 아래의 전체 목록에서 제목으로
        골라 가세요.
    </p>

    <h2>무엇으로 만들었나</h2>

    <p>
        CodeIgniter 4와 PHP 8.3으로 만들었고, 인증은 CodeIgniter Shield를 썼습니다.
        운영은 SQLite와 Nginx + PHP-FPM 조합입니다. 테스트는 PHPUnit Feature 테스트가
        중심이고, GitHub Actions가 테스트를 통과시킨 뒤에야 배포가 이뤄집니다.
    </p>

    <p>
        소스는 <a href="https://github.com/nambak/ci4blog" rel="noopener" target="_blank">GitHub</a>에
        공개돼 있습니다.
    </p>
<?= $this->endSection() ?>
