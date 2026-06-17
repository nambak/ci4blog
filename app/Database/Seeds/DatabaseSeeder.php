<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // 글이 카테고리를 참조하므로 카테고리를 먼저 채운다.
        $this->call('CategorySeeder');
        $this->call('PostSeeder');
    }
}
