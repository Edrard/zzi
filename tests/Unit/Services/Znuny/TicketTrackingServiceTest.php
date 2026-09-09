<?php

namespace Tests\Unit\Services\Znuny;

use App\Models\User;
use App\Services\Znuny\TicketTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TicketTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private TicketTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TicketTrackingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_returns_false_if_tracking_is_disabled()
    {
        $user = User::factory()->create([
            'track_new_tickets' => false,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
        ]);

        $ticketData = [
            'TicketID' => 123,
            'Created' => Carbon::now()->subDays(1)->toDateTimeString(),
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_returns_false_if_ticket_is_linked_to_zabbix_problem()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
        ]);

        $ticketData = [
            'TicketID' => 123,
            'Created' => Carbon::now()->subDays(1)->toDateTimeString(),
            'is_linked_to_zabbix_problem' => true,
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_returns_false_if_ticket_id_is_missing()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
        ]);

        $ticketData = [
            'Created' => Carbon::now()->subDays(1)->toDateTimeString(),
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_returns_false_if_ticket_is_seen()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
        ]);

        $ticketData = [
            'TicketID' => 123,
            'Created' => Carbon::now()->subDays(1)->toDateTimeString(),
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, [123]));
    }

    public function test_it_returns_false_if_ticket_created_before_tracking_since()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(1),
        ]);

        $ticketData = [
            'TicketID' => 123,
            'Created' => Carbon::now()->subDays(2)->toDateTimeString(),
        ];

        Config::set('znuny.new_ticket_max_age_days', 3);

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_returns_false_if_created_is_invalid()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
        ]);

        $ticketData = [
            'TicketID' => 123,
            'Created' => 'not-a-valid-date',
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_respects_max_age_days_expiration()
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00'));

        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(10),
        ]);

        Config::set('znuny.new_ticket_max_age_days', 3);

        // Younger than 3 days
        $ticketYoung = [
            'TicketID' => 101,
            'Created' => Carbon::now()->subDays(2)->subHours(23)->toDateTimeString(),
        ];
        $this->assertTrue($this->service->isTicketNew($user, $ticketYoung, []));

        // Exactly 3 days
        $ticketExact = [
            'TicketID' => 102,
            'Created' => Carbon::now()->subDays(3)->toDateTimeString(),
        ];
        $this->assertFalse($this->service->isTicketNew($user, $ticketExact, []));

        // Older than 3 days
        $ticketOld = [
            'TicketID' => 103,
            'Created' => Carbon::now()->subDays(4)->toDateTimeString(),
        ];
        $this->assertFalse($this->service->isTicketNew($user, $ticketOld, []));
    }

    public function test_it_ignores_expiration_if_max_age_is_false()
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00'));

        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(10),
        ]);

        Config::set('znuny.new_ticket_max_age_days', false);

        // Older than 3 days, but after tracking_since
        $ticketOld = [
            'TicketID' => 103,
            'Created' => Carbon::now()->subDays(4)->toDateTimeString(),
        ];

        $this->assertTrue($this->service->isTicketNew($user, $ticketOld, []));

        // Before tracking_since still fails
        $ticketVeryOld = [
            'TicketID' => 104,
            'Created' => Carbon::now()->subDays(11)->toDateTimeString(),
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketVeryOld, []));
    }

    public function test_it_preserves_star_when_user_has_no_ignore_regexes()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => null,
        ]);

        $ticketData = [
            'TicketID' => 105,
            'Title' => 'Postmaster:: Daily report',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];

        $this->assertTrue($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_suppresses_star_when_title_matches_single_ignore_regex()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => ['^Postmaster::'],
        ]);

        $ticketData = [
            'TicketID' => 106,
            'Title' => 'Postmaster:: Delivery Status Notification',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_shows_star_when_title_does_not_match_ignore_regex()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => ['^Postmaster::'],
        ]);

        $ticketData = [
            'TicketID' => 107,
            'Title' => 'Customer request #12345',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];

        $this->assertTrue($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_suppresses_star_when_any_of_multiple_regexes_matches()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => [
                '^Postmaster::',
                'backup failure',
                '^Cron <.*>',
            ],
        ]);

        $matchingTicket = [
            'TicketID' => 108,
            'Title' => 'Nightly backup failure alert on db1',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];
        $this->assertFalse($this->service->isTicketNew($user, $matchingTicket, []));

        $nonMatchingTicket = [
            'TicketID' => 109,
            'Title' => 'Urgent customer inquiry',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];
        $this->assertTrue($this->service->isTicketNew($user, $nonMatchingTicket, []));
    }

    public function test_it_matches_case_insensitively()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => ['postmaster::'],
        ]);

        $ticketData = [
            'TicketID' => 110,
            'Title' => 'POSTMASTER:: Critical warning',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_matches_utf8_correctly()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => ['звернення'],
        ]);

        $ticketData = [
            'TicketID' => 111,
            'Title' => 'Важливе ЗВЕРНЕННЯ від бухгалтерії',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];

        $this->assertFalse($this->service->isTicketNew($user, $ticketData, []));
    }

    public function test_it_ignores_blank_entries_in_regex_list()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => ['', '   ', '^Ignored::'],
        ]);

        $nonMatching = [
            'TicketID' => 112,
            'Title' => 'Ordinary ticket',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];
        $this->assertTrue($this->service->isTicketNew($user, $nonMatching, []));

        $matching = [
            'TicketID' => 113,
            'Title' => 'Ignored:: Status update',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];
        $this->assertFalse($this->service->isTicketNew($user, $matching, []));
    }

    public function test_it_does_not_suppress_star_when_title_is_missing_or_empty()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => ['.*'],
        ]);

        $noTitleTicket = [
            'TicketID' => 114,
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];
        $this->assertTrue($this->service->isTicketNew($user, $noTitleTicket, []));

        $emptyTitleTicket = [
            'TicketID' => 115,
            'Title' => '',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];
        $this->assertTrue($this->service->isTicketNew($user, $emptyTitleTicket, []));
    }

    public function test_it_does_not_crash_and_handles_malformed_stored_regex()
    {
        $user = User::factory()->create([
            'track_new_tickets' => true,
            'ticket_tracking_since' => Carbon::now()->subDays(5),
            'new_ticket_subject_ignore_regexes' => ['[unclosed_character_class', '(?'],
        ]);

        $ticketData = [
            'TicketID' => 116,
            'Title' => 'Any title here',
            'Created' => Carbon::now()->subHours(1)->toDateTimeString(),
        ];

        // Malformed regexes are skipped defensively, so normal star is returned
        $this->assertTrue($this->service->isTicketNew($user, $ticketData, []));
    }
}
