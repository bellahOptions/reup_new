<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Reports environment problems that cause confusing runtime failures.
 *
 * Two are worth knowing about:
 *
 *  1. **Process environment overriding .env.** Dotenv does not overwrite
 *     variables that already exist in the environment. A stale export — from a
 *     previous .env, a supervisor unit, or a shell profile — silently wins over
 *     .env. That is what produced "Connection could not be established with
 *     host mailhog" while .env said 127.0.0.1.
 *
 *  2. **SMTP encryption mismatch.** `config/mail.php` defaults `encryption` to
 *     'tls'. Local catch-all servers (Mailpit, Mailhog) do not implement
 *     STARTTLS and answer "502 5.5.1 Command not implemented".
 *
 * Usage: php artisan env:doctor
 */
class EnvDoctor extends Command
{
    protected $signature = 'env:doctor';

    protected $description = 'Report environment values that shadow .env, plus mail and provider configuration problems';

    public function handle(): int
    {
        $this->reportOverrides();
        $this->reportMail();
        $this->reportProviders();

        return self::SUCCESS;
    }

    private function reportOverrides(): void
    {
        $this->line('');
        $this->info('Environment overrides');

        $file = $this->envFile();

        if (! $file) {
            $this->warn('  .env not found; nothing to compare against.');

            return;
        }

        $conflicts = [];
        $noise = ['DSH_', 'PSModule', 'COMPUTERNAME', 'USERDOMAIN', 'PROCESSOR_', 'ProgramFiles', 'SystemRoot', 'windir'];

        foreach (getenv() as $key => $value) {
            foreach ($noise as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    continue 2;
                }
            }

            if (array_key_exists($key, $file) && (string) $value !== (string) $file[$key]) {
                $conflicts[$key] = [(string) $value, (string) $file[$key]];
            }
        }

        if (! $conflicts) {
            $this->line('  <fg=green>No process variables shadow .env.</>');

            return;
        }

        $this->line('  <fg=yellow>These are set in the environment and will be used INSTEAD of .env:</>');
        $this->table(['Variable', 'In environment', 'In .env'], array_map(
            fn ($key, $pair) => [$key, $pair[0], $pair[1]],
            array_keys($conflicts),
            $conflicts
        ));
        $this->line('  Restart the shell or supervisor that set these, then re-run.');
    }

    private function reportMail(): void
    {
        $this->line('');
        $this->info('Mail');

        $mailer = config('mail.default');
        $host = config('mail.mailers.smtp.host');
        $port = config('mail.mailers.smtp.port');
        $encryption = config('mail.mailers.smtp.encryption');
        $from = config('mail.from.address');

        $this->line("  mailer:     {$mailer}");
        $this->line("  host:       {$host}:{$port}");
        $this->line('  encryption: ' . var_export($encryption, true));
        $this->line('  from:       ' . var_export($from, true));

        if ($mailer === 'log') {
            $this->line('  <fg=green>Mail is written to storage/logs — nothing is sent.</>');

            return;
        }

        if ($encryption === 'tls' && in_array($port, [1025, 25, null], true)) {
            $this->line('  <fg=yellow>SMTP on 1025/25 with tls: local catch-all servers do not');
            $this->line('  implement STARTTLS and reply "502 Command not implemented".</>');
            $this->line('  Set <fg=green>MAIL_ENCRYPTION=null</> in .env.');
        }

        if ($from === null || $from === 'null') {
            $this->line('  <fg=yellow>MAIL_FROM_ADDRESS is null — set a real address.</>');
        }
    }

    private function reportProviders(): void
    {
        $this->line('');
        $this->info('Bill-payment providers');

        try {
            foreach (app(\App\Services\ProviderManager::class)->all() as $provider) {
                $state = $provider->isConfigured() ? '<fg=green>configured</>' : '<fg=yellow>not configured</>';
                $this->line(sprintf('  %-14s %s', $provider->label(), $state));
            }

            $order = implode(', ', (array) config('bills.provider_order', []));
            $this->line("  failover order: {$order}");
        } catch (\Throwable $e) {
            $this->warn('  Could not resolve providers: ' . $e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function envFile(): array
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            return [];
        }

        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value, " \t\"'");

            // Skip values that are themselves expansions, e.g.
            // MAIL_FROM_NAME="${APP_NAME}". The literal text is not the resolved
            // value, so comparing it against the environment is meaningless.
            if (str_contains($value, '${')) {
                continue;
            }

            $values[trim($key)] = $value;
        }

        return $values;
    }
}
