<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddStatusAndParentToComments extends Migration
{
    public function up()
    {
        // ENUM 이 아니라 VARCHAR 인 이유: 운영(MySQL)과 테스트(SQLite)를 같은 마이그레이션으로
        // 덮기 위해서다. 값 제약은 CommentModel 의 in_list 검증이 맡는다(AddStatusToPosts 와 동일).
        //
        // 기존 댓글은 DEFAULT 'visible' · parent_id NULL 로 자동 백필된다. 지금 있는 댓글은
        // 전부 공개된 최상위 댓글이므로 별도 UPDATE 가 필요 없다.
        $this->forge->addColumn('comments', [
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
                'default'    => 'visible',
                'after'      => 'body',
            ],
            'parent_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'user_id',
            ],
        ]);

        // 공개 목록(status='visible')과 관리 탭이 status 로 거르고,
        // 스레드 구성이 parent_id 로 묶으므로 둘 다 인덱스를 건다.
        $this->forge->addKey('status');
        $this->forge->addKey('parent_id');
        $this->forge->processIndexes('comments');

        // 부모를 지우면 답글도 함께 사라진다(고아 답글 방지). 애플리케이션 코드가
        // 정리할 필요가 없다. SQLite 는 테이블 재생성이 필요해 MySQL 에서만 건다.
        // Forge 의 addForeignKey()+processIndexes() 는 이미 존재하는 테이블에도
        // ALTER TABLE ADD CONSTRAINT 를 발행한다(raw SQL 대신 프레임워크 API 사용).
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->addForeignKey('parent_id', 'comments', 'id', '', 'CASCADE', 'fk_comments_parent');
            $this->forge->processIndexes('comments');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->dropForeignKey('comments', 'fk_comments_parent');
        }

        $this->forge->dropColumn('comments', ['status', 'parent_id']);
    }
}
