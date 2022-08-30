<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface Syncable
{
    public function getGlobalIdentifierKeyName(): string;

    public function getGlobalIdentifierKey(): string|int;

    public function getCentralModelName(): string;

    public function getSyncedAttributeNames(): array;

    public function triggerSyncEvent(): void;
}
