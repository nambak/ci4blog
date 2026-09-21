<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * 블로그 표시 설정.
 *
 * 사이트 제목 등 "공개 저장소엔 일반값만 두고, 실제 운영값은 서버에서만"
 * 두고 싶은 값을 모은다. 아래 기본값은 공개되어도 무방한 일반값이며,
 * 운영 서버에서는 .env 로 덮어쓴다(.env 는 gitignore 됨):
 *
 *   blog.title = '실제 블로그 제목'
 */
class Blog extends BaseConfig
{
    /**
     * 헤더 브랜드·푸터·브라우저 탭에 쓰이는 사이트 제목.
     */
    public string $title = 'CI4 Blog';

    /** 메타 설명·OG 의 기본 문구. about 페이지 첫 문장과 같은 내용을 유지한다. */
    public string $description = 'CodeIgniter 4로 한 회차씩 만들어 가는 학습용 블로그입니다.';

    /**
     * /about 본문을 마지막으로 고친 날짜(sitemap 의 lastmod 로 나간다).
     *
     * 손으로 적는 값이다. 본문을 고치면 이 날짜도 함께 고쳐 주세요.
     *
     * 파일 mtime 을 쓰지 않는 이유가 있다. 배포가 git pull 이라 서버를 다시 세우기만
     * 해도 mtime 이 바뀌는데, 그러면 내용이 그대로인데 매번 "방금 바뀌었다" 고 알리게
     * 된다. 그런 lastmod 는 크롤러가 곧 신뢰하지 않는다.
     *
     * 잊고 안 고치면 낡은 날짜가 남는다. 그쪽이 안전한 방향이다 — 없는 변경을 알리는
     * 것보다 있는 변경을 늦게 알리는 편이 낫다.
     */
    public string $aboutUpdatedAt = '2026-08-13';

    /**
     * 개인정보처리방침 본문을 마지막으로 고친 날짜(sitemap 의 lastmod).
     *
     * $aboutUpdatedAt 과 같은 규칙이다 — 손으로 적고, 본문을 고치면 함께 고친다.
     * 방침은 언제 바뀌었는지가 고지 의무의 일부라 날짜가 특히 중요하다.
     */
    public string $privacyUpdatedAt = '2026-09-21';

    /**
     * 로그인 기록(auth_logins·auth_token_logins)을 보관할 일수. (#184)
     *
     * `php spark auth:prune` 의 기본값이자 **개인정보처리방침 화면에 그대로 찍히는
     * 숫자**다. 출처를 하나로 두는 것이 핵심이다 — 커맨드에 기본값을 따로 박으면
     * 방침이 약속한 기간과 실제 동작이 조용히 갈라진다. 그 드리프트가 이 이슈를
     * 만든 실패 유형이다(방침은 파기를 약속했는데 지우는 코드가 없었다).
     *
     * 90일인 근거: 이 기록의 목적은 계정 도용 확인이고, 분기 단위로 되짚어 볼 수
     * 있는 창을 남기되 IP·브라우저 정보를 그 이상 쌓아 두지 않는 선이다.
     *
     * 이 값을 바꾸면 방침 화면의 문구도 함께 바뀐다. $privacyUpdatedAt 을 함께
     * 갱신해 주세요 — 보관 기간 변경은 고지 대상이다.
     */
    public int $loginLogKeepDays = 90;
}