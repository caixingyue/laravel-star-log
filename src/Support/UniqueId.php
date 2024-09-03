<?php

namespace Caixingyue\LaravelStarLog\Support;

class UniqueId
{
    /**
     * Create a new Unique ID
     *
     * @param int|string $bit_num
     * @return int
     */
    public static function generate(int|string $bit_num): int
    {
        $max = pow(10, $bit_num) - 1;
        $min = pow(10, $bit_num - 1);
        return rand($min, $max);
    }
}
