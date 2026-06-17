<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run()
    {
        $categories = [
            ['name' => 'CodeIgniter 4', 'slug' => 'codeigniter4'],
            ['name' => '웹 개발',        'slug' => 'web'],
            ['name' => '회고',           'slug' => 'retrospect'],
        ];

        // 반복 실행해도 슬러그 유니크 제약에 걸리지 않도록 이번 슬러그만 먼저 비운다.
        $slugs = array_column($categories, 'slug');
        $this->db->table('categories')->whereIn('slug', $slugs)->delete();

        $now = date('Y-m-d H:i:s');

        foreach ($categories as $category) {
            $this->db->table('categories')->insert([
                'name'       => $category['name'],
                'slug'       => $category['slug'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
