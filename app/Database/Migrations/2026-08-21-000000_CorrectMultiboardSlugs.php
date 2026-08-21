<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 멀티보드 ep01·ep02 의 slug 을 원고가 정한 값으로 되돌린다.
 *
 * 두 글은 slug 보존 수정(PR #153, 2026-08-18 10:27Z 배포) **이전에** 발행됐다. 그때는
 * 관리 화면에서 저장할 때마다 콜백이 제목으로 slug 을 다시 만들었고, 그래서 원고가
 * 정한 영문 slug 이 한글 제목 slug 으로 덮어써졌다. 같은 원고를 다시 발행하면 upsert
 * 키가 안 맞아 새 글이 하나 더 생긴다.
 *
 * 같은 시각 23분 뒤에 발행된 ep03(ci4-multiboard-03-schema-blueprint)은 정상이다.
 * 그래서 대상은 앞의 두 편뿐이다.
 *
 * 이 마이그레이션은 **운영 데이터 전용**이다. 로컬·테스트 DB 에는 대상 행이 없으므로
 * 아무 일도 하지 않는다(그게 정상이다).
 *
 * ⚠️ 옛 한글 URL 은 이 교정 이후 404 가 된다. 리다이렉트를 두지 않기로 한 결정이다.
 */
class CorrectMultiboardSlugs extends Migration
{
    /**
     * 옛 slug(제목에서 만들어진 값) => 원고 front matter 가 정한 slug.
     * 옛 값은 운영 sitemap 의 URL 을 디코딩해 그대로 옮겼다.
     */
    private const CORRECTIONS = [
        'codeigniter-4로-멀티보드-만들기-1-왜-게시판마다-테이블을-만들지-않는가' => 'ci4-multiboard-01-three-designs',
        'codeigniter-4로-멀티보드-만들기-2-프로젝트-세팅과-이-강좌의-규칙'        => 'ci4-multiboard-02-project-setup',
    ];

    public function up(): void
    {
        foreach (self::CORRECTIONS as $old => $new) {
            // 이미 그 slug 을 쓰는 글이 있으면 건드리지 않는다. slug 이 겹치면 상세 조회가
            // 어느 글을 집을지 알 수 없게 되고, 그건 지금 고치려는 문제보다 나쁘다.
            if ($this->db->table('posts')->where('slug', $new)->countAllResults() > 0) {
                continue;
            }

            $this->db->table('posts')->where('slug', $old)->update(['slug' => $new]);

            // 무엇을 바꿨는지 남긴다. down() 이 자동으로 되돌리지 않으므로(아래 참고)
            // 손으로 복구해야 할 때 이 기록이 유일한 단서다.
            if ($this->db->affectedRows() > 0) {
                log_message('notice', sprintf(
                    'CorrectMultiboardSlugs: slug 교정 %d건 — "%s" → "%s"',
                    $this->db->affectedRows(),
                    $old,
                    $new
                ));
            }
        }
    }

    /**
     * 되돌리지 않는다.
     *
     * "새 slug → 옛 slug" 를 그대로 수행하면, up() 이 충돌 가드로 **건너뛴** 경우에
     * 멀쩡한 글을 한글 slug 으로 망가뜨린다. up() 이 아무것도 하지 않았는데 down() 이
     * 데이터를 바꾸는 셈이다. up() 이후 다른 글이 그 slug 을 쓰게 된 경우도 마찬가지다.
     *
     * 어느 행을 실제로 바꿨는지 기록 없이는 정확한 복원이 불가능하고, **부정확한 복원은
     * 교정하지 않는 것보다 나쁘다.** 그래서 여기서는 아무 일도 하지 않고, 복구가 필요하면
     * up() 이 남긴 로그(notice)를 보고 손으로 되돌린다.
     */
    public function down(): void
    {
        log_message('notice', 'CorrectMultiboardSlugs: down() 은 자동 복원하지 않는다. up() 로그를 보고 손으로 되돌릴 것.');
    }
}
