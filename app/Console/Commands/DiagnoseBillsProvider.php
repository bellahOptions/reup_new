<?php

namespace App\Console\Commands;

use App\Services\ClubKonnectService;
use App\Services\ProviderBalanceService;
use App\Services\ProviderHealthService;
use App\Services\ProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Diagnose why a bill-vending provider is not working.
 *
 * ## Why the dashboard's "Attention" is not enough to act on
 *
 * ClubKonnect reaches the application through several layers, and each can fail
 * independently while producing the same one-word status:
 *
 *   1. credentials absent, or present but rejected;
 *   2. the endpoint the service actually calls being unreachable (note that the
 *      balance enquiry goes to `nellobytesystems.com`, **not** to
 *      `CLUBKONNECT_BASE_URL` — the two are configured separately and only one of
 *      them is used for this);
 *   3. the upstream answering with `200 OK` and an error *body*, which
 *      `Http::successful()` accepts, so a rejected key looks like a successful
 *      call;
 *   4. the provider's health probe having marked it down, so routing skips it
 *      even when it would work.
 *
 * This walks those in order and prints what each layer says. It never prints a
 * credential — only whether one is present, and its length, which is enough to
 * spot a truncated paste.
 */
class DiagnoseBillsProvider extends Command
{
    protected $signature = 'bills:diagnose
                            {--provider=clubkonnect : Which provider to diagnose}
                            {--raw : Also show the first 400 characters of the upstream body, credentials redacted}';

    protected $description = 'Diagnose why a bill-vending provider (ClubKonnect, Pairgate) is not working';

