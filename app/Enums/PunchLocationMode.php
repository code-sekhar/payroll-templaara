<?php

namespace App\Enums;

enum PunchLocationMode: string
{
    case Disabled = 'disabled';
    case Capture = 'capture';
    case Required = 'required';
    case GeofenceRequired = 'geofence_required';
}
