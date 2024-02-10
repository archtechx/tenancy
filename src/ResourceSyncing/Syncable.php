<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

interface Syncable
{
    public function getGlobalIdentifierKeyName(): string;

    public function getGlobalIdentifierKey(): string|int;

    public function getCentralModelName(): string;

    public function getSyncedAttributeNames(): array;

    public function triggerSyncEvent(): void;

    /**
     * Get the attributes used for creating the *other* model (i.e. tenant if this is the central one, and central if this is the tenant one).
     *
     * You can also specify the default values for the attributes.
     *
     * E.g. return [
     *      'attribute',
     *      'attribute2' => 'default value',
     * ];
     *
     * In the ResourceSyncing trait, this method defaults to getSyncedAttributeNames().
     *
     * Note: These values are *merged into* getSyncedAttributeNames().
     */
    public function getCreationAttributes(): array;

    public function shouldSync(): bool;
}
