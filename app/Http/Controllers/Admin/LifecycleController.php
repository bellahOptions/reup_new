<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Lifecycle\HtmlSanitizer;
use App\Lifecycle\LifecycleConditionEvaluator;
use App\Lifecycle\LifecycleDispatcher;
use App\Lifecycle\LifecycleScheduler;
use App\Lifecycle\LifecycleTrigger;
use App\Lifecycle\LifecycleVariables;
use App\Models\AdminLog;
use App\Models\LifecycleAutomation;
use App\Models\LifecycleConsent;
use App\Models\LifecycleEmailTemplate;
use App\Models\LifecycleMessage;
use App\Models\LifecycleSettings;
use App\Models\LifecycleSuppression;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The Customer Lifecycle Messaging module.
 *
 * ## Authorisation
 *
 * Four permissions, applied at the route and re-checked here:
 *
 *   * `view_lifecycle` — the overview, templates, automations and delivery log;
 *   * `manage_lifecycle` — writing them, and the marketing kill switch;
 *   * `send_lifecycle` — a test send, and retrying a failed delivery.
 *
 * The route middleware is the gate; the checks here exist because a route registered
 * without its middleware would otherwise be a template-editing endpoint, and a
 * template is content that goes to every customer. Same reasoning as `PricingController`.
 *
 * ## Everything the customer will read is validated before it is stored
 *
 * Template HTML is sanitised on save rather than on send, so what an author previews
 * is what a customer receives. CTA URLs are validated by scheme. Condition fields and
 * operators are checked against the evaluator's allowlists, so a condition that cannot
 * be evaluated can never be *stored* — an ignored condition would silently widen an
 * audience, which is the worst failure this module can have.
 */
class LifecycleController extends Controller
{
    public function __construct(
        private readonly LifecycleScheduler $scheduler,
        private readonly LifecycleDispatcher $dispatcher,
        private readonly LifecycleVariables $variables,
        private readonly LifecycleConditionEvaluator $conditions,
    ) {
    }

    /* =====================================================================
     | Overview
     |==================================================================== */

    public function index()
    {
        return view('admin.lifecycle.index', [
            'snapshot' => $this->scheduler->snapshot(),
            'bounceRate' => $this->scheduler->bounceRate(),
            'marketingEnabled' => LifecycleSettings::marketingEnabled(),
            'recent' => LifecycleMessage::with(['automation', 'user'])
                ->latest('id')
                ->limit(15)
                ->get(),
            'automations' => LifecycleAutomation::with('template')->orderByDesc('id')->limit(10)->get(),
            'suppressedCount' => LifecycleSuppression::count(),
            'optedOutCount' => LifecycleConsent::where('marketing_opt_in', false)->count(),
        ]);
    }

    /**
     * The global marketing kill switch.
     *
     * Marketing only, and that separation is enforced in the dispatcher rather than
     * here: pausing marketing must never silence a receipt, a funding confirmation or
     * a security alert, and the way to guarantee that is for the check to live where
     * the category is known rather than in a switch that disables everything.
     */
    public function toggleMarketing(Request $request)
    {
        $this->authoriseManage();

        $validated = $request->validate(['enabled' => 'required|boolean']);

        LifecycleSettings::setMarketingEnabled((bool) $validated['enabled'], (int) Auth::id());

        AdminLog::log(Auth::id(), 'lifecycle_marketing_toggled', [
            'enabled' => (bool) $validated['enabled'],
        ]);

        return back()->with('success', $validated['enabled']
            ? 'Marketing email resumed. Transactional and security email was never paused.'
            : 'Marketing email paused. Receipts, funding confirmations and security email continue.');
    }

    /* =====================================================================
     | Templates
     |==================================================================== */

    public function templates()
    {
        return view('admin.lifecycle.templates', [
            'templates' => LifecycleEmailTemplate::withCount('versions')->orderBy('name')->get(),
            'variables' => LifecycleVariables::allowed(),
        ]);
    }

