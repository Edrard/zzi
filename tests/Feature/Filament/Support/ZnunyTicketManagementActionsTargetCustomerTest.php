<?php

namespace Tests\Feature\Filament\Support;

use App\Filament\Support\ZnunyTicketManagementActions;
use App\Services\Znuny\ZnunyCachedLookupService;
use Mockery\MockInterface;
use Tests\TestCase;

class ZnunyTicketManagementActionsTargetCustomerTest extends TestCase
{
    public function test_default_options_contain_prewarmed_customers()
    {
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('TestQueue')
                ->once()
                ->andReturn(['user1' => 'User One', 'user2' => 'User Two']);

            $mock->shouldNotReceive('searchCustomerUserOptions');
        });

        $options = ZnunyTicketManagementActions::getTargetCustomerDefaultOptions('TestQueue', null);

        $this->assertEquals(['user1' => 'User One', 'user2' => 'User Two'], $options);
    }

    public function test_current_customer_is_included_when_absent_from_prewarm()
    {
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('TestQueue')
                ->once()
                ->andReturn(['user1' => 'User One']);

            $mock->shouldReceive('getCustomerUserLabel')
                ->with('user_current')
                ->once()
                ->andReturn('Current User Label');

            $mock->shouldNotReceive('searchCustomerUserOptions');
        });

        $options = ZnunyTicketManagementActions::getTargetCustomerDefaultOptions('TestQueue', 'user_current');

        $this->assertEquals([
            'user1' => 'User One',
            'user_current' => 'Current User Label',
        ], $options);
    }

    public function test_current_customer_is_not_duplicated_when_already_present()
    {
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('TestQueue')
                ->once()
                ->andReturn(['user1' => 'User One', 'user_current' => 'Current User Label Cache']);

            $mock->shouldNotReceive('getCustomerUserLabel');
            $mock->shouldNotReceive('searchCustomerUserOptions');
        });

        $options = ZnunyTicketManagementActions::getTargetCustomerDefaultOptions('TestQueue', 'user_current');

        $this->assertEquals([
            'user1' => 'User One',
            'user_current' => 'Current User Label Cache',
        ], $options);
    }

    public function test_current_customer_remains_available_with_raw_login_fallback_when_label_resolution_fails()
    {
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('TestQueue')
                ->once()
                ->andReturn(['user1' => 'User One']);

            $mock->shouldReceive('getCustomerUserLabel')
                ->with('user_current')
                ->once()
                ->andThrow(new \Exception('Label cache failed'));

            $mock->shouldNotReceive('searchCustomerUserOptions');
        });

        $options = ZnunyTicketManagementActions::getTargetCustomerDefaultOptions('TestQueue', 'user_current');

        $this->assertEquals([
            'user1' => 'User One',
            'user_current' => 'user_current',
        ], $options);
    }

    public function test_prewarm_read_failure_does_not_crash_and_current_customer_remains_available()
    {
        $this->mock(ZnunyCachedLookupService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getCustomerUserPrimaryOptionsForQueue')
                ->with('TestQueue')
                ->once()
                ->andThrow(new \Exception('Queue prewarm missing'));

            $mock->shouldReceive('getCustomerUserLabel')
                ->with('user_current')
                ->once()
                ->andReturn('Current User Label');

            $mock->shouldNotReceive('searchCustomerUserOptions');
        });

        $options = ZnunyTicketManagementActions::getTargetCustomerDefaultOptions('TestQueue', 'user_current');

        $this->assertEquals([
            'user_current' => 'Current User Label',
        ], $options);
    }
}
