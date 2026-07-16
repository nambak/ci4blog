<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCommentReportsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'comment_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'reporter_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'reason'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'pending'],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        // 한 사용자는 한 댓글을 한 번만 신고한다.
        $this->forge->addUniqueKey(['comment_id', 'reporter_user_id']);
        // 신고 탭이 status 로 거르고, 뱃지·정리가 comment_id 로 묶는다.
        $this->forge->addKey('status');
        $this->forge->addKey('comment_id');
        $this->forge->createTable('comment_reports');

        // 댓글을 지우면 신고도 함께 사라진다. SQLite 는 테이블 재생성이 필요해 MySQL 에서만 건다.
        // (comments 마이그레이션과 같은 방식: 이미 만든 테이블에 addForeignKey+processIndexes.)
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->addForeignKey('comment_id', 'comments', 'id', '', 'CASCADE', 'fk_reports_comment');
            $this->forge->processIndexes('comment_reports');
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            $this->forge->dropForeignKey('comment_reports', 'fk_reports_comment');
        }

        $this->forge->dropTable('comment_reports');
    }
}
