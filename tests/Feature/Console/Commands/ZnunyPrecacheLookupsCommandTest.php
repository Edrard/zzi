<?php

namespace Tests\Feature\Console\Commands;

use App\Services\Znuny\ZnunyCachedLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyPrecacheLookupsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_warms_cache_successfully()
    {
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('invalidateCache')->once();

            $mock->shouldReceive('getTicketStates')->once()->andReturn(['open']);
            $mock->shouldReceive('getTicketPriorities')->once()->andReturn(['3 normal']);
            $mock->shouldReceive('getTicketTypes')->once()->andReturn(['Incident']);

            $mock->shouldReceive('getAllQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
            ]);

            $mock->shouldReceive('getFilteredQueueOptions')->once()->andReturn([
                'Raw' => 'Raw',
            ]);

            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->with('Raw', true)->once()->andReturn([]);
            $mock->shouldReceive('getCustomerUserSearchTerms')->with('Raw')->once()->andReturn([]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->with('Raw')->once()->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->with('Raw')->once()->andReturn(null);
        });

        $this->artisan('znuny:precache-lookups')
            ->expectsOutput('Starting Znuny UI lookup precache...')
            ->expectsOutputToContain('Precache completed successfully.')
            ->assertExitCode(0);
    }

    public function test_command_handles_failures_safely()
    {
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('invalidateCache')->once();

            $mock->shouldReceive('getTicketStates')->once()->andThrow(new \Exception('API Error'));
            $mock->shouldReceive('getTicketPriorities')->once()->andReturn(['3 normal']);
            $mock->shouldReceive('getTicketTypes')->once()->andReturn(['Incident']);

            $mock->shouldReceive('getAllQueues')->once()->andReturn([
                ['id' => 1, 'name' => 'Raw', 'label' => 'Raw'],
                ['id' => 2, 'name' => 'Network', 'label' => 'Network'],
            ]);

            $mock->shouldReceive('getFilteredQueueOptions')->once()->andReturn([
                'Raw' => 'Raw',
                'Network' => 'Network',
            ]);

            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->with('Raw', true)->once()->andReturn([]);
            $mock->shouldReceive('getCustomerUserSearchTerms')->with('Raw')->once()->andReturn([]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->with('Raw')->once()->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->with('Raw')->once()->andReturn(null);

            $mock->shouldReceive('getAssignableOwnerOptionsForQueue')->with('Network', true)->once()->andThrow(new \Exception('Network queue failed'));
            $mock->shouldReceive('getCustomerUserSearchTerms')->with('Network')->once()->andReturn([]);
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')->with('Network')->once()->andReturn([]);
            $mock->shouldReceive('resolveTemplateCandidate')->with('Network')->once()->andReturn(null);
        });

        $this->artisan('znuny:precache-lookups')
            ->expectsOutputToContain('Precache finished with 2 failures.')
            ->assertExitCode(1);
    }
}
