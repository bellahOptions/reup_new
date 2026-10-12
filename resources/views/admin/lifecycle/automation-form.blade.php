@extends('admin.layouts.app')

@section('title', $automation->exists ? 'Edit automation' : 'New automation')
@section('page-title', $automation->exists ? $automation->name : 'New automation')
@section('page-description', 'Trigger, conditions, timing and template.')

@section('content')
<div class="space-y-6"
     x-data="lifecycleAutomation({
        testUrl: @js($automation->exists ? route('admin.lifecycle.automations.test', $automation, false) : null),
        conditions: @js(old('conditions', $automation->conditions ?? [])),
        fields: @js($fields),
        operators: @js($operators),
        valueless: @js($valuelessOperators),
     })">

    @if($errors->any())
        <div class="alert alert-destructive">
            <p class="text-sm font-medium">That could not be saved</p>
            <ul class="mt-1 list-inside list-disc text-sm">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $automation->exists ? route('admin.lifecycle.automations.update', $automation, false) : route('admin.lifecycle.automations.store', [], false) }}"
          class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @csrf
        @if($automation->exists)
            @method('PUT')
        @endif

        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">What it is</h2>
                </div>
                <div class="card-content space-y-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="key" class="label">Key</label>
                            <input id="key" name="key" type="text" required value="{{ old('key', $automation->key) }}"
                                   placeholder="welcome-email" pattern="[a-z0-9._-]+"
                                   class="input mt-1.5">
                        </div>
                        <div>
                            <label for="name" class="label">Name</label>
                            <input id="name" name="name" type="text" required value="{{ old('name', $automation->name) }}"
                                   class="input mt-1.5">
                        </div>
                    </div>

                    <div>
                        <label for="description" class="label">Description</label>
                        <textarea id="description" name="description" rows="2" maxlength="500"
                                  class="textarea mt-1.5">{{ old('description', $automation->description) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">When it fires</h2>
                </div>
                <div class="card-content space-y-5">
                    <div>
                        <label for="trigger_event" class="label">Trigger</label>
                        <select id="trigger_event" name="trigger_event" class="select mt-1.5" x-model="trigger">
                            @foreach($triggers as $name => $trigger)
                                <option value="{{ $name }}" @selected(old('trigger_event', $automation->trigger_event) === $name)>
                                    {{ $trigger['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-muted-foreground" x-text="triggerDescription"></p>
                    </div>

                    <div>
                        <label for="template_id" class="label">Template</label>
                        <select id="template_id" name="template_id" class="select mt-1.5" required>
                            <option value="">Choose a template</option>
                            @foreach($templates as $templateOption)
                                <option value="{{ $templateOption->id }}"
                                        @selected((int) old('template_id', $automation->template_id) === $templateOption->id)>
                                    {{ $templateOption->name }}
                                    ({{ $templateOption->status }}{{ $templateOption->category === 'transactional' ? ', transactional' : '' }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Conditions</h2>
                    <p class="card-description">
                        All fields are evaluated on the server. No condition can run SQL, PHP or JavaScript.
                    </p>
                </div>

                <div class="card-content space-y-4">
                    <div>
                        <label for="condition_logic" class="label">Match</label>
                        <select id="condition_logic" name="condition_logic" class="select mt-1.5 max-w-xs">
                            <option value="and" @selected(old('condition_logic', $automation->condition_logic) === 'and')>All conditions (AND)</option>
                            <option value="or" @selected(old('condition_logic', $automation->condition_logic) === 'or')>Any condition (OR)</option>
                        </select>
                    </div>

                    <template x-for="(condition, index) in conditions" :key="index">
                        <div class="grid grid-cols-1 gap-3 rounded-lg border border-border p-3 sm:grid-cols-[2fr_2fr_2fr_auto]">
                            <select :name="`conditions[${index}][field]`" class="select" x-model="condition.field">
                                <option value="">Choose a field</option>
                                <template x-for="(meta, key) in fields" :key="key">
                                    <option :value="key" x-text="meta.label"></option>
                                </template>
                            </select>

                            <select :name="`conditions[${index}][operator]`" class="select" x-model="condition.operator">
                                <option value="">Choose an operator</option>
                                <template x-for="(label, key) in operators" :key="key">
                                    <option :value="key" x-text="label"></option>
                                </template>
                            </select>

                            <input :name="`conditions[${index}][value]`" type="text" class="input"
                                   x-model="condition.value"
                                   :disabled="valueless.includes(condition.operator)"
                                   :placeholder="valueless.includes(condition.operator) ? '(no value needed)' : 'Value'">

                            <button type="button" class="btn btn-ghost btn-sm" @click="removeCondition(index)"
                                    aria-label="Remove condition">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </div>
                    </template>

                    <p class="text-sm text-muted-foreground" x-show="! conditions.length">
                        No conditions — the automation matches every customer the trigger fires for.
                    </p>

                    <button type="button" class="btn btn-outline btn-sm" @click="addCondition()">
                        <x-icon name="plus" class="h-4 w-4" />
                        Add condition
                    </button>

                    <div class="rounded-lg border border-border bg-surface-muted p-3">
                        <p class="text-xs font-medium">Wallet balance</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Readable as a condition, and never printable in an email. There is no variable for it —
                            a balance in a message is forwarded, screenshotted and read over a shoulder.
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Timing and limits</h2>
                </div>

                <div class="card-content grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="delay_minutes" class="label">Delay (minutes)</label>
                        <input id="delay_minutes" name="delay_minutes" type="number" min="0" max="525600" required
                               value="{{ old('delay_minutes', $automation->delay_minutes ?? 0) }}"
                               class="input mt-1.5">
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            1440 = 24 hours. Eligibility is re-checked immediately before sending, so a customer who
                            funds their wallet in the meantime does not get the reminder.
                        </p>
                    </div>

                    <div>
                        <label for="max_sends" class="label">Maximum sends (optional)</label>
                        <input id="max_sends" name="max_sends" type="number" min="1"
                               value="{{ old('max_sends', $automation->max_sends) }}"
                               class="input mt-1.5">
                    </div>

                    <div>
                        <label for="limit_per_run" class="label">Per run</label>
                        <input id="limit_per_run" name="limit_per_run" type="number" min="1" max="5000" required
                               value="{{ old('limit_per_run', $automation->limit_per_run ?? 200) }}"
                               class="input mt-1.5">
                    </div>

                    <div>
                        <label for="limit_per_user_per_day" class="label">Per customer per day</label>
                        <input id="limit_per_user_per_day" name="limit_per_user_per_day" type="number" min="1" max="10" required
                               value="{{ old('limit_per_user_per_day', $automation->limit_per_user_per_day ?? 1) }}"
                               class="input mt-1.5">
                    </div>

                    <div>
                        <label for="active_from" class="label">Active from</label>
                        <input id="active_from" name="active_from" type="datetime-local"
                               value="{{ old('active_from', $automation->active_from?->format('Y-m-d\TH:i')) }}"
                               class="input mt-1.5">
                    </div>

                    <div>
                        <label for="active_until" class="label">Active until</label>
                        <input id="active_until" name="active_until" type="datetime-local"
                               value="{{ old('active_until', $automation->active_until?->format('Y-m-d\TH:i')) }}"
                               class="input mt-1.5">
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Status</h2>
                </div>

                <div class="card-content space-y-4">
                    <select name="status" class="select" x-model="status">
                        <option value="draft" @selected(old('status', $automation->status) === 'draft')>Draft — does nothing</option>
                        <option value="active" @selected(old('status', $automation->status) === 'active')>Active — sends to matching customers</option>
                        <option value="paused" @selected(old('status', $automation->status) === 'paused')>Paused</option>
                        <option value="archived" @selected(old('status', $automation->status) === 'archived')>Archived</option>
                    </select>

                    <p class="text-xs text-muted-foreground" x-show="status === 'active'">
                        Setting this to Active starts mailing customers whose conditions match. The change is
                        recorded in the admin log with your account.
                    </p>

                    <div class="flex flex-col gap-2 border-t border-border pt-4">
                        <button type="submit" class="btn btn-primary btn-sm">Save automation</button>
                        <a href="{{ route('admin.lifecycle.automations') }}" class="btn btn-ghost btn-sm">Cancel</a>
                    </div>
                </div>
            </div>

            @if($automation->exists)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Test against a customer</h2>
                        <p class="card-description">Checks the conditions. Sends nothing.</p>
                    </div>

                    <div class="card-content space-y-3">
                        <input type="number" x-model="userId" placeholder="User id" class="input">

                        <button type="button" class="btn btn-outline btn-sm w-full" @click="test()" :disabled="testing">
                            <span x-text="testing ? 'Checking…' : 'Check conditions'"></span>
                        </button>

                        <template x-if="result">
                            <div class="space-y-2">
                                <span class="badge" :class="result.matches ? 'badge-success' : 'badge-neutral'"
                                      x-text="result.matches ? 'Matches' : 'Does not match'"></span>

                                <template x-if="result.blocking_reason">
                                    <p class="text-xs text-amber-700" x-text="result.blocking_reason"></p>
                                </template>

                                <template x-if="result.opted_out">
                                    <p class="text-xs text-amber-700">Customer has opted out of marketing.</p>
                                </template>

                                <template x-if="! result.marketing_enabled">
                                    <p class="text-xs text-amber-700">Marketing is paused platform-wide.</p>
                                </template>

                                <table class="table text-xs">
                                    <tbody>
                                        <template x-for="row in result.conditions" :key="row.field">
                                            <tr>
                                                <td class="font-mono" x-text="row.field"></td>
                                                <td x-text="`${row.operator} ${row.expected ?? ''}`"></td>
                                                <td class="tabular-nums" x-text="row.actual ?? '—'"></td>
                                                <td>
                                                    <span :class="row.matched ? 'text-green-600' : 'text-red-600'"
                                                          x-text="row.matched ? 'ok' : 'no'"></span>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </div>
            @endif
        </div>
    </form>
</div>

@push('scripts')
<script>
    /*
     * The condition builder.
     *
     * Rows are rendered from Alpine state and posted as indexed arrays, so the server
     * receives exactly the structure it validates. The field and operator lists come
     * from the same PHP allowlists the evaluator uses — a second copy in JavaScript is
     * how a UI starts offering a condition the server then refuses.
     */
    document.addEventListener('alpine:init', () => {
        Alpine.data('lifecycleAutomation', (config) => ({
            conditions: Array.isArray(config.conditions) ? config.conditions : [],
            fields: config.fields,
            operators: config.operators,
            valueless: config.valueless,
            trigger: @js(old('trigger_event', $automation->trigger_event)),
            status: @js(old('status', $automation->status ?? 'draft')),
            userId: '',
            testing: false,
            result: null,

            get triggerDescription() {
                const map = @js($triggers);

                return map[this.trigger]?.description ?? '';
            },

            addCondition() {
                this.conditions.push({ field: '', operator: '', value: '' });
            },

            removeCondition(index) {
                this.conditions.splice(index, 1);
            },

            async test() {
                if (! config.testUrl || ! this.userId) {
                    return;
                }

                this.testing = true;

                try {
                    const response = await fetch(config.testUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ user_id: this.userId }),
                    });

                    this.result = await response.json();
                } catch (error) {
                    this.result = null;
                } finally {
                    this.testing = false;
                }
            },
        }));
    });
</script>
@endpush
@endsection
