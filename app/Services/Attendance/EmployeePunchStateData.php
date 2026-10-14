<?php

namespace App\Services\Attendance;

final readonly class EmployeePunchStateData
{
    public function __construct(
        public string $state,
        public bool $canPunchIn,
        public bool $canPunchOut,
        public ?string $reasonCode,
        public ?string $lastPunchType,
        public ?string $lastPunchedAt,
        public string $timezone,
        public string $locationMode,
        public bool $locationAvailable,
        public ?string $lastLocationResult,
    ) {}

    /** @return array{state: string, canPunchIn: bool, canPunchOut: bool, reasonCode: ?string, lastPunchType: ?string, lastPunchedAt: ?string, timezone: string, locationMode: string, locationAvailable: bool, lastLocationResult: ?string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'canPunchIn' => $this->canPunchIn,
            'canPunchOut' => $this->canPunchOut,
            'reasonCode' => $this->reasonCode,
            'lastPunchType' => $this->lastPunchType,
            'lastPunchedAt' => $this->lastPunchedAt,
            'timezone' => $this->timezone,
            'locationMode' => $this->locationMode,
            'locationAvailable' => $this->locationAvailable,
            'lastLocationResult' => $this->lastLocationResult,
        ];
    }
}
