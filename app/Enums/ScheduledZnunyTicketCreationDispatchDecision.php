<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduledZnunyTicketCreationDispatchDecision: string
{
    case Proceed = 'proceed';
    case ReuseConfirmed = 'reuse_confirmed';
    case BlockUncertain = 'block_uncertain';
}
