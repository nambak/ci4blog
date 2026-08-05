<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * 공개 페이지의 메타태그(#113).
 *
 * SNS 공유 미리보기와 검색 스니펫이 이 태그들에 달려 있다. 화면에 보이지 않아
 * 조용히 빠져도 아무도 모르는 종류라 테스트로 붙잡는다.
 *
 * @internal
 */
final class MetaTagsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        // View::include() 는 $saveData=true 라 뷰 데이터가 인스턴스에 쌓이고,
        // renderer 는 shared 서비스다. 리셋하지 않으면 앞 테스트의 meta 가
        // 남아 "기본값으로 떨어진다"는 검증이 거짓 통과한다(실측).
        Services::resetSingle('renderer');
    }

    /** 한글 단언은 엔티티 디코드를 거쳐야 CI(ubuntu)에서도 통과한다. */
    private function decodedBody(TestResponse $result): string
    {
        return html_entity_decode($result->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** `<meta ... content="X">` 에서 X 를 뽑는다. 없으면 null. */
    private function metaContent(string $html, string $attr, string $name): ?string
    {
        $pattern = sprintf(
            '/<meta\s+%s="%s"\s+content="([^"]*)"/',
            preg_quote($attr, '/'),
            preg_quote($name, '/')
        );

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    public function testHomeUsesSiteDefaults(): void
    {
        $html = $this->decodedBody($this->call('GET', '/'));

        $this->assertSame(config('Blog')->title, $this->metaContent($html, 'property', 'og:title'));
        $this->assertSame(config('Blog')->description, $this->metaContent($html, 'property', 'og:description'));
        $this->assertSame(config('Blog')->description, $this->metaContent($html, 'name', 'description'));
        $this->assertSame('website', $this->metaContent($html, 'property', 'og:type'));
        $this->assertSame(config('Blog')->title, $this->metaContent($html, 'property', 'og:site_name'));
        $this->assertSame('ko_KR', $this->metaContent($html, 'property', 'og:locale'));
    }

    /** 사이트 설명이 비어 있으면 태그가 빈 채로 나간다 — 설정 자체를 지킨다. */
    public function testSiteDescriptionIsConfigured(): void
    {
        $this->assertNotSame('', trim(config('Blog')->description));
    }

    /**
     * og:url 은 canonical 과 같아야 한다.
     *
     * apex 도메인에서도 같은 글을 서빙하므로 정본이 갈라지면 중복 콘텐츠가 된다.
     */
    public function testOgUrlMatchesCanonical(): void
    {
        $html = $this->decodedBody($this->call('GET', 'about'));

        preg_match('/<link rel="canonical" href="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'canonical 링크를 찾지 못했다.');
        $this->assertSame($m[1], $this->metaContent($html, 'property', 'og:url'));
    }

    /** 이미지가 없으면 og:image 를 내지 않고 카드 종류도 summary 다. */
    public function testPageWithoutImageOmitsOgImage(): void
    {
        $html = $this->decodedBody($this->call('GET', '/'));

        $this->assertNull($this->metaContent($html, 'property', 'og:image'));
        $this->assertSame('summary', $this->metaContent($html, 'name', 'twitter:card'));
    }

    private function makeUser(): \CodeIgniter\Shield\Entities\User
    {
        $users = auth()->getProvider();
        $user  = new \CodeIgniter\Shield\Entities\User([
            'username' => 'writer',
            'email'    => 'writer@example.com',
            'password' => 'secret-password-123',
        ]);
        $users->save($user);

        return $users->findById($users->getInsertID());
    }

    /** @return \App\Entities\Post */
    private function makePost(int $userId, string $title, string $body, ?string $image = null)
    {
        $posts = model(\App\Models\PostModel::class);
        $posts->insert([
            'user_id' => $userId,
            'title'   => $title,
            'body'    => $body,
            'image'   => $image,
            'status'  => \App\Entities\Post::STATUS_PUBLISHED,
        ]);

        return $posts->find($posts->getInsertID());
    }

    public function testPostDetailHasArticleOgTags(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '오지에스 태그 시험', '본문 첫 문장이다. 두 번째 문장.', 'cover.png');

        $html = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));

        $this->assertSame('article', $this->metaContent($html, 'property', 'og:type'));
        $this->assertSame('오지에스 태그 시험', $this->metaContent($html, 'property', 'og:title'));
        $this->assertStringContainsString('본문 첫 문장이다', (string) $this->metaContent($html, 'property', 'og:description'));
    }

    /** 이미지는 절대 URL 이어야 SNS 가 가져갈 수 있다. */
    public function testPostImageIsAbsoluteUrl(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '이미지 있는 글', '본문', 'cover.png');

        $html  = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));
        $image = $this->metaContent($html, 'property', 'og:image');

        $this->assertNotNull($image, 'og:image 가 있어야 한다.');
        $this->assertStringStartsWith('http', $image);
        $this->assertStringEndsWith('/uploads/cover.png', $image);
        $this->assertSame('summary_large_image', $this->metaContent($html, 'name', 'twitter:card'));
    }

    public function testPostWithoutImageOmitsOgImage(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '이미지 없는 글', '본문');

        $html = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));

        $this->assertNull($this->metaContent($html, 'property', 'og:image'));
        $this->assertSame('summary', $this->metaContent($html, 'name', 'twitter:card'));
    }

    /**
     * 설명은 검색 스니펫 길이에 맞춰 잘린다.
     *
     * 목록 카드용 기본값(80)을 그대로 쓰면 스니펫이 너무 짧아진다. 155 를
     * 넘겨 자르므로 최대 길이는 155 + 말줄임표 1자다.
     */
    public function testDescriptionIsTrimmedToSnippetLength(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '아주 긴 글', str_repeat('가나다라마바사아자차', 40));

        $html        = $this->decodedBody($this->call('GET', 'posts/' . $post->slug));
        $description = (string) $this->metaContent($html, 'property', 'og:description');

        $this->assertSame(156, mb_strlen($description), '155자 + 말줄임표여야 한다.');
        $this->assertStringEndsWith('…', $description);
    }

    /**
     * 제목에 & 가 들어가도 이중 이스케이프되지 않는다.
     *
     * 이 설계의 핵심 방지망이다. 컨트롤러가 esc() 한 값을 넘기거나 제목을
     * renderSection 에서 재사용하면 `&amp;amp;` 가 나온다.
     */
    public function testTitleIsNotDoubleEscaped(): void
    {
        $user = $this->makeUser();
        $post = $this->makePost($user->id, '정규식 & 패턴', '본문');

        // 이스케이프 상태 자체가 검증 대상이라 디코드하지 않은 원본을 본다.
        // TestResponse::getBody() 는 DOMParser 를 거치므로 response() 로 실제 바디를 꺼낸다.
        $html = $this->call('GET', 'posts/' . $post->slug)->response()->getBody();

        $this->assertStringNotContainsString('&amp;amp;', $html, '이중 이스케이프됐다.');
        $this->assertStringContainsString('content="정규식 &amp; 패턴"', $html);
    }

    /**
     * 메타 content 에 숫자 엔티티가 섞이지 않는다. (#113)
     *
     * `esc($v, 'attr')` 은 영숫자 외 모든 문자를 숫자 엔티티로 바꾼다 — 공백조차
     * `&#x20;` 이다. 그러면 한국어 설명이 5배로 부풀어 156자 description 이
     * 1000자를 넘기고, 파서에 따라 잘려 나간다.
     *
     * 값을 비교하는 다른 테스트들은 이걸 못 잡는다. decodedBody() 가 숫자
     * 엔티티까지 디코드해 버려 비교가 통과하기 때문이다. 그래서 원본 바디에서
     * 형태를 직접 본다.
     */
    /**
     * 컨트롤러 소스에서 `view('경로', …)` 호출을 뽑아 "뷰 이름 → 호출 전체 텍스트" 로 모은다.
     *
     * 정규식으로 범위를 자르면 이웃 호출이나 뒤따르는 무관한 코드를 삼킨다 —
     * 이 저장소에서 두 번 겪었다(SlugUrlAssemblyTest, 그리고 이 테스트의 첫 버전).
     * PHP 토크나이저는 문자열·주석을 하나의 토큰으로 주므로 괄호 짝을 정확히 셀 수 있다.
     *
     * @return array<string, list<string>>
     */
    private function viewCalls(string $source): array
    {
        $tokens = token_get_all($source);
        $calls  = [];
        $count  = count($tokens);

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'view') {
                continue;
            }

            // `$this->view(...)` 같은 메서드 호출은 헬퍼 view() 가 아니다.
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            // 다음 유의미 토큰이 '(' 여야 호출이다.
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT], true)) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }

            // 괄호 짝을 세어 호출 전체를 모은다. 첫 인자가 뷰 이름이다.
            $depth    = 0;
            $chunk    = '';
            $viewName = null;

            for ($k = $j; $k < $count; $k++) {
                $piece = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                $chunk .= $piece;

                if ($viewName === null
                    && is_array($tokens[$k])
                    && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $viewName = trim($tokens[$k][1], "'\"");
                }

                if ($piece === '(') {
                    $depth++;
                } elseif ($piece === ')') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }
            }

            if ($viewName !== null) {
                $calls[$viewName][] = $chunk;
            }
        }

        return $calls;
    }

    /**
     * 레이아웃을 쓰는 화면은 모두 meta 를 명시적으로 넘긴다. (#113)
     *
     * 넘기지 않으면 뷰 스코프에 남은 앞 렌더의 $meta 가 `?? []` 를 통과해
     * 엉뚱한 제목이 나간다(testMetaDoesNotLeakBetweenPages 참조). 그런데 그
     * 누수는 렌더 순서가 맞아떨어져야 드러나므로, 새 화면이 meta 를 빠뜨려도
     * 값 기반 테스트로는 잡히지 않는다. 그래서 배선 자체를 검사한다.
     *
     * 이 테스트가 실패하면 새로 만든 컨트롤러 액션에 다음을 더하면 된다.
     *   'meta' => ['title' => '화면 이름'],
     */
    public function testEveryLayoutViewPassesMeta(): void
    {
        $layoutViews = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH . 'Views'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // 인용부호·공백 변형을 허용한다. 정확한 문자열만 찾으면 뷰가
            // extend("layouts/default") 로 바뀌는 순간 검사 대상에서 통째로 빠진다.
            if (preg_match('/extend\s*\(\s*[\'"]layouts\/default[\'"]\s*\)/', $source) !== 1) {
                continue;
            }

            // app/Views/posts/show.php → posts/show
            $layoutViews[] = str_replace(
                [APPPATH . 'Views' . DIRECTORY_SEPARATOR, '.php'],
                '',
                $file->getPathname()
            );
        }

        $this->assertNotEmpty($layoutViews, '레이아웃을 쓰는 뷰를 찾지 못했다.');

        $callsByView = [];

        // 화면을 렌더하는 주체가 컨트롤러만은 아니다 — 에러 화면은 컨트롤러를
        // 거치지 않고 예외 핸들러가 app/Views/errors/html/*.php 를 include 하며,
        // 그 어댑터가 view() 로 레이아웃 뷰를 렌더한다(#114). 그래서 어댑터도
        // 렌더 주체로 함께 훑는다. 여기를 빠뜨리면 에러 화면이 meta 를
        // 빠뜨려도 이 가드가 잡지 못한다.
        $renderers = [APPPATH . 'Controllers', APPPATH . 'Views' . DIRECTORY_SEPARATOR . 'errors'];

        foreach ($renderers as $dir) {
            $sourceFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($sourceFiles as $file) {
                if ($file->getExtension() === 'php') {
                    foreach ($this->viewCalls((string) file_get_contents($file->getPathname())) as $view => $chunks) {
                        $callsByView[$view] = array_merge($callsByView[$view] ?? [], $chunks);
                    }
                }
            }
        }

        $missing = [];

        foreach ($layoutViews as $view) {
            $chunks = $callsByView[$view] ?? [];

            // 그 뷰를 렌더하는 호출이 하나라도 meta 를 빠뜨리면 위반이다.
            $hasAll = $chunks !== [] && array_reduce(
                $chunks,
                static fn (bool $carry, string $chunk): bool => $carry && str_contains($chunk, "'meta'"),
                true
            );

            if (! $hasAll) {
                $missing[] = $view;
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "레이아웃을 쓰는데 meta 를 넘기지 않는 화면:\n" . implode("\n", $missing)
        );
    }

    public function testMetaContentAvoidsNumericEntities(): void
    {
        $html = $this->call('GET', '/')->response()->getBody();

        preg_match_all('/<meta [^>]*content="([^"]*)"/', $html, $matches);
        $this->assertNotEmpty($matches[1], '메타 태그를 찾지 못했다.');

        foreach ($matches[1] as $content) {
            $this->assertStringNotContainsString(
                '&#x',
                $content,
                "메타 content 에 숫자 엔티티가 있다(attr 이스케이프를 쓴 듯): {$content}"
            );
        }
    }

    public function testPostListHasOwnTitle(): void
    {
        $html = $this->decodedBody($this->call('GET', 'posts'));

        $this->assertSame('글 목록', $this->metaContent($html, 'property', 'og:title'));
        // 설명은 사이트 기본값을 그대로 쓴다.
        $this->assertSame(config('Blog')->description, $this->metaContent($html, 'property', 'og:description'));
    }

    public function testCategoryPageHasCategoryTitle(): void
    {
        $categories = model(\App\Models\CategoryModel::class);
        $categories->insert(['name' => '회고', 'slug' => 'retro']);

        $html = $this->decodedBody($this->call('GET', 'categories/retro'));

        $this->assertSame('회고 글', $this->metaContent($html, 'property', 'og:title'));
    }

    public function testAboutHasOwnTitle(): void
    {
        $html = $this->decodedBody($this->call('GET', 'about'));

        $this->assertSame('소개', $this->metaContent($html, 'property', 'og:title'));
    }

    /**
     * 앞서 렌더된 페이지의 meta 가 다음 페이지로 새지 않는다. (#113)
     *
     * view() 는 setData 로 데이터를 누적하고 renderer 는 shared 서비스다. 그래서
     * 컨트롤러가 meta 를 넘기지 않으면 뷰 스코프에 **이전 렌더의 $meta 가 남아**
     * `$meta ?? []` 의 `??` 를 통과해 버린다. 실측으로 확인한 증상이다 —
     * about 을 본 뒤 홈을 렌더하면 홈의 og:title 이 '소개' 로 나왔다.
     *
     * setUp 의 resetSingle 로는 막지 못한다(한 요청 사이클 안의 문제가 아니라
     * 같은 프로세스에서 이어지는 렌더의 문제다). 컨트롤러가 빈 배열이라도
     * 명시적으로 넘겨야 값이 확정된다.
     */
    public function testMetaDoesNotLeakBetweenPages(): void
    {
        // 자기 제목을 가진 페이지를 먼저 렌더한다.
        $this->call('GET', 'about');

        // 그 다음 사이트 기본값을 쓰는 페이지를 렌더한다.
        $html = $this->decodedBody($this->call('GET', '/'));

        $this->assertSame(
            config('Blog')->title,
            $this->metaContent($html, 'property', 'og:title'),
            '앞 페이지의 meta 가 남았다.'
        );
    }
}
