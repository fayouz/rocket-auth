<?php

namespace App\Ldap;

final class SyncReport
{
    public int $created = 0;
    public int $updated = 0;
    public int $disabled = 0;
    /** @var list<string> emails of local accounts not taken over by the directory */
    public array $conflicts = [];

    public function __construct(public readonly bool $dryRun = false)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'created' => $this->created,
            'updated' => $this->updated,
            'disabled' => $this->disabled,
            'conflicts' => $this->conflicts,
        ];
    }
}