    public function createTemplate()
    {
        $this->authoriseManage();

        return view('admin.lifecycle.template-form', [
            'template' => new LifecycleEmailTemplate([
                'category' => LifecycleEmailTemplate::CATEGORY_MARKETING,
                'status' => LifecycleEmailTemplate::STATUS_DRAFT,
            ]),
            'variables' => LifecycleVariables::allowed(),
        ]);
    }

    public function editTemplate(LifecycleEmailTemplate $template)
    {
        return view('admin.lifecycle.template-form', [
            'template' => $template,
            'variables' => LifecycleVariables::allowed(),
            'versions' => $template->versions()->limit(10)->get(),
        ]);
    }

    public function storeTemplate(Request $request, ?LifecycleEmailTemplate $template = null)
    {
        $this->authoriseManage();

        $validated = $this->validateTemplate($request);

        $isNew = $template === null;

        if ($isNew) {
            $template = new LifecycleEmailTemplate(['created_by' => (int) Auth::id()]);
        }

        $before = $isNew ? null : $template->only(['subject', 'html_body', 'category', 'status']);

        $template->fill([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'preview_text' => $validated['preview_text'] ?? null,
            // Sanitised on save, not on send: what the author previews is what the
            // customer receives, and a dangerous tag never reaches the database.
            'html_body' => HtmlSanitizer::clean($validated['html_body']),
            'text_body' => $validated['text_body'] ?? null,
            'cta_buttons' => $this->buttons($validated),
            'category' => $validated['category'],
            'from_name' => $validated['from_name'] ?? null,
            'from_address' => $validated['from_address'] ?? null,
        ]);

        $template->save();

        $template->recordVersion($isNew ? 'Created' : 'Edited', (int) Auth::id());

        AdminLog::log(Auth::id(), $isNew ? 'lifecycle_template_created' : 'lifecycle_template_updated', [
            'template_key' => $template->key,
            'before' => $before,
            'after' => $template->only(['subject', 'category', 'status']),
        ]);

        return redirect()->route('admin.lifecycle.templates.edit', $template)
            ->with('success', 'Template saved. Version ' . $template->current_version . ' recorded.');
    }

    public function duplicateTemplate(LifecycleEmailTemplate $template)
    {
        $this->authoriseManage();

        $copy = $template->duplicate((int) Auth::id());

        AdminLog::log(Auth::id(), 'lifecycle_template_duplicated', [
            'from' => $template->key,
            'to' => $copy->key,
        ]);

        return redirect()->route('admin.lifecycle.templates.edit', $copy)
            ->with('success', 'Template duplicated as a draft.');
    }

    public function previewTemplate(LifecycleEmailTemplate $template, Request $request)
    {
        // A preview is rendered against a real customer when one is named, because a
        // preview with empty variables is not a preview — it is an empty shell.
        $user = $this->previewUser($request);

        $rendered = $this->dispatcher->render($template, $user);

        return response()->json([
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'unknown_variables' => $this->variables->unknownVariables(
                (string) $template->subject . ' ' . (string) $template->html_body
            ),
        ]);
    }

    public function testTemplate(Request $request, LifecycleEmailTemplate $template)
    {
        $this->authoriseSend();

        $validated = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::findOrFail($validated['user_id']);

        $message = $this->dispatcher->sendTest($template, $user, (int) Auth::id());

        AdminLog::log(Auth::id(), 'lifecycle_template_tested', [
            'template_key' => $template->key,
            'to_user_id' => $user->getKey(),
            'status' => $message->status,
        ]);

        return back()->with(
            $message->status === LifecycleMessage::STATUS_ACCEPTED ? 'success' : 'error',
            $message->status === LifecycleMessage::STATUS_ACCEPTED
                ? 'Test sent to ' . $user->email . '.'
                : 'Test not sent: ' . ($message->status_message ?? 'see the delivery log.')
        );
    }

