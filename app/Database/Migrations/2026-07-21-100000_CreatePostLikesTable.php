<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePostLikesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'post_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // 한 사용자는 한 글에 좋아요를 한 번만 남긴다. 이 제약이 중복 방지의 중심이다 —
        // 검사-후-삽입은 동시 요청 두 개가 모두 통과하는 레이스가 있어 DB 가 막아야 한다(#88).
        // 카운트·조회는 이 유니크 키의 선두 컬럼(post_id)이 인덱스를 겸한다.
        $this->forge->addUniqueKey(['post_id', 'user_id']);
        $this->forge->createTable('post_likes');

        // 글을 지우면 좋아요도 함께 사라진다. SQLite 는 테이블 재생성이 필요해 MySQL 에서만 건다
        // (comment_reports 마이그레이션과 같은 방식).
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->addForeignKey('post_id', 'posts', 'id', '', 'CASCADE', 'fk_post_likes_post');
            $this->forge->processIndexes('post_likes');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->dropForeignKey('post_likes', 'fk_post_likes_post');
        }

        $this->forge->dropTable('post_likes');
    }
}
