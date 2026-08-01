<?php

namespace Tests\Feature\Filament\Support;

use App\Filament\Support\ZnunyTicketManagementActions;
use App\Models\AuditLog;
use App\Services\Znuny\ZnunyAssignmentDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class ZnunyTicketManagementActionsAssignableQueuesFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_options_on_success()
    {
        $this->mock(ZnunyAssignmentDependencyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueueOptionsForOwnerLogin')
                ->once()
                ->with('some_owner')
                ->andReturn(['q1' => 'Queue 1', 'q2' => 'Queue 2']);
        });

        session()->forget('filament.notifications');

        $closure = $this->extractTargetQueueOptionsClosure();

        $get = function ($field) {
            if ($field === 'target_owner') {
                return 'some_owner';
            }

            return null;
        };

        $result = app()->call($closure, ['arguments' => [], 'record' => null, 'get' => $get]);

        $this->assertEquals(['q1' => 'Queue 1', 'q2' => 'Queue 2'], $result);
        $this->assertEmpty(session()->get('filament.notifications'));

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'znuny.connection_failed',
        ]);
    }

    public function test_it_handles_connection_exception_with_curl_code_and_notifies_and_logs_once_with_redaction()
    {
        $url = 'https://znuny.test/Agent/123/AssignableQueues?SessionID=TEST_SECRET_SESSION';
        $exceptionMessage = "cURL error 28: Resolving timed out after 10000 milliseconds for {$url}";
        $exception = new ConnectionException($exceptionMessage, 0, null);

        $this->mock(ZnunyAssignmentDependencyService::class, function (MockInterface $mock) use ($exception) {
            $mock->shouldReceive('getQueueOptionsForOwnerLogin')
                ->twice()
                ->with('some_owner')
                ->andThrow($exception);
        });

        session()->forget('filament.notifications');

        $closure = $this->extractTargetQueueOptionsClosure();

        $get = function ($field) {
            return 'some_owner';
        };

        $result1 = app()->call($closure, ['arguments' => ['znuny_ticket_id' => 123], 'record' => null, 'get' => $get]);
        $this->assertEquals([], $result1);

        $result2 = app()->call($closure, ['arguments' => ['znuny_ticket_id' => 123], 'record' => null, 'get' => $get]);
        $this->assertEquals([], $result2);

        $notifications = session()->get('filament.notifications');
        $this->assertNotNull($notifications, 'No notifications found in session');
        $this->assertCount(1, $notifications);

        $notification = $notifications[0];
        $this->assertEquals(__('znuny_ticket_workspace.management_actions.queues_load_failed_title'), $notification['title']);
        $this->assertEquals(__('znuny_ticket_workspace.management_actions.queues_load_failed_body'), $notification['body']);
        $this->assertEquals('danger', $notification['status']);

        $serializedNotification = json_encode($notification, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('TEST_SECRET_SESSION', $serializedNotification);
        $this->assertStringNotContainsString('SessionID', $serializedNotification);
        $this->assertStringNotContainsString($url, $serializedNotification);
        $this->assertStringNotContainsString($exceptionMessage, $serializedNotification);

        $logs = AuditLog::where('action', 'znuny.connection_failed')->get();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertEquals('znuny.connection_failed', $log->action);
        $this->assertEquals('ZnunyTicket', $log->entity_type);
        $this->assertEquals('123', $log->entity_id);

        $context = $log->context;
        $this->assertEquals('agent_assignable_queues', $context['operation']);
        $this->assertEquals('change_assignment', $context['context']);
        $this->assertEquals(123, $context['ticket_id']);
        $this->assertNull($context['local_record_id']);
        $this->assertEquals('some_owner', $context['agent_login']);
        $this->assertEquals('ConnectionException', $context['exception']);
        $this->assertEquals('connection_timeout', $context['category']);
        $this->assertEquals('/Agent/{AgentID}/AssignableQueues', $context['path']);
        $this->assertIsInt($context['curl_code']);
        $this->assertEquals(28, $context['curl_code']);

        $serializedContext = json_encode($context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('TEST_SECRET_SESSION', $serializedContext);
        $this->assertStringNotContainsString('SessionID', $serializedContext);
        $this->assertStringNotContainsString('?SessionID=', $serializedContext);
        $this->assertStringNotContainsString($url, $serializedContext);
        $this->assertStringNotContainsString($exceptionMessage, $serializedContext);
    }

    public function test_it_handles_connection_exception_without_parsable_curl_code()
    {
        $exceptionMessage = 'Connection refused by server without curl code';
        $exception = new ConnectionException($exceptionMessage, 0, null);

        $this->mock(ZnunyAssignmentDependencyService::class, function (MockInterface $mock) use ($exception) {
            $mock->shouldReceive('getQueueOptionsForOwnerLogin')
                ->once()
                ->with('some_owner')
                ->andThrow($exception);
        });

        session()->forget('filament.notifications');

        $closure = $this->extractTargetQueueOptionsClosure();

        $get = function ($field) {
            return 'some_owner';
        };

        $result = app()->call($closure, ['arguments' => ['znuny_ticket_id' => 124], 'record' => null, 'get' => $get]);
        $this->assertEquals([], $result);

        $logs = AuditLog::where('action', 'znuny.connection_failed')->get();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $context = $log->context;
        $this->assertArrayNotHasKey('curl_code', $context);
    }

    public function test_it_propagates_unrelated_exceptions()
    {
        $this->mock(ZnunyAssignmentDependencyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getQueueOptionsForOwnerLogin')
                ->once()
                ->andThrow(new RuntimeException('Something else broke'));
        });

        session()->forget('filament.notifications');

        $closure = $this->extractTargetQueueOptionsClosure();

        $get = function ($field) {
            return 'some_owner';
        };

        try {
            app()->call($closure, ['arguments' => [], 'record' => null, 'get' => $get]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertEquals('Something else broke', $e->getMessage());
        }

        $this->assertEmpty(session()->get('filament.notifications'));

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'znuny.connection_failed',
        ]);
    }

    public function test_translation_contracts()
    {
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('en');
            $this->assertEquals('Failed to load queues', __('znuny_ticket_workspace.management_actions.queues_load_failed_title'));
            $this->assertEquals('Could not retrieve assignable queues from Znuny. Please try again later.', __('znuny_ticket_workspace.management_actions.queues_load_failed_body'));

            app()->setLocale('uk');
            $this->assertEquals('Не вдалося завантажити черги', __('znuny_ticket_workspace.management_actions.queues_load_failed_title'));
            $this->assertEquals('Не вдалося отримати доступні черги зі Znuny. Спробуйте ще раз пізніше.', __('znuny_ticket_workspace.management_actions.queues_load_failed_body'));
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    private function extractTargetQueueOptionsClosure(): \Closure
    {
        $action = ZnunyTicketManagementActions::changeAssignmentAction('change_assignment');

        $reflection = new ReflectionClass($action);
        $property = $reflection->getProperty('schema');
        $property->setAccessible(true);
        $formValue = $property->getValue($action);

        $components = app()->call($formValue, ['arguments' => [], 'record' => null]);

        $targetQueueComponent = collect($components)->first(fn ($c) => $c->getName() === 'target_queue');
        $this->assertNotNull($targetQueueComponent);

        $reflectionComponent = new ReflectionClass($targetQueueComponent);
        while ($reflectionComponent) {
            if ($reflectionComponent->hasProperty('options')) {
                $optionsProperty = $reflectionComponent->getProperty('options');
                $optionsProperty->setAccessible(true);

                return $optionsProperty->getValue($targetQueueComponent);
            }
            $reflectionComponent = $reflectionComponent->getParentClass();
        }

        throw new \Exception('Could not find options property');
    }
}