    public function toggleTemplate(LifecycleEmailTemplate $template, Request $request)
    {
        $this->authoriseManage();

        $validated = $request->validate(['status' => 'required|in:active,draft,archived']);

        $template->forceFill(['status' => $validated['status']])->save();

        AdminLog::log(Auth::id(), 'lifecycle_template_status', [
            'template_key' => $template->key,
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Template ' . $validated['status'] . '.');
    }

    /**
     * Roll a template back to an earlier version.
     *
     * A restore is recorded as a *new* version rather than erasing the ones after it,
     * because the edit that is being undone may itself have been a fix, and history
     * that can be rewritten is not history.
     */
    public function rollbackTemplate(LifecycleEmailTemplate $template, int $version)
    {
        $this->authoriseManage();

        $record = $template->versions()->where('version', $version)->firstOrFail();

        $record->restoreTo($template, (int) Auth::id());

        AdminLog::log(Auth::id(), 'lifecycle_template_rolled_back', [
            'template_key' => $template->key,
            'restored_version' => $version,
        ]);

        return back()->with('success', 'Restored version ' . $version . ' as a new version.');
    }

    /* =====================================================================
     | Automations
     |==================================================================== */

    public function automations()
    {
        return view('admin.lifecycle.automations', [
            'automations' => LifecycleAutomation::with('template')->orderByDesc('id')->get(),
            'triggers' => LifecycleTrigger::all(),
        ]);
    }

    public function createAutomation()
    {
        $this->authoriseManage();

        return view('admin.lifecycle.automation-form', $this->automationFormData(new LifecycleAutomation([
            'trigger_event' => LifecycleTrigger::USER_REGISTERED,
            'status' => LifecycleAutomation::STATUS_DRAFT,
            'conditions' => [],
            'condition_logic' => 'and',
            'delay_minutes' => 0,
            'limit_per_run' => 200,
            'limit_per_user_per_day' => 1,
        ])));
    }

    public function editAutomation(LifecycleAutomation $automation)
    {
        return view('admin.lifecycle.automation-form', $this->automationFormData($automation));
    }

    public function storeAutomation(Request $request, ?LifecycleAutomation $automation = null)
    {
        $this->authoriseManage();

        $validated = $request->validate([
            'key' => 'required|string|max:64|regex:/^[a-z0-9._-]+$/|unique:lifecycle_automations,key' . ($automation ? ',' . $automation->getKey() : ''),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'trigger_event' => 'required|string|in:' . implode(',', LifecycleTrigger::names()),
            'template_id' => 'required|integer|exists:lifecycle_email_templates,id',
            'condition_logic' => 'required|in:and,or',
            'delay_minutes' => 'required|integer|min:0|max:525600',
            'limit_per_run' => 'required|integer|min:1|max:5000',
            'limit_per_user_per_day' => 'required|integer|min:1|max:10',
            'max_sends' => 'nullable|integer|min:1',
            'active_from' => 'nullable|date',
            'active_until' => 'nullable|date|after:active_from',
            'status' => 'required|in:draft,active,paused,archived',
            'conditions' => 'nullable|array|max:20',
            'conditions.*.field' => 'required|string',
            'conditions.*.operator' => 'required|string',
            'conditions.*.value' => 'nullable',
            'cta_label' => 'nullable|array',
            'cta_url' => 'nullable|array',
        ]);

        $conditions = $this->normaliseConditions($validated['conditions'] ?? []);

        // A condition that cannot be evaluated must not be storable: the evaluator
        // treats an unknown field as false, so storing one silently changes an
        // audience without anybody being told.
        $problems = LifecycleConditionEvaluator::validate($conditions);

        if ($problems !== []) {
            return back()->withInput()->withErrors(['conditions' => $problems]);
        }

        $isNew = $automation === null;

        if ($isNew) {
            $automation = new LifecycleAutomation(['created_by' => (int) Auth::id()]);
        }

        $automation->fill([
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'trigger_event' => $validated['trigger_event'],
            'template_id' => $validated['template_id'],
            'conditions' => $conditions,
            'condition_logic' => $validated['condition_logic'],
            'delay_minutes' => $validated['delay_minutes'],
            'limit_per_run' => $validated['limit_per_run'],
            'limit_per_user_per_day' => $validated['limit_per_user_per_day'],
            'max_sends' => $validated['max_sends'] ?? null,
            'active_from' => $validated['active_from'] ?? null,
            'active_until' => $validated['active_until'] ?? null,
            'status' => $validated['status'],
        ]);

        $automation->save();

        // Activation is the auditable event, not the save: an automation that is
        // merely edited sends nothing, and one that is activated starts mailing people.
        AdminLog::log(Auth::id(), $isNew ? 'lifecycle_automation_created' : 'lifecycle_automation_updated', [
            'automation_key' => $automation->key,
            'trigger' => $automation->trigger_event,
            'status' => $automation->status,
            'condition_count' => count($conditions),
        ]);

        return redirect()->route('admin.lifecycle.automations')
            ->with('success', 'Automation saved.');
    }

    public function toggleAutomation(Request $request, LifecycleAutomation $automation)
    {
        $this->authoriseManage();

        $validated = $request->validate(['status' => 'required|in:draft,active,paused,archived']);

        $automation->forceFill(['status' => $validated['status']])->save();

        /*
         * The most important line in this file. Activating an automation is the moment
         * the platform starts mailing people, and a log entry that names who did it and
         * what it will do is the only way to answer "why did 4,000 customers get this?"
         * a week later.
         */
        AdminLog::log(Auth::id(), 'lifecycle_automation_status', [
            'automation_key' => $automation->key,
            'name' => $automation->name,
            'status' => $validated['status'],
            'trigger' => $automation->trigger_event,
        ]);

        return back()->with('success', 'Automation ' . $validated['status'] . '.');
    }

    /**
     * Test an automation against one customer, without sending.
     *
     * Reports each condition's field, expected value and actual value, so "did not
     * match" becomes an answer rather than a mystery. The check is the same evaluator
     * the sweep uses — a second implementation for the tester is how a preview starts
     * disagreeing with production.
     */
    public function testAutomation(Request $request, LifecycleAutomation $automation)
    {
        $validated = $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::findOrFail($validated['user_id']);

        $explanation = $this->conditions->explain($automation, $user);

        $matches = $this->conditions->matches($automation, $user);

        return response()->json([
            'matches' => $matches,
            'blocking_reason' => $automation->blockingReason(),
            'conditions' => $explanation,
            'suppressed' => LifecycleSuppression::blocks((string) $user->email, true),
            'opted_out' => LifecycleConsent::marketingOptedOut((string) $user->email),
            'marketing_enabled' => LifecycleSettings::marketingEnabled(),
        ]);
    }

    /* =====================================================================
     | Delivery log
     |==================================================================== */

    public function deliveries(Request $request)
    {
        $filters = $request->validate([
            'status' => 'nullable|in:' . implode(',', [
                LifecycleMessage::STATUS_SCHEDULED,
                LifecycleMessage::STATUS_QUEUED,
                LifecycleMessage::STATUS_SENDING,
                LifecycleMessage::STATUS_ACCEPTED,
                LifecycleMessage::STATUS_DELIVERED,
                LifecycleMessage::STATUS_BOUNCED,
                LifecycleMessage::STATUS_SUPPRESSED,
                LifecycleMessage::STATUS_CANCELLED,
                LifecycleMessage::STATUS_FAILED,
            ]),
            'automation_id' => 'nullable|integer',
            'search' => 'nullable|string|max:120',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = LifecycleMessage::query()->with(['automation', 'user']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['automation_id'])) {
            $query->where('automation_id', $filters['automation_id']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('to_address', 'like', '%' . $search . '%')
                    ->orWhere('subject', 'like', '%' . $search . '%')
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%' . $search . '%'));
            });
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return view('admin.lifecycle.deliveries', [
            'messages' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'automations' => LifecycleAutomation::orderBy('name')->get(['id', 'name']),
            'counts' => $this->statusCounts(),
        ]);
    }

    /**
     * Retry a failed delivery.
     *
     * Only a `failed` row with attempts left. A `bounced` row is never retried — the
     * address is known bad and the next send costs sending reputation — and a
     * `suppressed` one is terminal by definition. `isRetryable()` owns that rule so a
     * second copy of it cannot drift.
     */
    public function retryDelivery(Request $request, LifecycleMessage $message)
    {
        $this->authoriseSend();

        if (! $message->isRetryable($this->dispatcher->maxAttempts())) {
            return back()->with('error', 'That delivery is not eligible for a retry.');
        }

        $message->forceFill([
            'status' => LifecycleMessage::STATUS_SCHEDULED,
            'scheduled_for' => now(),
            'status_message' => 'Queued for retry by an administrator.',
        ])->save();

        \App\Jobs\LifecycleSendMessage::dispatch($message->getKey());

        AdminLog::log(Auth::id(), 'lifecycle_delivery_retried', [
            'message_id' => $message->getKey(),
            'automation_id' => $message->automation_id,
        ]);

        return back()->with('success', 'Delivery queued for retry.');
    }

    /* =====================================================================
     | Suppressions and consent
     |==================================================================== */

    public function suppressions(Request $request)
    {
        $validated = $request->validate(['search' => 'nullable|string|max:190']);

        $query = LifecycleSuppression::query()->latest('id');

        if (! empty($validated['search'])) {
            $query->where('email', 'like', '%' . $validated['search'] . '%');
        }

        return view('admin.lifecycle.suppressions', [
            'suppressions' => $query->paginate(25)->withQueryString(),
            'search' => $validated['search'] ?? null,
        ]);
    }

    public function storeSuppression(Request $request)
    {
        $this->authoriseManage();

        $validated = $request->validate([
            'email' => 'required|email|max:190',
            'reason' => 'required|in:' . implode(',', [
                LifecycleSuppression::REASON_BOUNCE,
                LifecycleSuppression::REASON_COMPLAINT,
                LifecycleSuppression::REASON_MANUAL,
            ]),
            'note' => 'nullable|string|max:255',
        ]);

        LifecycleSuppression::firstOrCreate(
            ['email' => strtolower($validated['email']), 'reason' => $validated['reason']],
            ['note' => $validated['note'] ?? null, 'created_by' => (int) Auth::id()]
        );

        AdminLog::log(Auth::id(), 'lifecycle_suppression_added', $validated);

        return back()->with('success', 'Address suppressed.');
    }

    public function destroySuppression(LifecycleSuppression $suppression)
    {
        $this->authoriseManage();

        AdminLog::log(Auth::id(), 'lifecycle_suppression_removed', [
            'email' => $suppression->email,
            'reason' => $suppression->reason,
        ]);

        $suppression->delete();

        return back()->with('success', 'Suppression removed.');
    }

    /* =====================================================================
     | Internals
     |=================================================================== */

    private function authoriseManage(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_lifecycle'), 403);
    }

    private function authoriseSend(): void
    {
        abort_unless(
            Auth::user()?->hasPermission('send_lifecycle') || Auth::user()?->hasPermission('manage_lifecycle'),
            403
        );
    }

    /**
     * A real customer to render previews and tests against.
     *
     * An administrator may name one; otherwise the most recent customer is used, so a
     * preview shows real values (a real first name, a real referral link) rather than
     * empty placeholders. A deployment with no customers falls back to a transient,
     * unsaved model, which is enough to render `{{ app_name }}` and the like.
     */
    private function previewUser(Request $request): User
    {
        $id = $request->integer('user_id');

        if ($id > 0) {
            return User::find($id) ?? $this->fallbackUser();
        }

        return User::regularUsers()->latest('id')->first() ?? $this->fallbackUser();
    }

    private function fallbackUser(): User
    {
        return new User([
            'name' => 'Ada Obi',
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'email' => 'customer@example.com',
            'referral_code' => 'REUP0001',
        ]);
    }

    /** @return array<string,mixed> */
    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'key' => 'required|string|max:64|regex:/^[a-z0-9._-]+$/',
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'preview_text' => 'nullable|string|max:255',
            'html_body' => 'required|string|max:200000',
            'text_body' => 'nullable|string|max:200000',
            'category' => 'required|in:marketing,transactional',
            // Nullable so the platform default applies; when present it must be a
            // real address, because an unverified From is rejected by most providers
            // and repeated attempts damage the sending domain.
            'from_name' => 'nullable|string|max:120',
            'from_address' => 'nullable|email|max:190',
            'cta_label' => 'nullable|array|max:3',
            'cta_label.*' => 'nullable|string|max:60',
            'cta_url' => 'nullable|array|max:3',
            'cta_url.*' => 'nullable|string|max:500',
        ]);
    }

