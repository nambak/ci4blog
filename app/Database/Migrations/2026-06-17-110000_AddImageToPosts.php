<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddImageToPosts extends Migration
{
    public function up()
    {
        // 대표 이미지의 '저장된 파일명'만 보관한다(경로·도메인은 코드가 만든다).
        // 이미지는 선택이므로 null 허용.
        $this->forge->addColumn('posts', [
            'image' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'body',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('posts', 'image');
    }
}
