<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CategoryModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

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

    public function create(): RedirectResponse
    {
        $model = model(CategoryModel::class);

        // name, slug 만 받는다(slug 비면 모델이 name 으로 자동 생성).
        $data = $this->request->getPost(['name', 'slug']);

        if (! $model->insert($data)) {
            return redirect()->back()->withInput()->with('errors', $model->errors());
        }

        return redirect()->to('admin/categories')->with('message', '카테고리가 추가되었습니다.');
    }

    public function edit(int $id): string
    {
        $category = model(CategoryModel::class)->find($id);

        if ($category === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('admin/categories/edit', ['category' => $category]);
    }

    public function update(int $id): RedirectResponse
    {
        $model    = model(CategoryModel::class);
        $category = $model->find($id);

        if ($category === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->request->getPost(['name', 'slug']);

        // is_unique[...,{id}] 가 자기 자신을 제외하도록 id 를 넘긴다.
        // {id} 는 validate() 에 전달된 data 배열의 'id' 키로 채워진다(update()의 $id 인자로는 자동 주입되지 않음).
        // id 는 $allowedFields 밖이라 doProtectFields()가 SQL 반영 전에 제거한다.
        $data['id'] = $id;

        if (! $model->update($id, $data)) {
            return redirect()->back()->withInput()->with('errors', $model->errors());
        }

        return redirect()->to('admin/categories')->with('message', '카테고리를 수정했습니다.');
    }
}
