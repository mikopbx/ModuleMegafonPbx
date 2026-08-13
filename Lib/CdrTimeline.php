<?php

declare(strict_types=1);

namespace Modules\ModuleMegafonPbx\Lib;

use DateTime;

final class CdrTimeline
{
    /**
     * Build all CDR timestamps from the same immutable point in time.
     * Duration already includes the waiting interval.
     */
    public static function fromStart(DateTime $start, int $wait, int $duration): array
    {
        $answer = (clone $start)->modify('+' . $wait . ' seconds');
        $end = (clone $start)->modify('+' . $duration . ' seconds');

        return [
            'start' => $start->format('Y-m-d H:i:s.u'),
            'answer' => $answer->format('Y-m-d H:i:s.u'),
            'endtime' => $end->format('Y-m-d H:i:s.u'),
        ];
    }
}
