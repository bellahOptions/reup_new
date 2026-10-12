<?php

namespace App\Lifecycle;

use App\Models\LifecycleAutomation;
use App\Models\User;
use App\Services\AffiliateService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates an automation's conditions against one customer.
 *
 * ## The security boundary of this module
 *
 * Conditions are authored by administrators and stored as JSON. This class is what
 * turns them into a yes or no — and it does so by **looking each field up in an
 * allowlist of closures** rather than by building a query, a string, or anything that
 * could be evaluated.
 *
 * That means:
 *
 *   * a field name that is not on the list is *unknown*, not interpolated;
 *   * an operator that is not on the list is *unknown*, not executed;
 *   * a value is compared as a scalar, never used as a fragment of SQL;
 *   * there is no `eval`, no `call_user_func`, no dynamic method name, and no way to
 *     reach application code from a condition.
 *
 * The alternative — translating conditions into `whereRaw()` fragments — is how a
 * configuration screen becomes an injection point, and it is the specific thing the
 * brief forbids. Everything below is arithmetic on values this class fetched itself.
 *
 * ## Why values are resolved lazily
 *
 * Several fields (balance, counts, last transaction) require queries. Resolving only
 * the fields a condition actually names means a simple automation costs one query
 * instead of six, which matters when the sweep evaluates thousands of customers a
 * minute.
 */
class LifecycleConditionEvaluator
{
    /** @var array<int,array{field:string,operator:string,value:mixed}>|null */
    private ?array $loaded = null;

    /**
     * Which automation `$loaded` belongs to.
     *
     * The condition cache has to be keyed by automation, not just populated once. It
     * was a bare "have I loaded yet" flag, and the consequence was that evaluating a
     * *second* automation through the same instance returned the *first* automation's
     * conditions — so a message was scheduled or skipped on somebody else's criteria.
     *
     * In production this is mostly invisible: the container builds one instance per
     * resolved dependency, and the sweep processes one automation at a time. It is not
     * invisible when the same instance is reused — an admin testing two automations in
     * one request, or any future caller looping over automations — and "mostly
     * invisible" is not a property a condition about who gets emailed should have.
     */
    private ?int $loadedFor = null;

    private array $cache = [];

    public function __construct(private readonly AffiliateService $affiliates)
    {
    }

    /**
     * The fields a condition may reference.
     *
     * `type` drives both the operator list in the editor and how a value is coerced
     * before comparison, so a date is never compared as a string and a boolean is not
     * compared with `>`.
     *
     * @return array<string, array{label:string,type:string}>
     */
    public static function fields(): array
    {
        return [
            'account_age_days' => ['label' => 'Account age (days)', 'type' => 'number'],
            'email_verified' => ['label' => 'Email verified', 'type' => 'boolean'],
            'has_phone' => ['label' => 'Phone number on file', 'type' => 'boolean'],
            'account_status' => ['label' => 'Account status', 'type' => 'text'],
            'successful_fundings' => ['label' => 'Successful wallet fundings', 'type' => 'number'],
            'successful_transactions' => ['label' => 'Successful transactions', 'type' => 'number'],
            'last_transaction_days' => ['label' => 'Days since last transaction (blank if none)', 'type' => 'number'],
            'referral_count' => ['label' => 'Customers referred', 'type' => 'number'],
            'referral_qualified_count' => ['label' => 'Referrals that qualified for a reward', 'type' => 'number'],
            'referral_rewards_paid' => ['label' => 'Referral rewards paid', 'type' => 'number'],
            'wallet_balance_minor' => ['label' => 'Wallet balance (kobo)', 'type' => 'number'],
            'wallet_balance_naira' => ['label' => 'Wallet balance (naira)', 'type' => 'number'],
            'inactive_days' => ['label' => 'Days since last activity', 'type' => 'number'],
        ];
    }

    /** Operators, grouped by the field type they apply to. */
    public static function operators(): array
    {
        return [
            'equals' => 'is equal to',
            'not_equals' => 'is not equal to',
            'greater_than' => 'is greater than',
            'greater_or_equal' => 'is at least',
            'less_than' => 'is less than',
            'less_or_equal' => 'is at most',
            'is_empty' => 'is empty',
            'is_not_empty' => 'is not empty',
        ];
    }

    /** Operators that take no comparison value. */
    public static function valuelessOperators(): array
    {
        return ['is_empty', 'is_not_empty'];
    }

