<?php

namespace App\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contract = 'contract';
    case Intern = 'intern';
    case Temporary = 'temporary';
    case Freelance = 'freelance';
}
