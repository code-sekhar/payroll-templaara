<?php

namespace App\Services\Attendance;

use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;

final class AttendancePunchPairer
{
    /** @param list<AttendancePunch|EffectiveAttendancePunch> $punches */
    public function pair(array $punches): PunchPairingResult
    {
        $events = array_map(
            fn (AttendancePunch|EffectiveAttendancePunch $punch): EffectiveAttendancePunch => $punch instanceof EffectiveAttendancePunch
                ? $punch
                : EffectiveAttendancePunch::fromRaw($punch),
            $punches,
        );
        usort($events, function (EffectiveAttendancePunch $left, EffectiveAttendancePunch $right): int {
            return $left->deterministicSortKey() <=> $right->deterministicSortKey();
        });

        $pairs = [];
        $openCheckIn = null;

        foreach ($events as $punch) {
            if ($punch->punch_type === AttendancePunchType::CheckIn) {
                if ($openCheckIn !== null) {
                    return new PunchPairingResult($pairs, true, $openCheckIn);
                }

                $openCheckIn = $punch;

                continue;
            }

            if ($punch->punch_type !== AttendancePunchType::CheckOut || $openCheckIn === null) {
                return new PunchPairingResult($pairs, true, $openCheckIn);
            }

            $pairs[] = new PunchPair($openCheckIn, $punch);
            $openCheckIn = null;
        }

        return new PunchPairingResult($pairs, false, $openCheckIn);
    }
}
