<?php

namespace Tests\Unit\Services\Znuny;

use App\Services\Znuny\ZnunyTicketWorkspaceCacheReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZnunyTicketWorkspaceCacheReaderHtmlBodyCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_single_ticket_normalizes_html_body_article_count(): void
    {
        $reader = app(ZnunyTicketWorkspaceCacheReader::class);

        $valid = $reader->normalizeSingleTicket([
            'TicketID' => 901,
            'HTMLBodyArticleCount' => '3',
        ]);
        $missing = $reader->normalizeSingleTicket([
            'TicketID' => 902,
        ]);
        $invalid = $reader->normalizeSingleTicket([
            'TicketID' => 903,
            'HTMLBodyArticleCount' => -5,
        ]);

        $this->assertSame(3, $valid['HTMLBodyArticleCount']);
        $this->assertSame(0, $missing['HTMLBodyArticleCount']);
        $this->assertSame(0, $invalid['HTMLBodyArticleCount']);
    }
}
