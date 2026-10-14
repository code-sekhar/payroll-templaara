<?php

namespace App\Services\Attendance;

use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use App\Models\AttendancePunch;
use App\Models\AttendancePunchCorrection;
use Carbon\CarbonImmutable;

/** A calculation event backed by raw evidence or an approved correction overlay. */
final readonly class EffectiveAttendancePunch
{
    public AttendancePunchType $punch_type;

    public CarbonImmutable $punched_at;

    public string $eventKind;

    public int $stableId;

    public function __construct(
        public ?AttendancePunch $rawPunch,
        public ?AttendancePunchCorrection $correction,
    ) {
        if (($rawPunch === null) === ($correction === null)) {
            throw new \InvalidArgumentException('An effective punch must have exactly one source.');
        }

        if ($rawPunch) {
            $this->punch_type = $rawPunch->punch_type;
            $this->punched_at = CarbonImmutable::instance($rawPunch->punched_at);
            $this->eventKind = 'raw';
            $this->stableId = (int) $rawPunch->getKey();

            return;
        }

        if (! $correction->effective_punch_type || ! $correction->effective_punched_at
            || $correction->action === AttendanceRegularizationItemAction::Remove) {
            throw new \InvalidArgumentException('A remove correction cannot produce an effective punch event.');
        }

        $this->punch_type = $correction->effective_punch_type;
        $this->punched_at = CarbonImmutable::instance($correction->effective_punched_at);
        $this->eventKind = 'correction';
        $this->stableId = (int) $correction->getKey();
    }

    public static function fromRaw(AttendancePunch $punch): self
    {
        return new self($punch, null);
    }

    public static function fromCorrection(AttendancePunchCorrection $correction): self
    {
        return new self(null, $correction);
    }

    public function deterministicSortKey(): array
    {
        return [
            ((int) $this->punched_at->format('U') * 1_000_000) + (int) $this->punched_at->format('u'),
            $this->eventKind === 'raw' ? 0 : 1,
            $this->stableId,
        ];
    }
}
