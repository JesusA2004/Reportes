<?php

namespace App\Enums;

enum ProcessType: string {

    case Import = 'import';
    case Match = 'match';
    case Consolidate = 'consolidate';
    case Export = 'export';

}
