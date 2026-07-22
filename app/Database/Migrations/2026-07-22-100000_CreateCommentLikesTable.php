<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCommentLikesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'comment_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // post_likes 와 같은 이유로 유니크 키가 중복 방지의 중심이다 — 검사-후-삽입은
        // 동시 요청 두 개가 모두 통과하는 레이스가 있어 DB 가 막아야 한다(#88).
        // 목록의 일괄 집계(whereIn comment_id)도 이 키의 선두 컬럼을 인덱스로 쓴다.
        $this->forge->addUniqueKey(['comment_id', 'user_id']);
        $this->forge->createTable('comment_likes');

        // 댓글을 지우면 좋아요도 함께 사라진다. SQLite 는 테이블 재생성이 필요해
        // MySQL 에서만 건다(post_likes·comment_reports 와 같은 방식).
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->addForeignKey('comment_id', 'comments', 'id', '', 'CASCADE', 'fk_comment_likes_comment');
            $this->forge->processIndexes('comment_likes');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->dropForeignKey('comment_likes', 'fk_comment_likes_comment');
        }

        $this->forge->dropTable('comment_likes');
    }
}
