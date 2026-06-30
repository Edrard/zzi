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
        $view->assertSee('No subject'); // Fallback for empty subject
        $view->assertSee('No body'); // Fallback for empty body

        // Check for removed fields
        $view->assertDontSee('Visible to Customer');
        $view->assertDontSee('No.');
        $view->assertDontSee('Channel');

        // Check for expanded state fields
        $view->assertSee('Body of article 1');
        $view->assertSee('customer');
        $view->assertSee('agent');
        $view->assertSee(app(DateTimeDisplayService::class)->formatDateTime('2023-01-01 12:00:00'));

        // Check for indicators
        $view->assertSee('aria-label="Article"', false);
        $view->assertSee('aria-label="Internal note"', false);
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
        $view->assertSee('overflow-x-auto">Body of article 1</div>', false);
        $view->assertSee('overflow-x-auto">No body</div>', false);
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
}