    /**
     * Does this customer satisfy the automation's conditions?
     *
     * An automation with no conditions matches everybody the trigger fires for —
     * which is the honest reading of "no conditions configured", not an accident.
     */
    public function matches(LifecycleAutomation $automation, User $user, array $payload = []): bool
    {
        $conditions = $this->loadConditions($automation);

        if ($conditions === []) {
            return true;
        }

        $results = [];

        foreach ($conditions as $condition) {
            $results[] = $this->evaluateOne($condition, $user, $payload);
        }

        // `or` needs any one; `and` needs all. Two branches rather than a fold so the
        // empty-conditions case above stays the only special case.
        return strtolower((string) $automation->condition_logic) === 'or'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * Human-readable explanations of why each condition did or did not match.
     *
     * Exposed for the automation tester: "this customer did not match" is not
     * actionable, and the specific field and value that failed is.
     *
     * @return array<int,array{field:string,operator:string,expected:mixed,actual:mixed,matched:bool}>
     */
    public function explain(LifecycleAutomation $automation, User $user, array $payload = []): array
    {
        $out = [];

        foreach ($this->loadConditions($automation) as $condition) {
            $actual = $this->value($condition['field'], $user, $payload);

            $out[] = [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'expected' => $condition['value'] ?? null,
                'actual' => $actual,
                'matched' => $this->evaluateOne($condition, $user, $payload),
            ];
        }

        return $out;
    }

    /**
     * Validate a submitted condition tree, returning the problems found.
     *
     * Called on save so a condition cannot be *stored* that cannot be evaluated. The
     * evaluator treats an unknown field as false, which is safe; storing one anyway
     * would mean a saved automation that silently never fires.
     *
     * @param  array<int,mixed>  $conditions
     * @return array<int,string>
     */
    public static function validate(array $conditions): array
    {
        $problems = [];
        $fields = self::fields();
        $operators = self::operators();

        foreach ($conditions as $index => $condition) {
            $position = $index + 1;

            if (! is_array($condition)) {
                $problems[] = "Condition {$position} is malformed.";

                continue;
            }

            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');

            if (! isset($fields[$field])) {
                $problems[] = "Condition {$position} uses an unknown field \"{$field}\".";
            }

            if (! isset($operators[$operator])) {
                $problems[] = "Condition {$position} uses an unknown operator \"{$operator}\".";
            }

            if ($operator !== '' && ! in_array($operator, self::valuelessOperators(), true)) {
                if (! array_key_exists('value', $condition) || $condition['value'] === '' || $condition['value'] === null) {
                    $problems[] = "Condition {$position} needs a value.";
                }
            }
        }

        return $problems;
    }

    /* =====================================================================
     | Internals
     |=================================================================== */

    /** @return array<int,array{field:string,operator:string,value:mixed}> */
    private function loadConditions(LifecycleAutomation $automation): array
    {
        $key = $automation->getKey();

        // Keyed by the automation. See `$loadedFor` for why a bare flag was wrong.
        if ($this->loaded !== null && $this->loadedFor === $key) {
            return $this->loaded;
        }

        $this->loadedFor = $key;

        $conditions = $automation->conditions;

        if (! is_array($conditions)) {
            return $this->loaded = [];
        }

        $valid = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');

            // Unknown field or operator: the condition cannot be evaluated, and a
            // condition that cannot be evaluated must not be treated as satisfied.
            if (! isset(self::fields()[$field]) || ! isset(self::operators()[$operator])) {
                return $this->loaded = [['field' => '__invalid__', 'operator' => 'equals', 'value' => true]];
            }

            $valid[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $condition['value'] ?? null,
            ];
        }

        return $this->loaded = $valid;
    }

    private function evaluateOne(array $condition, User $user, array $payload): bool
    {
        $field = $condition['field'];

        if ($field === '__invalid__') {
            return false;
        }

        $actual = $this->value($field, $user, $payload);
        $operator = $condition['operator'];
        $expected = $condition['value'] ?? null;

        if ($operator === 'is_empty') {
            return $actual === null || $actual === '' || $actual === 0;
        }

        if ($operator === 'is_not_empty') {
            return ! ($actual === null || $actual === '' || $actual === 0);
        }

        $type = self::fields()[$field]['type'] ?? 'text';

        // A null actual can only equal a null expectation; every ordering comparison
        // against "no value" is false rather than accidentally true.
        if ($actual === null) {
            return $operator === 'equals' ? $expected === null : ($operator === 'not_equals');
        }

        return match ($type) {
            'number' => $this->compareNumeric((float) $actual, $operator, (float) $expected),
            'boolean' => $this->compareBoolean((bool) $actual, $operator, filter_var($expected, FILTER_VALIDATE_BOOLEAN)),
            default => $this->compareText((string) $actual, $operator, (string) $expected),
        };
    }

