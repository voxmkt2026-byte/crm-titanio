<?php
declare(strict_types=1);
final class CallAudioRange
{
    public static function parse(?string $range, int $size): array
    {
        if ($size < 1) throw new RangeException('INVALID_RANGE');
        if ($range === null) return [0,$size-1,200];
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1]==='' && $m[2]==='')) throw new RangeException('INVALID_RANGE');
        if ($m[1]==='') {
            if ((int)$m[2]<1) throw new RangeException('INVALID_RANGE');
            return [max(0,$size-(int)$m[2]),$size-1,206];
        }
        $start=(int)$m[1]; $end=$m[2]==='' ? $size-1 : min($size-1,(int)$m[2]);
        if ($start>=$size || $start>$end) throw new RangeException('INVALID_RANGE');
        return [$start,$end,206];
    }
}
