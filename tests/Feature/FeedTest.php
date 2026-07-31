<?php

namespace Tests\Feature;

use App\Controllers\Feed;
use App\Entities\Post;
use App\Libraries\RssXml;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /feed 엔드포인트. (#113)
 *
 * 무엇이 실리는가(쿼리 규칙)는 FeedQueryTest 가 본다. 여기서는 응답 형식과
 * 배선 — 라우트·Content-Type·캐시 헤더·필드 매핑 — 을 확인한다.
 *
 * GET 전용이라 WithCsrf 가 아니라 FeatureTestTrait 를 쓴다.
 *
 * @internal
 */
final class FeedTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;
    protected $refresh   = true;

    private function seed(): void
    {
        model(PostModel::class)->insert([
            'user_id'     => null,
            'category_id' => null,
            'title'       => '한글 제목 글',
            'body'        => "# 큰제목\n\n**굵게** 쓴 본문이다.",
            'status'      => Post::STATUS_PUBLISHED,
        ]);
    }

    /**
     * XML 원문을 돌려준다.
     *
     * ⚠️ TestResponse::getBody() 를 쓰면 안 된다 — __call 이 DOMParser::getBody()
     * 로 넘겨 본문을 <!DOCTYPE html …> 로 감싼다. 반드시 response() 를 거친다.
     */
    private function feedBody(): string
    {
        return $this->call('GET', 'feed')->response()->getBody();
    }

    private function parsedFeed(): \SimpleXMLElement
    {
        $parsed = simplexml_load_string($this->feedBody());

        $this->assertNotFalse($parsed, '피드 본문이 XML 로 파싱되지 않는다.');

        return $parsed;
    }

    /** 라우트가 물리고 200 + RSS Content-Type 으로 응답한다. */
    public function testRespondsWithRssContentType(): void
    {
        $this->seed();

        $result = $this->call('GET', 'feed');

        $result->assertStatus(200);
        $this->assertStringContainsString(
            'application/rss+xml',
            $result->response()->getHeaderLine('Content-Type')
        );
    }

    /** 본문이 RSS 2.0 규격의 XML 이다. */
    public function testBodyIsValidRss(): void
    {
        $this->seed();

        $parsed = $this->parsedFeed();

        $this->assertSame('rss', $parsed->getName());
        $this->assertSame('2.0', (string) $parsed['version']);
        $this->assertContains(RssXml::ATOM_NAMESPACE_URI, $parsed->getDocNamespaces());
    }

    /**
     * 캐시 헤더가 붙는다.
     *
     * setHeader('Cache-Control', …) 로 구현하면 생성자의 noCache() 가 남긴
     * 값에 덧붙어 no-store 가 앞에 남는다 — 그 구현을 배제한다.
     */
    public function testSendsPublicCacheHeader(): void
    {
        $this->seed();

        $cacheControl = $this->call('GET', 'feed')->response()->getHeaderLine('Cache-Control');

        $this->assertStringContainsString('max-age=' . Feed::MAX_AGE, $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
    }

    /** channel 메타가 사이트 설정에서 온다. */
    public function testChannelUsesBlogConfig(): void
    {
        $this->seed();

        $channel = $this->parsedFeed()->channel;
        $blog    = config('Blog');

        $this->assertSame($blog->title, (string) $channel->title);
        $this->assertSame($blog->description, (string) $channel->description);
    }

    /**
     * item 의 link 가 퍼센트 인코딩된 정식 절대 URL 이다.
     *
     * 기대값을 site_url() 로 만들지 않는다 — 컨트롤러와 같은 함수를 쓰면 인코딩이
     * 통째로 빠져도 양쪽이 함께 틀려 통과한다. 날 한글이 본문에 없다는 것까지 본다.
     */
    public function testItemLinkIsPercentEncodedAbsoluteUrl(): void
    {
        $this->seed();

        $post = model(PostModel::class)->where('title', '한글 제목 글')->first();

        // 전제를 먼저 고정한다 — slug 가 로마자로 바뀌는 구현이면 이 테스트는 무의미해진다.
        $this->assertMatchesRegularExpression('/[가-힣]/u', $post->slug, 'slug 에 한글이 남아 있어야 하는 전제가 깨졌다.');

        $body = $this->feedBody();

        $this->assertStringContainsString(
            '<link>' . rtrim(base_url(), '/') . '/posts/' . rawurlencode($post->slug) . '</link>',
            $body
        );
        $this->assertStringNotContainsString($post->slug, $body, '인코딩되지 않은 한글 slug 가 그대로 나갔다.');
    }

    /**
     * guid 가 slug 가 아니라 id 기반이다.
     *
     * slug 를 guid 로 쓰면 제목 수정 시 같은 글이 구독자에게 새 글로 다시 뜬다.
     */
    public function testGuidIsIdBasedAndNotAPermaLink(): void
    {
        $this->seed();

        $post = model(PostModel::class)->where('title', '한글 제목 글')->first();
        $guid = $this->parsedFeed()->channel->item[0]->guid;

        $this->assertSame('false', (string) $guid['isPermaLink']);
        $this->assertStringEndsWith('/posts/id/' . $post->id, (string) $guid);
    }

    /**
     * pubDate 가 RFC 822 형식이다.
     *
     * 기대값을 format(DATE_RSS) 로 만들지 않는다 — 구현과 같은 함수를 쓰면 포맷이
     * 통째로 바뀌어도 양쪽이 함께 틀려 통과한다. 형태를 하드코딩한 정규식으로 본다.
     * 요일·월은 반드시 영문이어야 한다(규격).
     */
    public function testPubDateIsRfc822(): void
    {
        $this->seed();

        $pubDate = (string) $this->parsedFeed()->channel->item[0]->pubDate;

        $this->assertMatchesRegularExpression(
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/',
            $pubDate
        );
    }

    /**
     * description 이 마크다운 기호 없는 요약이다.
     *
     * 본문 원문을 그대로 넣는 구현을 배제한다.
     */
    public function testDescriptionIsPlainTextExcerpt(): void
    {
        $this->seed();

        $description = (string) $this->parsedFeed()->channel->item[0]->description;

        $this->assertStringContainsString('굵게', $description);
        $this->assertStringNotContainsString('**', $description, '마크다운 기호가 남아 있다.');
        $this->assertStringNotContainsString('#', $description, '마크다운 제목 기호가 남아 있다.');
    }

    /**
     * 제목의 XML 특수문자가 문서를 깨뜨리지 않는다.
     */
    public function testEscapesSpecialCharactersInTitle(): void
    {
        model(PostModel::class)->insert([
            'user_id'     => null,
            'category_id' => null,
            'title'       => 'A & B <태그>',
            'body'        => '본문',
            'status'      => Post::STATUS_PUBLISHED,
        ]);

        $titles = [];

        foreach ($this->parsedFeed()->channel->item as $item) {
            $titles[] = (string) $item->title;
        }

        $this->assertContains('A & B <태그>', $titles);
    }

    /** 글이 없어도 유효한 빈 피드를 낸다(500 이 아니다). */
    public function testEmptyFeedIsValid(): void
    {
        $result = $this->call('GET', 'feed');

        $result->assertStatus(200);

        $parsed = simplexml_load_string($result->response()->getBody());

        $this->assertNotFalse($parsed);
        $this->assertCount(0, $parsed->channel->item);
        $this->assertStringNotContainsString('lastBuildDate', $result->response()->getBody());
    }

    /**
     * 🔴 교차 증거 — 같은 글의 feed <link> 와 sitemap <loc> 이 바이트 단위로 일치한다.
     *
     * 두 문서는 서로 다른 컨트롤러에서 만들어지므로, 일치한다는 것은 URL 조립이
     * 실제로 한 곳(absolute_url)에서 온다는 증거다. 한쪽만 바뀌는 회귀를 잡는다.
     */
    public function testFeedLinkMatchesSitemapLoc(): void
    {
        $this->seed();

        $post = model(PostModel::class)->where('title', '한글 제목 글')->first();

        $feedLink = (string) $this->parsedFeed()->channel->item[0]->link;

        $sitemap = $this->call('GET', 'sitemap.xml')->response()->getBody();

        $this->assertStringContainsString(
            '<loc>' . $feedLink . '</loc>',
            $sitemap,
            'feed 의 link 와 sitemap 의 loc 이 갈라졌다 — URL 조립이 두 곳으로 나뉘었다.'
        );
        $this->assertStringEndsWith(rawurlencode($post->slug), $feedLink);
    }

    /**
     * 🔴 회귀 테스트 — lastBuildDate 는 목록 첫 글(창작순 최신)이 아니라 실린
     * 글들의 updated_at 최대값을 써야 한다.
     *
     * "오래 전에 작성됐지만 최근에 수정된 글"이 있으면, created_at DESC 정렬의
     * 첫 항목은 그 글이 아니다 — 첫 항목의 updated_at 만 보면 그 수정을 놓친다.
     * sitemap 은 updated_at DESC 정렬이라 이 문제가 없지만(첫 행이 곧 최대값),
     * 피드는 정렬 기준이 달라 실제로 어긋난다.
     *
     * 기대값을 format(DATE_RSS) 로 만들지 않는다 — 구현과 같은 함수를 쓰면 계산이
     * 통째로 틀려도 양쪽이 함께 틀려 통과한다. RFC 822 문자열을 하드코딩해서 본다.
     */
    public function testLastBuildDateUsesMaxUpdatedAtAcrossItems(): void
    {
        $posts = model(PostModel::class);

        $posts->insert([
            'user_id'     => null,
            'category_id' => null,
            'title'       => '오래된 글',
            'body'        => '본문',
            'status'      => Post::STATUS_PUBLISHED,
        ]);
        $oldPostId = $posts->getInsertID();

        $posts->insert([
            'user_id'     => null,
            'category_id' => null,
            'title'       => '새 글',
            'body'        => '본문',
            'status'      => Post::STATUS_PUBLISHED,
        ]);
        $newPostId = $posts->getInsertID();

        // $useTimestamps 가 채우므로 insert 로는 임의 시각을 못 넣는다 — 직접 갱신한다
        // (PostModel::builder() 는 protected 라 db_connect() 를 거친다).
        $db = db_connect();

        // created_at 은 오래됐지만, 목록에 실린 글들 중 updated_at 이 가장 최근이다.
        $db->table('posts')->where('id', $oldPostId)->update([
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2026-07-31 09:00:00',
        ]);

        // created_at 이 더 최근이라 목록 첫 항목이 되지만, updated_at 은 위 글보다 이전이다.
        $db->table('posts')->where('id', $newPostId)->update([
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
        ]);

        $channel = $this->parsedFeed()->channel;

        $this->assertSame(
            '새 글',
            (string) $channel->item[0]->title,
            '전제 확인 — created_at 이 더 최근인 글이 목록 첫 항목이어야 한다.'
        );
        $this->assertSame(
            'Fri, 31 Jul 2026 09:00:00 +0000',
            (string) $channel->lastBuildDate,
            'lastBuildDate 가 목록 첫 글의 updated_at 만 썼다면(구 구현) 2026-07-30 이 나와 이 값과 달라진다.'
        );
    }
}