    private function compareNumeric(float $actual, string $operator, float $expected): bool
    {
        return match ($operator) {
            'equals' => abs($actual - $expected) < 0.0001,
            'not_equals' => abs($actual - $expected) >= 0.0001,
            'greater_than' => $actual > $expected,
            'greater_or_equal' => $actual >= $expected,
            'less_than' => $actual < $expected,
            'less_or_equal' => $actual <= $expected,
            default => false,
        };
    }

    private function compareBoolean(bool $actual, string $operator, bool $expected): bool
    {
        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            // Ordering a boolean is meaningless, so it is refused rather than
            // coerced into a number and answered confidently.
            default => false,
        };
    }

    private function compareText(string $actual, string $operator, string $expected): bool
    {
        return match ($operator) {
            'equals' => strcasecmp($actual, $expected) === 0,
            'not_equals' => strcasecmp($actual, $expected) !== 0,
            default => false,
        };
    }

    /**
     * The current value of an allowlisted field.
     *
     * Every value is fetched here, by name, from a fixed set of queries. Nothing about
     * the *condition* reaches the database — only these predefined questions do.
     */
    private function value(string $field, User $user, array $payload): mixed
    {
        /*
         * The value cache is keyed by **customer** as well as field.
         *
         * It was keyed by field alone, which is correct only while the instance is used
         * for one customer — and this instance is exactly the kind that gets reused. The
         * effect would be the worst possible one: a condition evaluating against the
         * *previous* customer's balance or count, so a reminder meant for somebody who
         * has not funded goes to somebody who has. Caching is per customer or it is not
         * done at all.
         */
        $key = $user->getKey() . ':' . $field;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = match ($field) {
            'account_age_days' => (int) $user->created_at?->diffInDays(now()),
            'email_verified' => $user->email_verified_at !== null,
            'has_phone' => filled($user->phone),
            'account_status' => (string) ($user->status ?? 'active'),
            'successful_fundings' => $this->countFundings($user),
            'successful_transactions' => $this->countTransactions($user),
            'last_transaction_days' => $this->lastTransactionDays($user),
            'referral_count' => $this->affiliates->invitedCount($user),
            'referral_qualified_count' => $this->affiliates->qualifiedCount($user),
            'referral_rewards_paid' => $this->rewardsPaid($user),
            'wallet_balance_minor' => $this->balanceMinor($user),
            'wallet_balance_naira' => $this->balanceMinor($user) / 100,
            'inactive_days' => $this->inactiveDays($user),
            // A trigger payload key is readable only when the trigger supplied it, so
            // a condition on `amount_minor` is false for an event that has no amount
            // rather than accidentally matching a zero.
            default => array_key_exists($field, $payload) ? $payload[$field] : null,
        };

        return $this->cache[$key] = $value;
    }

    private function countFundings(User $user): int
    {
        return DB::table('transactions')
            ->where('user_id', $user->getKey())
            ->where('service_type', 'funding')
            ->where('status', 'success')
            ->where('type', 'credit')
            ->count();
    }

    private function countTransactions(User $user): int
    {
        return DB::table('transactions')
            ->where('user_id', $user->getKey())
            ->where('type', 'debit')
            ->where('status', 'success')
            ->where('service_type', '!=', 'fee')
            ->count();
    }

    private function lastTransactionDays(User $user): ?int
    {
        $last = DB::table('transactions')
            ->where('user_id', $user->getKey())
            ->where('type', 'debit')
            ->where('status', 'success')
            ->where('service_type', '!=', 'fee')
            ->max('created_at');

        return $last === null ? null : (int) \Illuminate\Support\Carbon::parse($last)->diffInDays(now());
    }

    private function rewardsPaid(User $user): int
    {
        return DB::table('referrals')->where('referrer_id', $user->getKey())->whereNotNull('paid_at')->count();
    }

    private function balanceMinor(User $user): int
    {
        $balance = DB::table('wallets')->where('user_id', $user->getKey())->value('balance');

        return Money::fromDatabase($balance)->minor();
    }

    private function inactiveDays(User $user): int
    {
        $last = $user->last_activity_at ?? $user->last_login_at ?? $user->created_at;

        return $last === null ? 0 : (int) $last->diffInDays(now());
    }
}
