<?php

namespace App\Services\Attendance;

use App\Models\AttendancePunch;
use InvalidArgumentException;

final readonly class PunchPair
{
    public int $workedMinutes;

    public EffectiveAttendancePunch $checkIn;

    public EffectiveAttendancePunch $checkOut;

    public function __construct(
        AttendancePunch|EffectiveAttendancePunch $checkIn,
        AttendancePunch|EffectiveAttendancePunch $checkOut,
    ) {
        $this->checkIn = $checkIn instanceof EffectiveAttendancePunch ? $checkIn : EffectiveAttendancePunch::fromRaw($checkIn);
        $this->checkOut = $checkOut instanceof EffectiveAttendancePunch ? $checkOut : EffectiveAttendancePunch::fromRaw($checkOut);

        $elapsedSeconds = $this->checkOut->punched_at->getTimestamp() - $this->checkIn->punched_at->getTimestamp();
        $elapsedMicroseconds = ($elapsedSeconds * 1_000_000)
            + ((int) $this->checkOut->punched_at->format('u') - (int) $this->checkIn->punched_at->format('u'));

        if ($elapsedMicroseconds < 0) {
            throw new InvalidArgumentException('A punch pair cannot end before it starts.');
        }

        $this->workedMinutes = intdiv($elapsedMicroseconds, 60_000_000);
    }
}
