<?php

namespace App\Controllers;

use App\Entities\Post;
use App\Models\CategoryModel;
use App\Models\CommentLikeModel;
use App\Models\CommentModel;
use App\Models\PostLikeModel;
use App\Models\PostModel;
use App\Models\TagModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Pager\Pager;

class Posts extends BaseController
{
    // 한 페이지에 보여 줄 글 수
    private const PER_PAGE = 10;

    // 댓글 더보기 한 번에 펼칠 최상위 댓글 수(#114).
    // PER_PAGE 와 값이 같더라도 의미가 다르므로 상수를 따로 둔다.
    private const COMMENTS_PER_PAGE = 20;

    /**
     * 글 목록. 카테고리 슬러그가 주어지면 그 카테고리 글만 거르고,
     * 검색어(q)가 주어지면 제목·본문을 like 로 찾는다.
     *
     * `posts` → 전체, `categories/{slug}` → 해당 카테고리, `?q=...` → 검색.
     */
    public function index(?string $categorySlug = null): string
    {
        // 공개 목록은 발행된 글만 보여 준다. 카테고리·검색 조건과 AND 로 묶인다.
        $model = model(PostModel::class)->published();

        // 없는 카테고리는 404. (필터가 빈 목록으로 조용히 떨어지지 않게)
        // 숨김 카테고리(is_visible = 0)도 공개 화면에서는 없는 것과 같게 다룬다(#67) —
        // 403 이 아니라 404 인 이유는 아래 show() 의 주석과 같다.
        $activeCategory = null;
        if ($categorySlug !== null) {
            $activeCategory = model(CategoryModel::class)
                ->where('slug', $categorySlug)
                ->where('is_visible', 1)
                ->first();
            if ($activeCategory === null) {
                throw PageNotFoundException::forPageNotFound();
            }
            $model->where('category_id', $activeCategory->id);
        }

        // 검색어가 있으면 제목 OR 본문에서 찾는다. 다른 조건(카테고리)과 AND 로 묶이도록
        // like 묶음을 groupStart/End 로 감싼다.
        $search = trim((string) $this->request->getGet('q'));
        if ($search !== '') {
            $model->groupStart()
                ->like('title', $search)
                ->orLike('body', $search)
                ->groupEnd();
        }

        $posts = $model
            ->orderBy('created_at', 'DESC')
            ->paginate(self::PER_PAGE);

        $this->guardPageRange($model->pager);

        return view('posts/index', [
            'posts'          => $posts,
            'pager'          => $model->pager,
            'categories'     => model(CategoryModel::class)->menu(),
            'activeCategory' => $activeCategory,
            // 전체 글 색인(#GSC). 거르지 않은 목록 1페이지에서만 싣는다 —
            // 자세한 이유는 archiveIndex() 주석에.
            'archive'        => $this->archiveIndex($activeCategory, $search, $posts),
            'search'         => $search,
            // 태그 목록(byTag)과 같은 뷰를 쓰므로 이 값을 명시적으로 넘긴다(#114).
            'activeTag'      => null,
            // 같은 메서드가 /posts 와 /categories/{slug} 를 모두 처리한다(#113).
            'meta'           => [
                'title'       => $activeCategory !== null ? $activeCategory->name . ' 글' : '글 목록',
                'description' => $this->listDescription($activeCategory, $search, $model->pager->getTotal()),
                'jsonld'      => $this->listBreadcrumb($activeCategory),
            ],
        ]);
    }

