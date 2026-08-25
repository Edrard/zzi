<?php

namespace App\Enums;

enum ScheduledZnunyTicketMarkerLookupStatus: string
{
    case NotFound = 'not_found';
    case Found = 'found';
    case Multiple = 'multiple';
    case Unavailable = 'unavailable';
}
