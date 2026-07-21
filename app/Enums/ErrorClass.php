<?php

namespace App\Enums;

enum ErrorClass: string
{
    case Transient = 'transient';
    case Permanent = 'permanent';
}
