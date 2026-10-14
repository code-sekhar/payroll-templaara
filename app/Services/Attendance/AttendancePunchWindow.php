<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class AttendancePunchWindow
{
    public const PRE_SHIFT_BUFFER_HOURS = 6;

    public const POST_SHIFT_BUFFER_HOURS = 6;

    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public bool $includesEnd = true,
    ) {
        if ($end->getTimestamp() < $start->getTimestamp()) {
            throw new InvalidArgumentException('An attendance punch window cannot end before it starts.');
        }
    }

    public static function forDate(
        CarbonImmutable $attendanceDate,
        ?CarbonImmutable $expectedStart,
        ?CarbonImmutable $expectedEnd,
    ): self {
        if ($expectedStart && $expectedEnd) {
            return new self(
                $expectedStart->subHours(self::PRE_SHIFT_BUFFER_HOURS),
                $expectedEnd->addHours(self::POST_SHIFT_BUFFER_HOURS),
            );
        }

        $start = $attendanceDate->startOfDay();

        return new self($start, $start->addDay(), includesEnd: false);
    }

    public function cappedAt(CarbonImmutable $instant): self
    {
        if ($instant->getTimestamp() >= $this->end->getTimestamp()) {
            return $this;
        }

        if ($instant->getTimestamp() < $this->start->getTimestamp()) {
            return new self($this->start, $this->start);
        }

        return new self($this->start, $instant);
    }

    public function overlaps(self $other): bool
    {
        return $this->start <= $other->end && $other->start <= $this->end;
    }

    public function contains(CarbonImmutable $instant): bool
    {
        return $instant >= $this->start
            && ($this->includesEnd ? $instant <= $this->end : $instant < $this->end);
    }
}
