<?php

namespace Stancl\Tenancy\Contracts;

// todo add comments
interface Syncable
{
    public function getGlobalIdentifierKeyName(): string;
    public function getGlobalIdentifierKey(): string;
    public function getCentralModelName(): string;
    public function getSyncedAttributeNames(): array;
}