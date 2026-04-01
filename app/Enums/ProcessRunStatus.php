<?php

namespace App\Enums;

enum ProcessRunStatus: string {

    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

}
