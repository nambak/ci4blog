<?php

namespace App\Controllers;

use App\Models\PostModel;

class Posts extends BaseController
{
    public function index(): string
    {
        $posts = model(PostModel::class)
            ->orderBy('created_at', 'DESC')
            ->findAll();

        return view('posts/index', [
            'posts' => $posts,
        ]);
    }
}
