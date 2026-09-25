<?php

namespace App\Enum;

enum UpdateRunStatus: string
{
    /** Script method: waiting for the scheduled task. */
    case Requested = 'requested';
    /** Script method: the update script is running. */
    case Running = 'running';
    /** Docker method: sent to Watchtower; succeeded once another version answers. */
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return \in_array($this, [self::Requested, self::Running, self::Started], true);
    }
}
