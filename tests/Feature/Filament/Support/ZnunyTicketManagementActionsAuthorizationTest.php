<?php

namespace Tests\Feature\Filament\Support;

use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Filament\Support\ZnunyTicketManagementActions;
use App\Models\User;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use App\Services\Znuny\ZnunyClient;
use App\Services\Znuny\ClosedTicketCacheService;
use App\Services\Znuny\ZnunyLinkedTicketCloseService;
use App\Services\Znuny\ZnunyLinkedTicketReopenService;
use App\Services\Znuny\ZnunyTicketArticleWriteService;
use App\Services\Znuny\ZnunyTicketCacheService;
use App\Services\Znuny\ZnunyTicketWorkspaceTicketRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Filament\Actions\Action;
use Filament\Support\Exceptions\Cancel;

class ZnunyTicketManagementActionsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(ZnunyClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicket')->andReturn(null)->byDefault();
            $mock->shouldReceive('post', 'get', 'put', 'patch')->andReturn([])->byDefault();
        });

        $this->mock(ClosedTicketCacheService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicket')->andReturn(null)->byDefault();
        });
    }

    protected function getActions(): array
    {
        return [
            ZnunyTicketManagementActions::closeTicketAction('close_ticket'),
            ZnunyTicketManagementActions::reopenTicketAction('reopen_ticket'),
            ZnunyTicketManagementActions::addNoteOrArticleAction('add_note_or_article'),
            ZnunyTicketManagementActions::takeOrReleaseTicketAction('take_or_release_ticket'),
            ZnunyTicketManagementActions::changeAssignmentAction('change_assignment'),
        ];
    }

    protected function evaluateVisibilityClosure(Action $action, array $arguments, $record = null): bool
    {
        $reflection = new ReflectionClass($action);
        $property = $reflection->getProperty('isVisible');
        $property->setAccessible(true);
        $isVisibleValue = $property->getValue($action);

        if ($isVisibleValue instanceof \Closure) {
            return app()->call($isVisibleValue, ['arguments' => $arguments, 'record' => $record]);
        }

        if ($isVisibleValue === null) {
            if (method_exists($action, 'isVisible')) {
                return $action->isVisible();
            }
            return true;
        }

        return (bool) $isVisibleValue;
    }

    protected function mockTicketCacheResponses()
    {
        $this->mock(ZnunyTicketCacheService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTicket')->with(1)->andReturn([
                'TicketID' => 1,
                'StateType' => 'open',
                'Lock' => 'lock',
                'State' => 'open'
            ])->byDefault();

            $mock->shouldReceive('getTicket')->with(2)->andReturn([
                'TicketID' => 2,
                'StateType' => 'closed',
                'Lock' => 'unlock',
                'State' => 'closed successful'
            ])->byDefault();

            $mock->shouldReceive('getTicket')->with(101)->andReturn([
                'TicketID' => 101,
                'StateType' => 'open',
                'Lock' => 'unlock',
                'State' => 'new'
            ])->byDefault();

            $mock->shouldReceive('getTicket')->andReturn(null)->byDefault();
        });
    }

    public function test_write_actions_are_hidden_for_viewer()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);

        $this->mockTicketCacheResponses();

        $actions = [
            'close_ticket' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
            'reopen_ticket' => ['record' => ['StateType' => 'closed'], 'arguments' => ['znuny_ticket_id' => 2]],
            'add_note_or_article' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
            'take_or_release_ticket' => ['record' => ['StateType' => 'open', 'Lock' => 'lock'], 'arguments' => ['znuny_ticket_id' => 1]],
            'change_assignment' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
        ];

        foreach ($this->getActions() as $action) {
            $name = $action->getName();
            $data = $actions[$name];

            $isVisible = $this->evaluateVisibilityClosure($action, $data['arguments'], $data['record']);
            $this->assertFalse($isVisible, "Action {$name} should be hidden for viewers");
        }
    }

    public function test_write_actions_are_visible_for_admin_when_state_permits()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->mockTicketCacheResponses();

        $actions = [
            'close_ticket' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
            'reopen_ticket' => ['record' => ['StateType' => 'closed'], 'arguments' => ['znuny_ticket_id' => 2]],
            'add_note_or_article' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
            'take_or_release_ticket' => ['record' => ['StateType' => 'open', 'Lock' => 'lock'], 'arguments' => ['znuny_ticket_id' => 1]],
            'change_assignment' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
        ];

        foreach ($this->getActions() as $action) {
            $name = $action->getName();
            $data = $actions[$name];

            $isVisible = $this->evaluateVisibilityClosure($action, $data['arguments'], $data['record']);
            $this->assertTrue($isVisible, "Action {$name} should be visible for admin when state permits");
        }
    }

    public function test_write_actions_are_visible_for_operator_when_state_permits()
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator);

        $this->mockTicketCacheResponses();

        $actions = [
            'close_ticket' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
            'reopen_ticket' => ['record' => ['StateType' => 'closed'], 'arguments' => ['znuny_ticket_id' => 2]],
            'add_note_or_article' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
            'take_or_release_ticket' => ['record' => ['StateType' => 'open', 'Lock' => 'lock'], 'arguments' => ['znuny_ticket_id' => 1]],
            'change_assignment' => ['record' => ['StateType' => 'open'], 'arguments' => ['znuny_ticket_id' => 1]],
        ];

        foreach ($this->getActions() as $action) {
            $name = $action->getName();
            $data = $actions[$name];

            $isVisible = $this->evaluateVisibilityClosure($action, $data['arguments'], $data['record']);
            $this->assertTrue($isVisible, "Action {$name} should be visible for operator when state permits");
        }
    }

    public function test_viewer_direct_execution_is_denied_before_downstream_services_are_invoked()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);

        $this->mock(ZnunyLinkedTicketCloseService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('closeTicket'));
        $this->mock(ZnunyLinkedTicketReopenService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reopenTicket'));
        $this->mock(ZnunyTicketArticleWriteService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createTicketArticle'));
        $this->mock(ZnunyAssignmentDependencyService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assignTicket'));
        $this->mock(ZnunyTicketWorkspaceTicketRefreshService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('refreshTicket'));

        foreach ($this->getActions() as $action) {
            $reflection = new ReflectionClass($action);
            $property = $reflection->getProperty('action');
            $property->setAccessible(true);
            $closure = $property->getValue($action);

            try {
                app()->call($closure, ['data' => [], 'arguments' => [], 'action' => $action]);
                $this->fail("Expected 403 exception for " . $action->getName());
            } catch (HttpException $e) {
                $this->assertEquals(403, $e->getStatusCode(), "Wrong status code for " . $action->getName());
            } catch (Cancel $e) {
                $this->fail("Expected 403 exception for " . $action->getName() . " but got Cancel");
            }
        }
    }

    public function test_executeCreateTicketArticle_independently_denies_viewer()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer);

        $this->mock(ZnunyTicketArticleWriteService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createTicketArticle');
        });

        $reflection = new ReflectionClass(ZnunyTicketManagementActions::class);
        $method = $reflection->getMethod('executeCreateTicketArticle');
        $method->setAccessible(true);

        try {
            $method->invoke(null, [], ['subject' => 's', 'body' => 'b'], ZnunyTicketManagementActions::addNoteOrArticleAction('add_note_or_article'), null, false);
            $this->fail('Expected 403 exception from executeCreateTicketArticle');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_actual_filament_component_action_invocation_is_denied_for_viewer()
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->mockTicketCacheResponses();

        $this->mock(ZnunyLinkedTicketCloseService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('closeTicket'));

        try {
            Livewire::actingAs($viewer)
                ->test(ZnunyTicketWorkspace::class)
                ->callAction(['viewTicket', 'manual_close_ticket'], arguments: ['znuny_ticket_id' => 101]);

            $this->fail('Expected action call to fail because the action is hidden from viewer');
        } catch (\PHPUnit\Framework\ExpectationFailedException $e) {
            $this->assertStringContainsString('Failed asserting that an action with name [viewTicket > manual_close_ticket] is visible', $e->getMessage());
        }
    }
}
