<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * 유니크 위반 판별(#107).
 *
 * 여기 쓰인 code·message 는 **지어낸 값이 아니라** 두 드라이버에서 실제로 받아 적은 것이다
 * (조사용 프로브 테스트로 확인 후 삭제). 지어낸 문자열로 테스트하면 실제와 어긋나도 통과한다.
 */
final class DbHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('db');
    }

    /** MySQL 은 중복 전용 코드(1062)가 있어 코드만으로 판별된다. */
    public function testDetectsMysqlDuplicateEntry(): void
    {
        $this->assertTrue(is_duplicate_key_error(1062, "Duplicate entry '1-1' for key 'probe_dup.a'"));
    }

    /** SQLite 는 메시지로 판별한다 — 코드 19 는 제약 위반 전반을 가리킨다. */
    public function testDetectsSqliteUniqueConstraint(): void
    {
        $this->assertTrue(is_duplicate_key_error(19, 'UNIQUE constraint failed: probe_dup.a, probe_dup.b'));
    }

    /**
     * 이 케이스가 이 술어의 존재 이유다.
     *
     * SQLite 는 NOT NULL 위반에도 **같은 코드 19** 를 준다. 코드만 보고 판별하면
     * 중복이 아닌 실패를 중복으로 오판해, 사용자가 누른 좋아요를 취소해 버린다.
     */
    public function testRejectsSqliteNotNullConstraintWithSameCode(): void
    {
        $this->assertFalse(is_duplicate_key_error(19, 'NOT NULL constraint failed: probe_dup.a'));
    }

    /** MySQL 의 다른 오류는 중복이 아니다. */
    public function testRejectsMysqlUnknownColumn(): void
    {
        $this->assertFalse(is_duplicate_key_error(1054, "Unknown column 'nope' in 'field list'"));
    }

    /** SQLite 의 다른 오류도 중복이 아니다. */
    public function testRejectsSqliteUnknownColumn(): void
    {
        $this->assertFalse(is_duplicate_key_error(1, 'table probe_dup has no column named nope'));
    }

    /** 드라이버가 코드를 문자열로 주는 경우에도 판별된다. */
    public function testAcceptsStringCode(): void
    {
        $this->assertTrue(is_duplicate_key_error('1062', "Duplicate entry '1-1' for key 'x'"));
    }
}
