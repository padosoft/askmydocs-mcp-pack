<?php

namespace Padosoft\AskMyDocsMcpPack\Tasks;

enum TaskStatus: string
{
    case Working = 'working';
    case InputRequired = 'input_required';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
