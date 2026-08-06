<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTagsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            // 태그는 짧다. 카테고리(100)보다 좁게 잡는다.
            'name'       => ['type' => 'VARCHAR', 'constraint' => 50],
            // 한글 slug 가 인코딩되면 길어질 수 있어 여유를 둔다.
            'slug'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        // slug 는 /tags/{slug} 의 식별자라 유일해야 한다.
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('tags');
    }

    public function down()
    {
        $this->forge->dropTable('tags');
    }
}
