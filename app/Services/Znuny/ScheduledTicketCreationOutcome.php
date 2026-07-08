<?php

namespace App\Services\Znuny;

enum ScheduledTicketCreationOutcome: string
{
    case SUCCESS = 'success';
    case NOT_SENT = 'not_sent';
    case FAILED = 'failed';
    case UNCERTAIN = 'uncertain';
}
