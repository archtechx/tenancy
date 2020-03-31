<?php

namespace Stancl\Tenancy\Traits;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait ProvidesDatabaseUser
{
    public function getDatabaseHost(): ?string
    {
        return $this->data['_tenancy_db_host'];
    }

    public function getDatabaseUrl(): ?string
    {
        return $this->data['_tenancy_db_url'];
    }

    public function getDatabaseUsername(): string
    {
        return $this->data['_tenancy_db_username'];
    }

    public function getDatabasePassword(): string
    {
        return $this->data['_tenancy_db_password'];
    }

    public function getDatabasePort(): ?string
    {
        return $this->data['_tenancy_db_port'] ?? null;
    }

    public function getDatabaseGrants(): array
    {
        return $this->data['_tenancy_db_grants'] ?? $this->config['tenancy.database.grants'];
    }

    public function generateDatabaseUsername(): string
    {
        return Str::random(16);
    }

    public function generateDatabasePassword(): string
    {
        return Hash::make(Str::random(16));
    }

    public function getDatabaseLink(): ?string
    {
        return $this->data['_tenancy_db_link'] ?? null;
    }
}