    /**
     * Build the CTA button list from the parallel form arrays.
     *
     * URLs are validated here as well as at render time: a `javascript:` href stored
     * now would be refused when rendered, but refusing it at the door means the author
     * is told immediately rather than discovering a missing button in a live send.
     */
    private function buttons(array $validated): ?array
    {
        $labels = $validated['cta_label'] ?? [];
        $urls = $validated['cta_url'] ?? [];

        $buttons = [];

        foreach ($labels as $index => $label) {
            $label = trim((string) $label);
            $url = trim((string) ($urls[$index] ?? ''));

            if ($label === '' || $url === '') {
                continue;
            }

            $safe = HtmlSanitizer::safeUrl($url);

            if ($safe === null) {
                continue;
            }

            $buttons[] = ['label' => $label, 'url' => $safe];
        }

        return $buttons === [] ? null : $buttons;
    }

    /**
     * Drop empty condition rows and normalise the value's type.
     *
     * @param  array<int,mixed>  $conditions
     * @return array<int,array<string,mixed>>
     */
    private function normaliseConditions(array $conditions): array
    {
        $out = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));
            $operator = trim((string) ($condition['operator'] ?? ''));

            if ($field === '' || $operator === '') {
                continue;
            }

            $type = LifecycleConditionEvaluator::fields()[$field]['type'] ?? 'text';
            $value = $condition['value'] ?? null;

            if (in_array($operator, LifecycleConditionEvaluator::valuelessOperators(), true)) {
                $value = null;
            } elseif ($type === 'number') {
                $value = is_numeric($value) ? $value + 0 : 0;
            } elseif ($type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            } else {
                $value = trim((string) $value);
            }

            $out[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function automationFormData(LifecycleAutomation $automation): array
    {
        return [
            'automation' => $automation,
            // Only active templates can be attached to an automation a customer will
            // receive, and drafts are excluded so a half-written body cannot be armed.
            'templates' => LifecycleEmailTemplate::whereIn('status', [
                LifecycleEmailTemplate::STATUS_ACTIVE,
                LifecycleEmailTemplate::STATUS_DRAFT,
            ])->orderBy('name')->get(['id', 'name', 'status', 'category']),
            'triggers' => LifecycleTrigger::all(),
            'fields' => LifecycleConditionEvaluator::fields(),
            'operators' => LifecycleConditionEvaluator::operators(),
            'valuelessOperators' => LifecycleConditionEvaluator::valuelessOperators(),
        ];
    }

    /** @return array<string,int> */
    private function statusCounts(): array
    {
        return LifecycleMessage::query()
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
