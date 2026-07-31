<?php

namespace Tests\Feature;

use App\Entities\Post;
use App\Models\PostModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * 구독자가 피드를 발견하는 경로 — head 의 autodiscovery 링크와 푸터 링크. (#113)
 *
 * 홈을 따로 보는 이유가 있다: home/index.php 는 공유 레이아웃을 쓰지 않고
 * 자체 <head> 를 그린다. 레이아웃만 고치면 홈에는 아무것도 안 붙는다.
 *
 * @internal
 */
final class FeedDiscoveryTest extends CIUnitTestCase
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
            'title'       => '글',
            'body'        => '본문',
            'status'      => Post::STATUS_PUBLISHED,
        ]);
    }

    /**
     * 원본 HTML 을 돌려준다.
     *
     * decodedBody() 는 엔티티를 풀어 버려 태그 형태를 그대로 볼 수 없다.
     */
    private function html(string $path): string
    {
        return $this->call('GET', $path)->response()->getBody();
    }

    private function assertHasAutodiscovery(string $html, string $where): void
    {
        $this->assertMatchesRegularExpression(
            '#<link[^>]+rel="alternate"[^>]+type="application/rss\+xml"[^>]*>#',
            $html,
            "{$where} 에 RSS autodiscovery 링크가 없다."
        );
    }

    /** 레이아웃을 쓰는 페이지에 autodiscovery 링크가 있다. */
    public function testLayoutPageHasAutodiscoveryLink(): void
    {
        $this->assertHasAutodiscovery($this->html('about'), '레이아웃 페이지(about)');
    }

    /** 목록 페이지에도 있다(레이아웃 상속 확인). */
    public function testPostListHasAutodiscoveryLink(): void
    {
        $this->seed();

        $this->assertHasAutodiscovery($this->html('posts'), '글 목록');
    }

    /**
     * 🔴 홈에도 있다 — 홈은 자체 <head> 를 그리므로 별도로 붙여야 한다.
     */
    public function testHomeHasAutodiscoveryLink(): void
    {
        $this->seed();

        $this->assertHasAutodiscovery($this->html('/'), '홈');
    }

    /**
     * autodiscovery 의 href 가 피드의 atom:link rel="self" 와 바이트 단위로 같다.
     *
     * 두 값이 다르면 리더가 정본을 어느 쪽으로 저장할지 갈린다.
     */
    public function testAutodiscoveryHrefMatchesFeedSelfLink(): void
    {
        $this->seed();

        preg_match(
            '#<link[^>]+rel="alternate"[^>]+href="([^"]+)"#',
            $this->html('about'),
            $matches
        );
        $this->assertNotEmpty($matches, 'autodiscovery 링크에서 href 를 찾지 못했다.');

        $feed = simplexml_load_string($this->call('GET', 'feed')->response()->getBody());
        $this->assertNotFalse($feed);

        // children() 로 네임스페이스를 지정해 얻은 엘리먼트는 ['href'] 배열 접근이
        // 항상 빈 문자열을 돌려준다(PHP SimpleXML 의 알려진 동작 — 실측 확인).
        // attributes() 를 거치면 같은 값을 정상적으로 얻는다.
        $selfHref = (string) $feed->channel
            ->children('http://www.w3.org/2005/Atom')
            ->link->attributes()['href'];

        $this->assertSame($selfHref, $matches[1], 'autodiscovery href 와 atom:link rel="self" 가 갈라졌다.');
    }

    /** 푸터에 사람이 클릭할 수 있는 RSS 링크가 있다. */
    public function testFooterHasRssLink(): void
    {
        $this->assertMatchesRegularExpression(
            '#<a[^>]+href="[^"]*/feed"[^>]*>RSS</a>#',
            $this->html('about'),
            '푸터에 RSS 링크가 없다.'
        );
    }

    /** 푸터는 공통이라 홈에도 링크가 있다. */
    public function testHomeFooterHasRssLink(): void
    {
        $this->seed();

        $this->assertStringContainsString('>RSS</a>', $this->html('/'), '홈 푸터에 RSS 링크가 없다.');
    }
}
