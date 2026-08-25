<?php

namespace Tests\Feature\Filament\Navigation;

use App\Filament\Pages\CreateTicket;
use App\Filament\Pages\ZnunyTicketWorkspace;
use App\Filament\Resources\ScheduledZnunyTasks\ScheduledZnunyTaskResource;
use Tests\TestCase;

class TicketWorkflowNavigationTest extends TestCase
{
    public function test_create_ticket_sorts_immediately_before_ticket_workspace()
    {
        $createTicketSort = CreateTicket::getNavigationSort();
        $ticketWorkspaceSort = ZnunyTicketWorkspace::getNavigationSort();

        $this->assertEquals(9, $createTicketSort);
        $this->assertEquals(10, $ticketWorkspaceSort);
        $this->assertLessThan($ticketWorkspaceSort, $createTicketSort);

        $this->assertEquals(
            CreateTicket::getNavigationGroup(),
            ZnunyTicketWorkspace::getNavigationGroup()
        );
    }

    public function test_scheduled_tasks_navigation_properties_remain_intact()
    {
        $this->assertEquals(30, ScheduledZnunyTaskResource::getNavigationSort());
        $this->assertNotNull(ScheduledZnunyTaskResource::getNavigationGroup());
    }

    public function test_mobile_separator_is_applied_to_scheduled_tasks()
    {
        $cssPath = resource_path('css/filament/admin/theme.css');
        $cssContent = file_get_contents($cssPath);

        // 5. targets Scheduled Tasks by route
        $this->assertMatchesRegularExpression('/\.fi-sidebar-item:has\(a\[href\*\="\/scheduled-znuny-tasks"\]\)/is', $cssContent);

        // 6. separator inside mobile breakpoint
        $this->assertMatchesRegularExpression('/@media\s*\(\s*max-width:\s*1023px\s*\)[^{]*\{[^\}]*\.fi-sidebar-item:has/is', $cssContent);

        // 7. margin, padding, border
        $this->assertStringContainsString('margin-top: 0.625rem;', $cssContent);
        $this->assertStringContainsString('padding-top: 0.625rem;', $cssContent);
        $this->assertStringContainsString('border-top: 1px solid', $cssContent);

        // 8. dark mode covered
        $this->assertMatchesRegularExpression('/dark[^\{]*\{[^\}]*border-top-color:/is', $cssContent);

        // 9. no nth-child
        $this->assertStringNotContainsString('nth-child', $cssContent);
    }
}
