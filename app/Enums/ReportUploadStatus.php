<?php

namespace App\Enums;

enum ReportUploadStatus: string {

    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';

}
