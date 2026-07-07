<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CategoryModel;
use App\Models\PostModel;

/**
 * 관리자 카테고리 관리.
 *
 * 라우트 그룹의 Shield `group:admin,superadmin` 필터가 접근을 막으므로
 * 이 컨트롤러는 이미 admin/superadmin 인 요청만 처리한다.
 */
class Categories extends BaseController
{
    public function index(): string
    {
        $search = (string) ($this->request->getGet('q') ?? '');

        $categories   = model(CategoryModel::class)->withPostCounts($search !== '' ? $search : null);
        $uncategorized = model(PostModel::class)->where('category_id', null)->countAllResults();

        return view('admin/categories/index', [
            'categories'    => $categories,
            'uncategorized' => $uncategorized,
            'search'        => $search,
        ]);
    }
}
