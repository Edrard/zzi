<?php

namespace Tests\Feature\Filament\Infolists;

use App\Services\Support\DateTimeDisplayService;
use Tests\TestCase;

class ArticlesAccordionTest extends TestCase
{
    public function test_accordion_renders_compactly_with_subject()
    {
        $articles = [
            [
                'subject' => 'Subject 1',
                'body' => 'Body of article 1',
                'sender_type' => 'customer',
                'created_at' => '2023-01-01 12:00:00',
                'is_visible_for_customer' => true,
            ],
            [
                'subject' => '',
                'body' => '',
                'sender_type' => 'agent',
                'created_at' => '2023-01-02 12:00:00',
                'is_visible_for_customer' => false,
            ],
        ];

        $view = $this->view('filament.infolists.articles-accordion', [
            'getState' => function () use ($articles) {
                return $articles;
            },
        ]);

        $view->assertSee('Subject 1');
        $view->assertSee(__('znuny_ticket_workspace.accordion.no_subject')); // Fallback for empty subject
        $view->assertSee(__('znuny_ticket_workspace.accordion.no_body')); // Fallback for empty body

        // Check for removed fields
        $view->assertDontSee('Visible to Customer');
        $view->assertDontSee('No.');
        $view->assertDontSee('Channel');

        // Check for expanded state fields
        $view->assertSee('Body of article 1');
        $view->assertSee(__('zabbix_tickets.sender_types.customer'));
        $view->assertSee(__('zabbix_tickets.sender_types.agent'));
        $view->assertSee(app(DateTimeDisplayService::class)->formatLocalizedDateTime('2023-01-01 12:00:00'));

        // Check for indicators
        $view->assertSee('aria-label="'.__('znuny_ticket_workspace.accordion.article').'"', false);
        $view->assertSee('aria-label="'.__('znuny_ticket_workspace.accordion.internal_note').'"', false);
        $view->assertDontSee('>Article</span>', false);
        $view->assertDontSee('>Note</span>', false);

        // Check for alpine data indicating single-open behavior and auto-scroll
        $view->assertSee('activeArticle: null', false);
        $view->assertSee('toggleArticle(', false);
        $view->assertSee('findScrollableAncestor(', false);
        $view->assertSee('scrollOpenedArticleIntoFocus(', false);
        $view->assertDontSee('scrollIntoView({ block: \'end\'', false);
        $view->assertSee('getBoundingClientRect(', false);
        $view->assertSee('visibleTop =', false);
        $view->assertSee('visibleBottom =', false);
        $view->assertSee('targetScrollTop', false);
        $view->assertSee('Math.min(', false);
        $view->assertSee('Math.max(', false);
        $view->assertDontSee('top: container.scrollHeight', false);
        $view->assertSee('scrollTo(', false);
        $view->assertSee('requestAnimationFrame(', false);
        $view->assertSee('setTimeout(', false);
        $view->assertSee('x-show="activeArticle === 0"', false);

        // Check for compact styling classes
        $view->assertDontSee('p-3 text-sm leading-relaxed');
        $view->assertDontSee(' p-2 text-sm leading-snug'); // padding removed from blade to be controlled by CSS
        $view->assertSee('zbx-ticket-article-text whitespace-pre-wrap break-words rounded-md bg-gray-50 dark:bg-white/5 text-sm leading-snug ring-1');

        // Check that body text has no leading/trailing whitespace within the div
        $view->assertSee('overflow-x-auto p-3">Body of article 1</div>', false);
        $view->assertSee('overflow-x-auto">'.__('znuny_ticket_workspace.accordion.no_body').'</div>', false);
    }

    public function test_accordion_renders_empty_state()
    {
        $view = $this->view('filament.infolists.articles-accordion', [
            'getState' => function () {
                return [];
            },
        ]);

        $view->assertSee('No articles found.');
    }

    public function test_accordion_renders_html_and_lazy_loads_images()
    {
        $articles = [
            [
                'ticket_id' => 123,
                'article_id' => 456,
                'subject' => 'HTML Subject',
                'mime_type' => 'text/html',
                'body' => '<p>HTML content</p><img src="cid:image1@domain.com">',
            ],
            [
                'ticket_id' => 123,
                'article_id' => 457,
                'subject' => 'Plain Subject',
                'mime_type' => 'text/plain',
                'body' => '<p>Plain content</p>',
            ],
        ];

        $view = $this->view('filament.infolists.articles-accordion', [
            'getState' => function () use ($articles) {
                return $articles;
            },
        ]);

        // 1. HTML article content renders sanitized HTML, not escaped markup
        $view->assertSeeHtml('<p>HTML content</p>');
        $view->assertDontSeeHtml('&lt;p&gt;HTML content&lt;/p&gt;');
        $view->assertSee('zbx-ticket-article-html');

        // 2. Plain text remains escaped/plain
        $view->assertSeeHtml('&lt;p&gt;Plain content&lt;/p&gt;');
        $view->assertDontSeeHtml('<p>Plain content</p>');

        // 3. Initial CID markup includes data-znuny-inline-src
        $view->assertSee('data-znuny-inline-src="/znuny/ticket/123/article/456/inline-image/', false);

        // 4. Initial CID markup does NOT contain an immediately fetchable internal route in src
        $html = (string) $view;

        $dom = new \DOMDocument;
        $useErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            @$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
        }
        $images = $dom->getElementsByTagName('img');
        $this->assertEquals(1, $images->length);
        $this->assertFalse($images->item(0)->hasAttribute('src'), 'No real src attribute should exist');
        $view->assertDontSee('src="cid:image1@domain.com"');

        // 5. Alpine contains activateInlineImages
        $view->assertSee('activateInlineImages(index)');

        // 6. toggleArticle() calls activation only on open (Alpine method)
        $view->assertSee('this.activateInlineImages(index);');

        // 7-10. Alpine activation logic exists for correct scoping and attribute copying
        $view->assertSee('const item = this.$refs.articlesAccordion.querySelector(`[data-article-index=\'${index}\']`);', false);
        $view->assertSee('const images = item.querySelectorAll(\'img[data-znuny-inline-src]\');', false);
        $view->assertSee('if (!img.getAttribute(\'src\')) {', false);
        $view->assertSee('img.setAttribute(\'src\', img.getAttribute(\'data-znuny-inline-src\'));', false);
        $view->assertSee('img.removeAttribute(\'data-znuny-inline-src\');', false);

        // 11. existing accordion scroll logic remains present
        $view->assertSee('this.scrollOpenedArticleIntoFocus(index, \'smooth\');', false);
    }
}
