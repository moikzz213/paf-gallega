<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class ManageApiKeys extends Command
{
    protected $signature = 'api-key:revoke
        {key? : The key to deactivate. Omit to list every key instead}';

    protected $description = 'List export-API keys, or deactivate one immediately.';

    public function handle(): int
    {
        $keyValue = $this->argument('key');

        if (! $keyValue) {
            $keys = ApiKey::with('user:id,name,email')->orderByDesc('id')->get();

            if ($keys->isEmpty()) {
                $this->info('No API keys have been issued. Create one with api-key:issue.');

                return self::SUCCESS;
            }

            $this->table(
                ['Key', 'Name', 'Issued for', 'Active', 'Last used', 'From'],
                $keys->map(fn (ApiKey $key) => [
                    $key->key,
                    $key->name,
                    $key->user?->email ?? '(user deleted)',
                    $key->is_active ? 'yes' : 'no',
                    $key->last_used_at?->diffForHumans() ?? 'never',
                    $key->last_used_ip ?? '—',
                ])->all(),
            );

            return self::SUCCESS;
        }

        $key = ApiKey::where('key', $keyValue)->first();

        if (! $key) {
            $this->error('No key with that value.');

            return self::FAILURE;
        }

        $key->update(['is_active' => false]);

        AuditLogger::log('api_key_revoked', "API key \"{$key->name}\" revoked");

        $this->info("Key \"{$key->name}\" is revoked and will be refused from now on.");

        return self::SUCCESS;
    }
}
