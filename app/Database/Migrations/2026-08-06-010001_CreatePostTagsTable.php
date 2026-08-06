<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePostTagsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'post_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'tag_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
        ]);

        $this->forge->addKey('id', true);
        // post_likes 와 같은 이유로 유니크 키가 중복 방지의 중심이다 — 검사-후-삽입은
        // 동시 요청 두 개가 모두 통과하는 레이스가 있어 DB 가 막아야 한다(#88).
        // 글 상세의 태그 조회(where post_id)도 이 키의 선두 컬럼을 인덱스로 쓴다.
        $this->forge->addUniqueKey(['post_id', 'tag_id']);
        $this->forge->createTable('post_tags');

        // 글·태그를 지우면 연결도 함께 사라진다. SQLite 는 테이블 재생성이 필요해
        // MySQL 에서만 건다(post_likes·comment_likes 와 같은 방식).
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->addForeignKey('post_id', 'posts', 'id', '', 'CASCADE', 'fk_post_tags_post');
            $this->forge->addForeignKey('tag_id', 'tags', 'id', '', 'CASCADE', 'fk_post_tags_tag');
            $this->forge->processIndexes('post_tags');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->dropForeignKey('post_tags', 'fk_post_tags_post');
            $this->forge->dropForeignKey('post_tags', 'fk_post_tags_tag');
        }

        $this->forge->dropTable('post_tags');
    }
}
