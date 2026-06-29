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
            ],
            [
                'subject' => '',
                'body' => '',
                'sender_type' => 'agent',
                'created_at' => '2023-01-02 12:00:00',
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

        // Check for alpine data indicating single-open behavior
        $view->assertSee('x-data="{ activeArticle: null }"', false);
        $view->assertSee('activeArticle = (activeArticle === 0 ? null : 0)', false);
        $view->assertSee('x-show="activeArticle === 0"', false);
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
