<?php

namespace Stancl\Tenancy\Contracts;

interface Tenant
{
    public function getTenantKeyName(): string;
    public function getTenantKey(): string;
}