<?php

namespace Tests\Feature;

use App\Models\CategoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * CategoryModel 의 slug 자동생성 동작.
 *
 * - name 만 주면 slug 가 name 으로 자동 생성된다(한글 지원).
 * - 같은 이름이면 -2, -3 … 으로 유일하게 만든다.
 * - slug 를 직접 주면 그 값을 쓴다.
 */
final class CategoryModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    public function testSlugIsGeneratedFromName(): void
    {
        $model = model(CategoryModel::class);

        $id  = $model->insert(['name' => '개발 노트']);
        $cat = $model->find($model->getInsertID());

        $this->assertNotFalse($id);
        $this->assertSame('개발-노트', $cat->slug);
    }

    public function testDuplicateNameGetsUniqueSlug(): void
    {
        $model = model(CategoryModel::class);

        $model->insert(['name' => '회고']);
        $model->insert(['name' => '회고']);

        $slugs = array_map(static fn ($c) => $c->slug, $model->findAll());

        $this->assertContains('회고', $slugs);
        $this->assertContains('회고-2', $slugs);
    }

    public function testExplicitSlugIsKept(): void
    {
        $model = model(CategoryModel::class);

        $model->insert(['name' => '디자인', 'slug' => 'design']);
        $cat = $model->find($model->getInsertID());

        $this->assertSame('design', $cat->slug);
    }
}