    public function handle(ClubKonnectService $clubKonnect): int
    {
        $provider = (string) $this->option('provider');

        // `provider_order` is a list, not a string — and a diagnostic that dies
        // on its own first line is worse than no diagnostic.
        $order = config('bills.provider_order', []);
        $order = is_array($order) ? implode(', ', $order) : (string) $order;

        $this->line('Provider: <options=bold>' . $provider . '</>');
        $this->line('Routing order: ' . ($order !== '' ? $order : '<fg=yellow>(unset)</>'));
        $this->newLine();

        // ---------------------------------------------------------------
        // 1. Configuration
        // ---------------------------------------------------------------
        $this->line('<options=bold>1. Configuration</>');

        $clientId = (string) config('services.clubkonnect.client_id');
        $apiKey = (string) config('services.clubkonnect.api_key');

        $this->line('   CLUBKONNECT_CLIENT_ID  ' . $this->describe($clientId));
        $this->line('   CLUBKONNECT_API_KEY    ' . $this->describe($apiKey));
        $this->line('   CLUBKONNECT_BASE_URL   ' . (string) config('services.clubkonnect.base_url'));
        $this->line('   <fg=gray>note: the balance enquiry does not use BASE_URL — see step 2.</>');

        if ($clientId === '' || $apiKey === '') {
            $this->newLine();
            $this->error('   A credential is missing. The provider cannot be used, and routing skips it.');

            return self::FAILURE;
        }

        $this->line('   isConfigured(): ' . ($clubKonnect->isConfigured() ? 'true' : 'false'));

        $this->line('   Balance endpoint: https://www.nellobytesystems.com/APIWalletBalanceV1.asp');
        $this->line('   <fg=gray>The step below is also the reachability test, so the host is not probed</>');
        $this->line('   <fg=gray>separately: a bare request answers differently from the API call itself, and an</>');
        $this->line('   <fg=gray>"unreachable" there — a transient DNS miss, say — would contradict the call</>');
        $this->line('   <fg=gray>that actually matters. One question, asked once.</>');

        // ---------------------------------------------------------------
        // 2. The real call, and what the body says
        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>2. Balance enquiry (the same call the health probe makes)</>');

        try {
            $endpoint = 'https://www.nellobytesystems.com/APIWalletBalanceV1.asp';

            /*
             * Through the service, not a bare HTTP call.
             *
             * The service normalises the balance (the upstream sends
             * `"4,985.28"`, a display string), so a raw call here would report
             * an error on a response the application handles correctly — a
             * diagnostic that contradicts the application is worse than none.
             * The raw body is fetched alongside it only so the tool can show
             * what the provider actually said.
             */
            $normalised = $clubKonnect->checkBalance(30);

            $rawResponse = Http::timeout(30)->get($endpoint, [
                'UserID' => $clientId,
                'APIKey' => $apiKey,
            ]);

            $body = (string) $rawResponse->body();

            $this->line('   HTTP ' . $rawResponse->status()
                . '  content-type: ' . ($rawResponse->header('Content-Type') ?: '(none)')
                . '  bytes: ' . strlen($body));

            $decoded = json_decode($body, true);
            $this->line('   valid JSON: ' . (is_array($decoded) ? 'yes' : '<fg=yellow>no</>'));

            if (is_array($decoded)) {
                // The balance endpoint answers 200 with an error body for a bad
                // key, so the *body* is the verdict, not the status code.
                foreach (['status', 'balance', 'message', 'UserID', 'APIKey'] as $key) {
                    if (array_key_exists($key, $decoded)) {
                        $value = $decoded[$key];

                        if (in_array($key, ['UserID', 'APIKey'], true)) {
                            $this->line('   body.' . $key . ' = <fg=gray>[echoed back, redacted]</>');

                            continue;
                        }

                        $this->line('   body.' . $key . ' = ' . $this->redact((string) $value, $clientId, $apiKey));
                    }
                }
            } else {
                $this->line('   the provider did not answer with JSON');
            }

            // The verdict comes from the same value every caller uses.
            $balance = is_array($normalised) ? ($normalised['balance'] ?? null) : null;

            if (is_numeric($balance)) {
                $this->newLine();
                $this->info('   WORKING — balance ₦' . number_format((float) $balance, 2));

                if (isset($normalised['balance_formatted'])) {
                    $this->line('   <fg=gray>(the provider sent it as "' . $normalised['balance_formatted']
                        . '"; the app normalises it, which is what made this work.)</>');
                }

                return $this->afterSuccess();
            }

            $this->newLine();
            $this->error('   No usable balance in the response: '
                . (is_array($normalised) ? ($normalised['message'] ?? '(no message)') : '(not an array)'));

            if ($this->option('raw')) {
                $this->newLine();
                $this->line('   body (first 400 chars, redacted):');
                $this->line('   ' . Str::limit($this->redact($body, $clientId, $apiKey), 400));
            }
        } catch (Throwable $e) {
            $this->error('   Could not reach the endpoint: ' . $this->redact($e->getMessage(), $clientId, $apiKey));
        }

        // ---------------------------------------------------------------
        // 3. What the health probe concludes, and whether routing skips it
        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>3. Health and routing</>');

        try {
            $manager = app(ProviderManager::class);
            $balances = app(ProviderBalanceService::class);
            $health = app(ProviderHealthService::class);

            foreach ($manager->all() as $candidate) {
                if (! $candidate->supports('airtime')) {
                    continue;
                }

                $this->line('   ' . str_pad($candidate->label(), 16)
                    . ' configured=' . ($candidate->isConfigured() ? 'yes' : 'no')
                    . '  ping=' . ($candidate->ping() ? 'ok' : '<fg=red>FAIL</>')
                    . '  marked available=' . ($health->isAvailable($candidate) ? 'yes' : '<fg=red>no</>')
                    . '  float covers ₦100=' . ($balances->canCover($candidate, 100.0) ? 'yes' : '<fg=red>no</>'));
            }

            $candidates = $manager->candidates('airtime');

            $this->newLine();
            $this->line('   Routing would try, in order: '
                . (count($candidates) ? implode(', ', array_map(fn ($c) => $c->label(), $candidates)) : '<fg=red>nothing</>'));

            if ($candidates === []) {
                $this->error('   No provider would be tried, so every purchase is refused before it is attempted.');
                $this->line('   That is the behaviour this command exists to explain: the customer sees');
                $this->line('   "temporarily unavailable" and no money leaves their wallet.');
            }
        } catch (Throwable $e) {
            $this->warn('   Could not evaluate routing: ' . $e->getMessage());
        }

        return self::FAILURE;
    }

    private function afterSuccess(): int
    {
        $this->line('   The provider itself is fine. If the dashboard still shows Attention, the');
        $this->line('   cached health/balance result has not expired yet — both are cached for a');
        $this->line('   couple of minutes, and the scheduled probe refreshes them.');

        return self::SUCCESS;
    }

    private function describe(string $value): string
    {
        if ($value === '') {
            return '<fg=red>MISSING</>';
        }

        return 'present (' . strlen($value) . ' chars)';
    }

    private function redact(string $text, string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return (string) preg_replace('~(APIKey|UserID)=[^&\s\'"]+~i', '$1=[redacted]', $text);
    }
}