    /**
     * 태그별 글 목록. (#114)
     *
     * index() 와 같은 뷰를 쓰되 필터가 카테고리 대신 태그다.
     * published() 스코프를 그대로 태워 초안·숨김 카테고리 글이 새어 나가지 않게 한다.
     */
    public function byTag(string $tagSlug): string
    {
        $tag = model(TagModel::class)->findBySlug($tagSlug);

        // 없는 태그는 404. 빈 목록으로 조용히 떨어지면 오타를 알아채지 못한다.
        if ($tag === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $model = model(PostModel::class)->published();

        // 연결 테이블로 좁힌다. join 이 아니라 id 목록을 뽑아 whereIn 을 쓰는 이유는
        // published() 가 이미 서브쿼리를 쓰고 있어 join 을 섞으면 groupBy 가 필요해지기 때문이다.
        $postIds = array_column(
            db_connect()->table('post_tags')->select('post_id')->where('tag_id', $tag->id)->get()->getResultArray(),
            'post_id'
        );

        // 태그는 있는데 걸린 글이 하나도 없는 경우다. whereIn([]) 은 드라이버에 따라
        // 동작이 갈리므로 존재할 수 없는 id 로 빈 목록을 만든다.
        if ($postIds === []) {
            $postIds = [0];
        }

        $posts = $model
            ->whereIn('posts.id', $postIds)
            ->orderBy('created_at', 'DESC')
            ->paginate(self::PER_PAGE);

        $this->guardPageRange($model->pager);

        return view('posts/index', [
            'posts'          => $posts,
            'pager'          => $model->pager,
            'categories'     => model(CategoryModel::class)->menu(),
            'activeCategory' => null,
            'activeTag'      => $tag,
            'search'         => '',
            // 태그로 좁힌 화면에는 전체 색인을 싣지 않는다. 같은 뷰를 쓰므로 키는 넘긴다.
            'archive'        => [],
            'meta'           => [
                'title'       => $tag->name . ' 태그 글',
                'description' => sprintf(
                    "'%s' 태그가 붙은 글 %d편입니다. %s",
                    $tag->name,
                    $model->pager->getTotal(),
                    config('Blog')->description
                ),
            ],
        ]);
    }

    public function show(string $slug): string
    {
        $post = model(PostModel::class)
            ->where('slug', $slug)
            ->first();

        // 없는 글은 404 로 응답한다.
        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->assertViewable($post);

        // 조회수(#114). 세션당 한 번만 센다 — 새로고침 연타로 부풀지 않게.
        //
        // 발행된 글만 센다: post_viewable() 은 작성자·관리자에게 초안도 열어 주지만
        // 그것은 미리보기지 조회가 아니다.
        //
        // 🔴 모델 update() 를 쓰면 안 된다. views 가 $allowedFields 에 없어 무시될 뿐
        // 아니라, $useTimestamps 가 updated_at 을 지금 시각으로 덮어 sitemap 의
        // <lastmod> 와 RSS 가 통째로 오염된다(#124). 쿼리 빌더로 DB 가 직접 더하게 한다
        // — 읽고-더하고-쓰면 동시 요청 둘이 같은 값을 읽어 하나가 사라진다(#88 과 같은 레이스).
        if ($post->isPublished()) {
            $seen = session()->get('viewed_posts') ?? [];

            if (! in_array((int) $post->id, $seen, true)) {
                db_connect()->table('posts')
                    ->where('id', $post->id)
                    ->set('views', 'views + 1', false)
                    ->update();

                $seen[] = (int) $post->id;
                session()->set('viewed_posts', $seen);
            }
        }

        // 댓글은 최상위 기준으로 나눠 가져온다(#114). 답글은 부모를 따라오므로
        // 경계에서 트리가 끊기지 않는다.
        $commentModel  = model(CommentModel::class);
        $topLevelCount = $commentModel->countTopLevelForPost((int) $post->id);

        // 페이지 번호로 받는다 — 개수를 직접 받으면 ?c=9999999 로 거대한 LIMIT 을 걸 수 있다.
        //
        // max(1, ...) 이 실질적인 방어다. (int) 'abc' 는 0, (int) '-1' 은 -1 이라
        // 이것이 없으면 limit 이 0 이나 음수가 되어 **전체가 로드된다**(페이지네이션이 무력해진다).
        //
        // min(..., $maxCommentPage) 은 화면 결과를 바꾸지 않는다 — 어차피 있는 행만 나온다.
        // 의미 없는 거대 LIMIT 을 DB 에 보내지 않으려고 둔다(뮤테이션으로 확인한 사실이다).
        $maxCommentPage = max(1, (int) ceil($topLevelCount / self::COMMENTS_PER_PAGE));
        $commentPage    = min(max(1, (int) $this->request->getGet('cp')), $maxCommentPage);
        $commentLimit   = $commentPage * self::COMMENTS_PER_PAGE;

        $comments         = $commentModel->forPost((int) $post->id, $commentLimit);
        $commentCount     = $commentModel->countForPost((int) $post->id);
        $hasOlderComments = $topLevelCount > $commentLimit;

        // 바이라인(작성자 아바타 행)용 작성자명. 홈 히어로와 같은 방식으로
        // users 테이블에서 username 만 직접 읽는다(엔티티 의존 없이).
        $authorName   = null;
        $authorAvatar = null;
        if ($post->user_id !== null) {
            $row          = db_connect()->table('users')->select('username, avatar')->where('id', $post->user_id)->get()->getRow();
            $authorName   = $row->username ?? null;
            $authorAvatar = $row->avatar ?? null;
        }

        // 제목 위 카테고리 칩. 없는 글(미분류)은 null.
        $category = $post->category_id !== null
            ? model(CategoryModel::class)->find($post->category_id)
            : null;

        // 이어 읽기(#114): 발행일 순서로 인접한 글. published() 스코프를 그대로
        // 태우므로 초안과 숨김 카테고리 글은 이 목록에서 자동으로 빠진다.
        $postModel = model(PostModel::class);
        $previous  = $postModel->previousOf($post);
        $next      = $postModel->nextOf($post);

        // 카드에 붙일 카테고리명. 최대 2건이라 whereIn 한 번으로 끝낸다.
        $neighborCategoryIds = array_values(array_filter(
            [$previous?->category_id, $next?->category_id],
            static fn ($id): bool => $id !== null
        ));

        $neighborCategories = [];
        if ($neighborCategoryIds !== []) {
            foreach (model(CategoryModel::class)->whereIn('id', $neighborCategoryIds)->findAll() as $neighborCategory) {
                $neighborCategories[(int) $neighborCategory->id] = $neighborCategory;
            }
        }

        // 좋아요(#64): 카운트는 상세에만 둔다. 목록까지 세면 글마다 쿼리가 돌아 N+1 이 된다.
        $likes     = model(PostLikeModel::class);
        $likeCount = $likes->countForPost((int) $post->id);
        $liked     = auth()->loggedIn() && $likes->hasLiked((int) $post->id, (int) auth()->id());

        // 댓글 좋아요(#100): 목록의 모든 댓글에 카운트가 붙으므로 댓글마다 조회하면
        // 그대로 N+1 이다. 최상위와 답글의 id 를 한 배열로 모아 두 번의 쿼리로 끝낸다.
        $commentIds = [];
        foreach ($comments as $comment) {
            $commentIds[] = (int) $comment->id;
            foreach ($comment->replies as $reply) {
                $commentIds[] = (int) $reply->id;
            }
        }

        $commentLikes = model(CommentLikeModel::class);
        $likeCounts   = $commentLikes->countsByComment($commentIds);
        // 비로그인은 누른 것이 있을 수 없으므로 쿼리 자체를 돌리지 않는다.
        $likedIds = auth()->loggedIn()
            ? $commentLikes->likedByUser($commentIds, (int) auth()->id())
            : [];

        return view('posts/show', [
            'post'         => $post,
            'likeCount'    => $likeCount,
            'liked'        => $liked,
            'comments'     => $comments,
            // 댓글 더보기(#114). 뷰가 총 개수를 몰라도 되게 컨트롤러가 판단해 넘긴다.
            'commentPage'      => $commentPage,
            'hasOlderComments' => $hasOlderComments,
            'commentCount' => $commentCount,
            'likeCounts'   => $likeCounts,
            'likedIds'     => $likedIds,
            'authorName'   => $authorName,
            'authorAvatar' => $authorAvatar,
            'category'     => $category,
            // 태그 칩(#114). 없으면 빈 배열이라 뷰가 영역을 통째로 생략한다.
            'tags'         => model(TagModel::class)->forPost((int) $post->id),
            // 이어 읽기(#114). 이웃이 없으면 null 이고, 부분 뷰가 알아서 섹션을 생략한다.
            'previous'           => $previous,
            'next'               => $next,
            'neighborCategories' => $neighborCategories,
            // SNS 미리보기·검색 스니펫용(#113). partial 이 이스케이프하므로 원문을 넘긴다.
            'meta'         => [
                'type'        => 'article',
                'title'       => $post->title,
                'description' => $post->getExcerpt(155),
                // 구조화 데이터(#GSC). 글의 정체와 발행·수정 시각을 선언한다.
                'jsonld'      => $this->articleJsonLd($post, $authorName),
                // 이미지가 없으면 키 자체를 넣지 않는다 — partial 이 태그를 생략한다.
                ...($post->image !== null && $post->image !== ''
                    ? ['image' => site_url('uploads/' . $post->image)]
                    : []),
            ],
        ]);
    }

    /**
     * 글 작성 폼을 보여 준다. (세션 필터로 로그인 사용자만 접근)
     *
     * 폼 뷰(posts/create)는 ep13 에서 추가한다.
     */
    public function new(): string
    {
        return view('posts/create', [
            // 폼은 숨김 카테고리도 고를 수 있어야 한다 — forForm() 주석 참고(#67).
            'categories' => model(CategoryModel::class)->forForm(),
            // 레이아웃을 쓰는 화면은 meta 를 명시적으로 넘긴다 — 넘기지 않으면
            // 뷰 스코프에 남은 앞 렌더의 $meta 가 `?? []` 를 통과한다(#113).
            'meta' => ['title' => '새 글 작성'],
        ]);
    }

    /**
     * 폼에서 넘어온 글을 검증하고 저장한다.
     */
    public function create(): RedirectResponse
    {
        $model = model(PostModel::class);

        // allowedFields 에 든 값만 추려서 받는다.
        $data = $this->request->getPost(['title', 'body', 'category_id', 'status']);

        // 카테고리는 선택 사항. 안 고르면 빈 문자열로 오므로 null 로 정규화한다.
        $data['category_id'] = $this->normalizeCategoryId($data['category_id'] ?? null);

        // 상태는 폼 셀렉트에서 온다. 없거나 이상한 값이면 발행으로 본다(기존 동작 유지).
        $data['status'] = $this->normalizeStatus($data['status'] ?? null);

        // 현재 로그인한 사용자를 작성자로 묶는다.
        $data['user_id'] = auth()->id();

        // 대표 이미지(선택). 검증 실패면 false, 미업로드면 null, 성공이면 파일명.
        $image = $this->saveUploadedImage();
        if ($image === false) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        if ($image !== null) {
            $data['image'] = $image;
        }

        // slug 는 PostModel 의 beforeInsert 콜백이 제목으로 자동 생성한다.

        // 검증 실패 시: 방금 옮긴 이미지 파일을 되돌리고(고아 방지) 폼으로 돌아간다.
        if (! $model->insert($data)) {
            if ($image !== null) {
                $this->deleteImageFiles($image);
            }

            return redirect()->back()
                ->withInput()
                ->with('errors', $model->errors());
        }

        // 태그(#114). 저장이 성공한 뒤에 붙인다 — 실패했다면 붙일 글이 없다.
        $tagModel = model(TagModel::class);
        $tagModel->syncForPost(
            (int) $model->getInsertID(),
            $tagModel->parseNames((string) $this->request->getPost('tags'))
        );

        // 저장 성공 시: 목록으로 이동하며 플래시 메시지를 남긴다.
        return redirect()->to('posts')->with('message', '글이 등록되었습니다.');
    }

    /**
     * 글 수정 폼을 보여 준다. 기존 값을 폼에 채운다.
     */
    public function edit(int $id): string|ResponseInterface
    {
        $post = model(PostModel::class)->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 작성자 본인 또는 관리자만 수정할 수 있다.
        if (! $this->canModify($post)) {
            return $this->response->setStatusCode(403, '수정 권한이 없습니다.');
        }

        return view('posts/edit', [
            'post' => $post,
            // 이 글이 숨김 카테고리에 속해 있어도 목록에 있어야 선택이 유지된다(#67).
            'categories' => model(CategoryModel::class)->forForm(),
            // 태그 입력을 현재 값으로 채우기 위해 넘긴다(#114). 빈 칸으로 두면
            // 사용자가 그대로 저장했을 때 태그가 통째로 사라진다.
            'tags' => model(TagModel::class)->forPost($id),
            // 레이아웃을 쓰는 화면은 meta 를 명시적으로 넘긴다 — 넘기지 않으면
            // 뷰 스코프에 남은 앞 렌더의 $meta 가 `?? []` 를 통과한다(#113).
            'meta' => ['title' => '글 수정'],
        ]);
    }

    /**
     * 수정된 값을 검증하고 저장한다.
     */
    public function update(int $id): RedirectResponse|ResponseInterface
    {
        $model = model(PostModel::class);
        $post  = $model->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 작성자 본인 또는 관리자만 수정할 수 있다.
        if (! $this->canModify($post)) {
            return $this->response->setStatusCode(403, '수정 권한이 없습니다.');
        }

        $data = $this->request->getPost(['title', 'body', 'category_id', 'status']);

        // 카테고리는 선택 사항. 안 고르면 빈 문자열로 오므로 null 로 정규화한다.
        $data['category_id'] = $this->normalizeCategoryId($data['category_id'] ?? null);

        // 상태는 폼 셀렉트에서 온다. 없거나 이상한 값이면 발행으로 본다(기존 동작 유지).
        $data['status'] = $this->normalizeStatus($data['status'] ?? null);

        // 새 대표 이미지가 올라오면 교체한다. 단 기존 파일은 DB 반영이
        // 성공한 뒤에 지운다(실패 시 기존 이미지 참조가 깨지지 않도록).
        $image    = $this->saveUploadedImage();
        $oldImage = $post->image;
        if ($image === false) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        if ($image !== null) {
            $data['image'] = $image;
        }

        // 검증 실패 시: 방금 옮긴 새 파일을 되돌리고 입력값을 들고 폼으로 돌아간다.
        if (! $model->update($id, $data)) {
            if ($image !== null) {
                $this->deleteImageFiles($image);
            }

            return redirect()->back()
                ->withInput()
                ->with('errors', $model->errors());
        }

        // 반영 성공 후에야 기존 이미지를 정리한다.
        if ($image !== null) {
            $this->deleteImageFiles($oldImage);
        }

        // 태그는 전체 교체다(#114). 폼이 현재 태그를 그대로 싣고 오므로,
        // 빠진 것은 연결이 끊기고 새로 적은 것은 붙는다.
        $tagModel = model(TagModel::class);
        $tagModel->syncForPost($id, $tagModel->parseNames((string) $this->request->getPost('tags')));

        // 수정 성공 시: 해당 글 상세로 이동하며 플래시 메시지를 남긴다.
        // $post 는 수정 **전** 에 읽은 엔티티다. 저장 과정에서 slug 이 달라지면 그 옛 주소는
        // 이미 없으므로 곧장 404 가 된다 — 이슈 #152 가 실제로 그 증상이었다(그때는 제목을
        // 고치면 콜백이 slug 을 다시 만들었다). 지금은 slug 이 보존되지만, 저장된 값을 다시
        // 읽어 그 구조 자체를 없앤다.
        $saved = $model->find($id);

        return redirect()->to('posts/' . ($saved->slug ?? $post->slug))
            ->with('message', '글이 수정되었습니다.');
    }

    /**
     * 폼에서 넘어온 category_id 를 저장용 값으로 정규화한다.
     * 미선택(빈 문자열/공백)은 null, 그 외에는 정수로 돌려준다.
     * 실존 여부 검증은 PostModel 의 is_not_unique 규칙이 맡는다.
     */
    private function normalizeCategoryId(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * 폼에서 넘어온 status 를 저장용 값으로 정규화한다.
     * 미지정·허용 값 밖이면 published(기존 동작)로 떨어뜨린다.
     */
    private function normalizeStatus(mixed $value): string
    {
        // status[] 처럼 배열로 조작해 오면 (string) 캐스팅에서 경고가 나므로,
        // 문자열이 아닌 값은 곧장 빈 문자열로 취급해 published 로 떨어뜨린다.
        $value = is_string($value) ? $value : '';

        return in_array($value, Post::STATUSES, true) ? $value : Post::STATUS_PUBLISHED;
    }

    /**
     * 글 상세의 BlogPosting. (#GSC 색인)
     *
     * dateModified 를 datePublished 와 **다른 값**에서 가져오는 것이 핵심이다.
     * 둘 다 created_at 을 쓰면 글을 고쳐도 "안 바뀐 글" 이라고 선언하게 되는데,
     * 크롤 후 색인이 거부된 글을 다시 보게 하려는 목적과 정반대다.
     *
     * URL 은 post_url() 로 만든다. 한글 slug 를 site_url() 에 넘기면 macOS 에서
     * 바이트가 뭉개진다([[ci4blog-siteurl-macos-bug]]). 이미지 파일명은 난수 ASCII 라
     * 그 경로에는 해당 사항이 없다.
     *
     * @return array<string, mixed>
     */
    private function articleJsonLd(Post $post, ?string $authorName): array
    {
        $data = [
            '@context'         => 'https://schema.org',
            '@type'            => 'BlogPosting',
            'headline'         => $post->title,
            'description'      => $post->getExcerpt(155),
            'mainEntityOfPage' => post_url($post->slug),
            'author'           => [
                '@type' => 'Person',
                'name'  => $authorName ?? config('Blog')->title,
            ],
        ];

        if ($post->created_at !== null) {
            $data['datePublished'] = $post->created_at->format('c');
        }

        if ($post->updated_at !== null) {
            $data['dateModified'] = $post->updated_at->format('c');
        }

        if ($post->image !== null && $post->image !== '') {
            $data['image'] = site_url('uploads/' . $post->image);
        }

        return $data;
    }

    /**
     * 목록·카테고리 화면의 BreadcrumbList. (#GSC 색인)
     *
     * 검색엔진에 이 화면이 계층 어디에 있는지 알려 준다. 목록성 페이지가 크롤 후
     * 색인 거부된 상태라, 화면의 정체를 추측이 아니라 선언으로 주려는 것이다.
     *
     * 검색 결과에는 붙이지 않는다 — ?q= 는 계층상의 자리가 아니라 일시적인 질의다.
     *
     * URL 은 category_url()·absolute_url() 로 만든다. site_url() 에 한글 경로를
     * 넘기면 macOS 에서 바이트가 뭉개진다([[ci4blog-siteurl-macos-bug]]).
     *
     * @return array<string, mixed>
     */
    private function listBreadcrumb(?object $activeCategory): array
    {
        $items = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => '홈', 'item' => absolute_url('')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '글 목록', 'item' => absolute_url('posts')],
        ];

        if ($activeCategory !== null) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => 3,
                'name'     => $activeCategory->name,
                'item'     => category_url($activeCategory->slug),
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * 목록 화면의 meta description. (#GSC 색인)
     *
     * 예전에는 사이트 기본 설명이 그대로 나가서 /, /posts, /about, 카테고리 페이지의
     * description 이 전부 같은 문장이었다. 같은 설명을 단 페이지가 여럿이면 검색엔진이
     * "따로 색인할 가치가 없다" 는 쪽으로 기운다 — 실제로 그 넷 중 셋이 크롤 후 색인
     * 거부 상태였다.
     *
     * 그래서 화면이 실제로 무엇을 담고 있는지를 문장에 넣는다. 개수를 함께 쓰는 것은
     * 장식이 아니라, 글이 늘면 설명도 따라 바뀌어 내용과 어긋나지 않기 때문이다.
     * 뒤에 사이트 설명을 붙이는 것은 문맥을 주기 위한 것이고, 앞부분이 달라 페이지끼리
     * 구별된다.
     */
    private function listDescription(?object $activeCategory, string $search, int $total): string
    {
        $site = config('Blog')->description;

        if ($search !== '') {
            return sprintf("'%s' 검색 결과 %d편입니다. %s", $search, $total, $site);
        }

        if ($activeCategory !== null) {
            return sprintf("'%s' 카테고리에 담긴 글 %d편입니다. %s", $activeCategory->name, $total, $site);
        }

        return sprintf('지금까지 쓴 글 %d편의 전체 목록입니다. 최신 글부터 볼 수 있습니다. %s', $total, $site);
    }

    /**
     * 목록 하단에 실을 전체 글 색인. 실을 자리가 아니면 빈 배열. (#GSC 색인)
     *
     * 거르지 않은 목록의 **1페이지에서만** 싣는다. 세 가지를 다 만족해야 한다.
     *
     * - 카테고리로 좁히지 않았을 것 · 검색하지 않았을 것: 좁힌 화면에 전체 목록을
     *   붙이면 화면이 스스로를 부정한다.
     * - 첫 페이지일 것: 페이지마다 반복하면 같은 링크 묶음이 페이지 수만큼 늘어나
     *   중복이 된다. canonical 로 방금 정리한 것을 도로 어지럽히는 셈이다.
     *
     * 공유 인스턴스를 쓰지 않는 이유가 있다. model() 이 돌려주는 것은 같은 객체라
     * 앞서 paginate() 를 태운 빌더 상태가 섞일 수 있다. 여기서는 조건이 전혀 다른
     * 질의를 하므로 새 인스턴스로 시작한다.
     *
     * published() 스코프는 반드시 태운다 — 이 목록은 공개 화면이고, 빠뜨리면
     * 초안 제목이 통째로 실린다.
     *
     * @return list<Post>
     */
    private function archiveIndex(?object $activeCategory, string $search, array $shown = []): array
    {
        if ($activeCategory !== null || $search !== '') {
            return [];
        }

        if ((int) ($this->request->getGet('page') ?? 1) > 1) {
            return [];
        }

        // 제목·slug·날짜만 있으면 되는 화면이라 컬럼을 좁힌다. 본문까지 끌어오면
        // 마크다운 원문 전체가 31번 메모리에 올라온다.
        $query = model(PostModel::class, false)
            ->published()
            ->select('id, title, slug, created_at')
            ->orderBy('created_at', 'DESC');

        // 카드 목록에 이미 보인 글은 뺀다(#148). 색인이 하는 일은 **카드에 없는 글**까지
        // 링크를 잇는 것이라, 카드에 있는 글을 또 나열하면 같은 화면에 같은 글이 두 번
        // 보일 뿐이다. 빼도 도달성은 그대로다 — 그 글들은 카드가 이미 링크하고 있다.
        $shownIds = array_values(array_filter(array_map(
            static fn ($post) => (int) ($post->id ?? 0),
            $shown
        )));

        if ($shownIds !== []) {
            $query->whereNotIn('id', $shownIds);
        }

        return $query->findAll();
    }

    /**
     * 범위를 벗어난 ?page=N 을 404 로 돌린다.
     *
     * CI4 의 Pager 는 범위를 넘긴 page 를 **마지막 페이지로 클램프**한다
     * (Pager::store() — `$page > $pageCount ? $pageCount : $page`). 그래서
     * ?page=999 가 빈 목록이 아니라 마지막 페이지와 **바이트 단위로 같은 응답**을
     * 200 으로 돌려준다. 무한히 많은 URL 이 같은 내용을 갖는 셈이다.
     *
     * 자기참조 canonical(#GSC)과 함께 두면 이게 특히 나빠진다. 각 ?page=N 이
     * 스스로를 정본이라고 선언하는 순간, 무한한 중복이 색인 후보가 된다.
     * 그래서 이 가드는 canonical 변경과 반드시 짝으로 가야 한다.
     *
     * 판정을 "결과가 비었다" 로 하지 않는 이유가 두 가지다. 하나는 클램프 때문에
     * 애초에 비지 않는다는 것이고, 다른 하나는 글이 하나도 없는 사이트의 첫 페이지가
     * 404 가 되어 목록이 통째로 사라진다는 것이다 — 없는 것은 페이지가 아니라 글이다.
     */
    private function guardPageRange(Pager $pager): void
    {
        $raw = $this->request->getGet('page');

        // 아래쪽도 막아야 한다. Pager 에는 하한 클램프가 따로 있어서
        // (Pager.php: `$page < 1 ? 1 : $page`) ?page=0 · ?page=-1 · ?page=abc 가
        // 전부 1페이지를 200 으로 돌려준다. canonical 이 /posts 를 가리키므로 색인
        // 위험은 위쪽 경우보다 낮지만, 200 을 주는 쓰레기 URL 이 무한히 생기고
        // 무엇보다 상한과 동작이 어긋난다.
        //
        // 파라미터가 아예 없는 것과 값이 이상한 것은 다르게 다룬다. 없으면 첫
        // 페이지지만, 있는데 1 미만이면 잘못 만들어진 요청이다.
        if ($raw !== null && (int) $raw < 1) {
            throw PageNotFoundException::forPageNotFound();
        }

        $requested = (int) ($raw ?? 1);

        if ($requested > 1 && $requested > $pager->getPageCount()) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * 업로드된 대표 이미지를 검증·저장하고 저장 파일명을 돌려준다.
     *
     * @return string|false|null 저장 파일명 / 검증 실패(false) / 업로드 없음(null)
     */
    private function saveUploadedImage(): string|false|null
    {
        $file = $this->request->getFile('image');

        // 파일을 고르지 않았으면 이미지 없이 진행한다.
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        // 이미지 형식·용량 검증(2MB 이하).
        if (! $this->validate([
            'image' => 'is_image[image]|mime_in[image,image/jpg,image/jpeg,image/png,image/webp]|max_size[image,2048]',
        ])) {
            return false;
        }

        // 저장은 UploadStorage 가 맡는다(#95). UploadedFile::move() 가 내부에서 쓰는
        // is_uploaded_file()·move_uploaded_file() 은 실제 HTTP 업로드에서만 참이라
        // 이 한 겹이 없으면 아래 썸네일 생성까지 통째로 테스트에서 빠진다.
        $dir  = WRITEPATH . 'uploads';
        $name = service('uploadStorage')->store($file);

        // 목록용 썸네일(400x250 크롭). 원본은 상세에서 사용.
        service('image')
            ->withFile($dir . '/' . $name)
            ->fit(400, 250, 'center')
            ->save($dir . '/thumb_' . $name);

        return $name;
    }

    /**
     * 글에 딸린 이미지 원본과 썸네일을 파일시스템에서 지운다.
     */
    private function deleteImageFiles(?string $name): void
    {
        if ($name === null || $name === '') {
            return;
        }

        foreach ([$name, 'thumb_' . $name] as $f) {
            $path = WRITEPATH . 'uploads/' . $f;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * 글을 삭제한다. 작성자 본인 또는 관리자만 가능하다.
     */
    public function delete(int $id): ResponseInterface|RedirectResponse
    {
        $model = model(PostModel::class);
        $post  = $model->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 컨트롤러 가드: 권한이 없으면 403 으로 막는다.
        if (! $this->canModify($post)) {
            return $this->response->setStatusCode(403, '삭제 권한이 없습니다.');
        }

        $image = $post->image;

        // 삭제 결과를 확인하고 나서 파일을 건드린다. 실패했는데 파일만 지우면
        // 글은 남은 채 이미지 참조만 깨진다.
        if (! $model->delete($id)) {
            return redirect()->back()->with('errors', ['글을 삭제하지 못했습니다.']);
        }

        // 행을 지운 뒤에 파일을 정리한다(update() 와 같은 순서). 소프트 삭제가 아니라
        // 되돌릴 일이 없으므로, 안 지우면 원본과 썸네일이 디스크에 영원히 남는다.
        $this->deleteImageFiles($image);

        return redirect()->to('posts')->with('message', '글이 삭제되었습니다.');
    }

    /**
     * 좋아요 토글(#64). 세션 필터 그룹 안이라 로그인 사용자만 들어온다.
     */
    public function like(int $id): RedirectResponse
    {
        $post = model(PostModel::class)->find($id);

        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 상세(show)와 같은 가드를 그대로 쓴다 — 상세가 404 인 글에 좋아요만 열려 있으면
        // 응답 차이로 글의 존재가 샌다.
        $this->assertViewable($post);

        $likes  = model(PostLikeModel::class);
        $userId = (int) auth()->id();

        // 검사-후-삽입(hasLiked 로 분기)은 동시 요청 두 개가 모두 통과하는 레이스가 있다.
        // 그래서 #88 처럼 그 구조를 아예 두지 않는다 — 먼저 넣어 보고, 유니크 키
        // (post_id, user_id) 위반으로 실패하면 그게 곧 "이미 눌렀다"는 뜻이라 취소로 간다.
        // 위반은 DBDebug=true 면 예외로, false 면 insert=false 로 온다.
        $dbException = null;

        try {
            $inserted = $likes->insert(['post_id' => $id, 'user_id' => $userId]);
        } catch (DatabaseException $e) {
            $inserted    = false;
            $dbException = $e;
        }

        if (! $inserted) {
            // 실패 원인은 예외에서 읽는다. 연결의 error() 는 이 시점에 이미 비어 있다
            // ({code:0, message:"not an error"}) — #107 조사에서 확인했다.
            $isDuplicate = $dbException !== null
                && is_duplicate_key_error($dbException->getCode(), $dbException->getMessage());

            // 유니크 위반 = 이미 눌러 뒀다는 뜻이므로 취소로 간다(토글).
            if ($isDuplicate) {
                $likes->where('post_id', $id)->where('user_id', $userId)->delete();
            } else {
                // 중복이 아닌 실패다. 기존 행은 건드리지 않는다 — 원인을 구분하지 않고
                // "행이 있으면 취소" 하면, 삽입이 커밋된 뒤 타임아웃 같은 실패에서 방금
                // 만든 행을 지워 누른 좋아요가 사라진다(#107).
                // 재던지지도 않는다. 같은 사용자의 동시 취소 같은 정상 상황에도 500 이
                // 나가므로(76ea329) 로그만 남기고 화면은 정상 응답한다. 진짜 오류라면
                // 카운트가 그대로여서 사용자에게도 "안 눌렸다"가 보인다.
                if ($dbException !== null) {
                    log_message('error', '좋아요 삽입 실패 (post {post}, user {user}): {message}', [
                        'post'    => $id,
                        'user'    => $userId,
                        'message' => $dbException->getMessage(),
                    ]);
                } elseif ($likes->errors() !== []) {
                    return redirect()->back()->with('errors', $likes->errors());
                } elseif ($likes->hasLiked($id, $userId)) {
                    // DBDebug=false 면 예외가 없어 원인을 알 수 없다. 이때만 예전처럼 되묻는다.
                    $likes->where('post_id', $id)->where('user_id', $userId)->delete();
                }
            }
        }

        return redirect()->to('posts/' . $post->slug . '#like');
    }

    /**
     * 이 글을 지금 사용자에게 보여 줘도 되는지 확인하고, 아니면 404 를 던진다.
     *
     * 상세(show)와 좋아요(like)가 함께 쓴다. 두 곳에 복붙해 두면 가드가 또 늘 때
     * 한쪽만 고치는 사고가 난다 — 댓글 신고(#79)가 정확히 그 사고였다.
     *
     * 403 이 아니라 404 를 주는 건 의도적이다 — 403 은 그 슬러그의 글이
     * 존재한다는 사실 자체를 흘린다.
     */
    private function assertViewable(Post $post): void
    {
        // 판정은 post_viewable() 헬퍼가 한다 — 댓글 작성·신고·좋아요가 같은 규칙을
        // 써야 해서 한 곳으로 모았다(#100).
        if (! post_viewable($post)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * 현재 사용자가 이 글을 수정/삭제할 수 있는지 판단한다.
     * 공통 규칙(작성자 본인 또는 admin)은 acl 헬퍼로 모았다.
     */
    private function canModify(Post $post): bool
    {
        return is_owner_or_admin($post->user_id);
    }
}
