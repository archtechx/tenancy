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

    /**
     * Get the attributes used for creating the *other* model (i.e. tenant if this is the central one, and central if this is the tenant one).
     */
    public function getSyncedCreationAttributes(): array|null; // todo come up with a better name
}
