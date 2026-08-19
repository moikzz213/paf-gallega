<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class IssueApiKey extends Command
{
    protected $signature = 'api-key:issue
        {name : A label for the key, e.g. "Finance Excel dashboard"}
        {email : The user whose data visibility the key carries}';

    protected $description = 'Issue a key/secret pair for the export API. The secret is shown once.';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user with the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error("{$user->name} is deactivated, so a key issued for them would not work.");

            return self::FAILURE;
        }

        ['key' => $key, 'secret' => $secret] = ApiKey::issue($this->argument('name'), $user);

        AuditLogger::log(
            'api_key_issued',
            "API key \"{$key->name}\" issued for {$user->name} ({$user->email})",
        );

        $this->newLine();
        $this->info("API key \"{$key->name}\" issued for {$user->name} ({$user->role}).");
        $this->newLine();
        $this->line('  key    : '.$key->key);
        $this->line('  secret : '.$secret);
        $this->newLine();
        $this->warn('The secret is not stored and cannot be shown again. Copy it now.');
        $this->newLine();
        $this->line('Report URL for Excel / Power Query:');
        $this->line('  '.url("/api/export-report?key={$key->key}&secret={$secret}"));
        $this->newLine();
        $this->comment('The key sees exactly what '.$user->name.' sees in Reports. Revoke with: php artisan api-key:revoke '.$key->key);

        return self::SUCCESS;
    }
}
