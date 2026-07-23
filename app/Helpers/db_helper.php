<?php

/**
 * DB 오류 판별 헬퍼.
 *
 * "삽입 먼저 → 실패하면 이미 있는 것으로 본다" 구조(#88)가 좋아요·신고 세 곳에 있다.
 * 그 구조가 실패 원인을 구분하지 않으면, 중복이 아닌 실패에도 기존 행을 지워
 * 사용자가 방금 누른 좋아요가 사라진다(#107).
 */

if (! function_exists('is_duplicate_key_error')) {
    /**
     * 이 DB 오류가 유니크 제약 위반(중복)인가.
     *
     * 코드와 메시지를 함께 보는 이유가 있다 — **SQLite 는 NOT NULL·FK 위반에도
     * 같은 코드 19(SQLITE_CONSTRAINT)를 준다.** 코드만 보면 중복이 아닌 실패를
     * 중복으로 오판한다. 반면 MySQL 의 1062 는 중복 전용이라 코드로 충분하다.
     *
     * 두 신호 모두 실제 드라이버에서 받아 적은 것이다(tests/Feature/DbHelperTest.php).
     * 확인하지 않은 코드는 넣지 않는다 — 근거 없는 분기는 테스트할 수도 없다.
     *
     * @param int|string $code    $db->error()['code'] 또는 예외 코드
     * @param string     $message $db->error()['message'] 또는 예외 메시지
     */
    function is_duplicate_key_error(int|string $code, string $message): bool
    {
        // MySQL: ER_DUP_ENTRY
        if ((int) $code === 1062) {
            return true;
        }

        // SQLite: 코드가 제약 위반 전반을 가리키므로 메시지로 좁힌다.
        return str_contains($message, 'UNIQUE constraint failed');
    }
}
