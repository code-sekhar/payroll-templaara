<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

final readonly class PunchPairingResult
{
    /** @param list<PunchPair> $pairs */
    public function __construct(
        public array $pairs,
        public bool $isMalformed,
        public ?EffectiveAttendancePunch $openCheckIn,
    ) {}

    public function isComplete(): bool
    {
        return ! $this->isMalformed && $this->openCheckIn === null;
    }

    public function workedMinutes(): int
    {
        return array_sum(array_map(fn (PunchPair $pair): int => $pair->workedMinutes, $this->pairs));
    }

    public function firstCheckIn(): ?CarbonImmutable
    {
        return $this->pairs[0]->checkIn->punched_at ?? $this->openCheckIn?->punched_at;
    }

    public function lastCheckOut(): ?CarbonImmutable
    {
        return $this->pairs === [] ? null : $this->pairs[array_key_last($this->pairs)]->checkOut->punched_at;
    }
}
