<?php

namespace App\Enums;

enum ZnunyPrewarmRefreshResult: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case SKIPPED_LOCKED = 'skipped_locked';
}
