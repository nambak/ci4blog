<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAvatarToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'avatar' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'status_message',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'avatar');
    }
}
