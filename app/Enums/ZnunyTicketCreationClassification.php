<?php

namespace App\Enums;

enum ZnunyTicketCreationClassification: string
{
    case NotSent = 'not_sent';
    case Success = 'success';
    case ConfirmedFailed = 'confirmed_failed';
    case Uncertain = 'uncertain';
}
