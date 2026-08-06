<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddViewsToPosts extends Migration
{
    public function up()
    {
        // 누적 조회수. 기존 글은 DEFAULT 0 으로 자동 백필된다 — 과거 조회 기록이
        // 없으므로 이것이 정확한 시작값이다.
        //
        // ('after' 는 MySQL 전용이라 SQLite 는 무시한다 — AddStatusToPosts 와 동일.)
        //
        // 인덱스를 걸지 않는 이유: 지금은 표시만 하고 정렬·필터에 쓰지 않는다.
        // 인기글 정렬을 넣게 되면 그때 추가한다.
        $this->forge->addColumn('posts', [
            'views' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 0,
                'after'      => 'status',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('posts', 'views');
    }
}
