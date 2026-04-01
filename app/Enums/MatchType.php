<?php

namespace App\Enums;

enum MatchType: string {

    case Exact = 'exact';
    case Normalized = 'normalized';
    case Manual = 'manual';
    case Unmatched = 'unmatched';

}
