<?php

namespace ZuqongTech\Kronos\Enums;

enum StepStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Skipped   = 'skipped';
}