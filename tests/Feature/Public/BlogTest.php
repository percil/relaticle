<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;

/*
 * Ink's blog is off by default (relaticle.features.blog) and enabled here only
 * because phpunit.xml sets RELATICLE_FEATURE_BLOG=true for the test suite.
 * These pages render through vendor/relaticle/ink's own default views against
 * resources/views/layouts/app.blade.php, not app-authored blog views: the SEO
 * enhancements the old app views added (canonical tags, og:type, JSON-LD,
 * search-empty-state copy, cover-image framing, tag pills, pagination-overflow
 * messaging) were deleted with those views and have no equivalent here.
 */
describe('Blog pages', function () {
    it('displays the blog index', function () {
        $this->get('/blog')
            ->assertStatus(200)
            ->assertSee('Engineering Blog');
    });

    it('displays published posts on the index', function () {
        $post = Post::factory()->published()->create();

        $this->get('/blog')
            ->assertStatus(200)
            ->assertSee($post->title);
    });

    it('does not display draft posts on the index', function () {
        $post = Post::factory()->draft()->create();

        $this->get('/blog')
            ->assertStatus(200)
            ->assertDontSee($post->title);
    });

    it('displays a single blog post', function () {
        $post = Post::factory()->published()->create();

        $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->assertSee($post->title);
    });

    it('returns 404 for non-existent blog post', function () {
        $this->get('/blog/non-existent-post')
            ->assertStatus(404);
    });

    it('displays posts filtered by category', function () {
        $category = Category::factory()->create();
        $post = Post::factory()->published()->create(['category_id' => $category->id]);

        $this->get("/blog/category/{$category->slug}")
            ->assertStatus(200)
            ->assertSee($post->title)
            ->assertSee($category->name);
    });

    it('displays posts filtered by tag', function () {
        $tag = Tag::factory()->create();
        $taggedPost = Post::factory()->published()->create();
        $otherPost = Post::factory()->published()->create();

        $taggedPost->tags()->attach($tag);

        $this->get("/blog/tag/{$tag->slug}")
            ->assertStatus(200)
            ->assertSee($taggedPost->title)
            ->assertSee('#'.$tag->name)
            ->assertDontSee($otherPost->title);
    });

    it('returns 404 for non-existent tag', function () {
        $this->get('/blog/tag/non-existent-tag')
            ->assertStatus(404);
    });

    it('returns RSS feed', function () {
        Post::factory()->published()->create();

        $response = $this->get('/blog/feed')->assertStatus(200);

        // Match the media type, not the full header: ink appends `; charset=UTF-8`,
        // which the app's old feed controller omitted. Both are valid RSS.
        expect($response->headers->get('Content-Type'))
            ->toStartWith('application/rss+xml');
    });

    it('renders the signed preview for a signed-in app user without an edit link', function () {
        // The blog admin lives in the sysadmin panel; building an app-panel URL here
        // used to throw RouteNotFoundException and 500 the page for any logged-in user.
        $post = Post::factory()->create();

        $this->actingAs(User::factory()->withPersonalWorkspace()->create())
            ->get(URL::temporarySignedRoute('blog.preview', now()->addHour(), ['post' => $post]))
            ->assertStatus(200)
            ->assertSee($post->title);
    });

    it('stops honouring a preview link once its signature expires, even as markdown', function () {
        // ProvideMarkdownResponse serves a cached body without calling $next(), so a
        // markdown hit used to skip ValidateSignature entirely and keep serving the
        // draft for the rest of the 1h cache TTL. AI crawler user agents are detected
        // as markdown requests, so ordinary crawl traffic warmed that cache.
        $post = Post::factory()->draft()->create(['content' => 'Unpublished body copy.']);

        $url = URL::temporarySignedRoute('blog.preview', now()->addHour(), ['post' => $post]);

        // Warm the cache late in the signature window: the 1h cache TTL starts here,
        // so it outlives the signature by 59 minutes.
        $this->travel(59)->minutes();
        $this->get($url, ['Accept' => 'text/markdown'])->assertStatus(200);

        $this->travel(2)->minutes();

        $this->get($url, ['Accept' => 'text/markdown'])->assertStatus(403);
        $this->get($url, ['User-Agent' => 'ClaudeBot/1.0'])->assertStatus(403);
    });

    it('still serves markdown for published posts, which are ungated', function () {
        $post = Post::factory()->published()->create();

        $response = $this->get("/blog/{$post->slug}", ['Accept' => 'text/markdown'])
            ->assertStatus(200);

        expect($response->headers->get('Content-Type'))->toStartWith('text/markdown');
    });

    it('404s a preview request whose post segment is not numeric', function () {
        $this->get('/blog/preview/not-a-post-id')
            ->assertStatus(404);
    });

    it('strips raw HTML embedded in post markdown instead of rendering it executable', function () {
        // Ink's Post::toSafeHtml() pins html_input=>strip (see the method's own
        // docblock), so untrusted markup is removed from the output entirely
        // rather than escaped-and-displayed like the deleted app renderer did.
        $post = Post::factory()->published()->create([
            'content' => "## Intro\nSafe copy.\n\n<script>window.pwned = true</script>\n<img src=x onerror=\"window.pwned = true\">",
        ]);

        $html = $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->getContent();

        $article = mb_substr($html, (int) mb_strpos($html, '<article'), (int) mb_strpos($html, '</article>') - (int) mb_strpos($html, '<article'));

        expect($article)->not->toContain('<script>window.pwned')
            ->and($article)->not->toContain('<img src=x onerror')
            ->and($article)->toContain('Safe copy.');
    });
});
