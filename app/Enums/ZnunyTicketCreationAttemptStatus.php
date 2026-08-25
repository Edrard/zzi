<?php

namespace App\Enums;

enum ZnunyTicketCreationAttemptStatus: string
{
    case Preparing = 'preparing';
    case Sending = 'sending';
    case Success = 'success';
    case ConfirmedFailed = 'confirmed_failed';
    case Uncertain = 'uncertain';
    case Orphaned = 'orphaned';
    case Recovered = 'recovered';
    case ManuallyLinked = 'manually_linked';
    case ResolvedWithoutTicket = 'resolved_without_ticket';
}
