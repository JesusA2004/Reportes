<?php

namespace App\Support;

class RowCleaner {

    public static function isEmpty(array $row): bool {
        foreach ($row as $value) {
            if (!is_null($value) && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

}
