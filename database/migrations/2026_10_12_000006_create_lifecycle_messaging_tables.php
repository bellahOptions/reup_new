<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer lifecycle messaging: templates, automations, events, deliveries,
 * suppressions and consent.
 *
 * ## The shape, and why each table exists
 *
 *   * **templates** — what is sent. Versioned, because an automation has to be able
 *     to name the version it used and roll back to.
 *   * **automations** — when it is sent: a trigger, structured conditions, a delay,
 *     limits and a status.
 *   * **events** — the durable outbox. A trigger is recorded here *before* anything
 *     is scheduled, so an event that arrives while the queue is down is still
 *     processed later rather than lost. This is the transactional-outbox pattern
 *     and it is the only reason a customer cannot silently miss a welcome email.
 *   * **messages** — one row per intended delivery, and the unit of idempotency.
 *   * **suppressions** — a hard address-level block (bounce, complaint, manual).
 *   * **consent** — per-customer marketing permission, keyed by email so it follows
 *     the customer rather than the account row.
 *
 * ## Deliberately not reusing `notifications`
 *
 * Laravel's `notifications` table is the *recipient's* inbox — it is read and written
 * by `Notifiable` and shows the customer what they have been told. This is the
 * *sender's ledger*: schedules, attempts, provider ids, suppressions, and the
 * render-time snapshot of what was actually sent. Different owners, different
 * lifetimes, and merging them would make one of the two answers wrong.
 *
 * ## Idempotency is a database constraint, not a cache lock
 *
 * `lifecycle_messages` carries `UNIQUE (automation_id, user_id, dedupe_key)`. A
 * cache lock is advisory: it expires, it is per-store, and two app servers with
 * separate caches both acquire it. The unique index is the only thing that holds
 * when the same event is processed twice, concurrently, from different machines —
 * which is exactly the situation a webhook plus a scheduled sweep produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('subject');
            $table->string('preview_text', 255)->nullable();

            $table->longText('html_body');
            $table->longText('text_body')->nullable();

            // Structured CTAs rather than raw HTML buttons: a URL that an
            // administrator typed is validated before it is rendered.
            $table->json('cta_buttons')->nullable();

            // `marketing` may be suppressed and is subject to the global kill
            // switch; `transactional` is never blocked by either.
            $table->string('category', 16)->default('marketing');

            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();

            $table->string('status', 16)->default('draft');

            // The last version number issued for this template.
            $table->unsignedInteger('current_version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'category']);
        });

        Schema::create('lifecycle_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('lifecycle_email_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');

            // The whole renderable body, snapshotted. A message row records the
            // version it was rendered from, so what a customer received is
            // recoverable even after the template is edited ten times.
            $table->json('content');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_id', 'version']);
        });

        Schema::create('lifecycle_automations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();

            // One of LifecycleTrigger::ALL.
            $table->string('trigger_event', 64);
            $table->foreignId('template_id')->constrained('lifecycle_email_templates')->cascadeOnDelete();

            /*
             * The condition tree, and the only place a condition ever lives.
             *
             * Stored as data and evaluated by an allowlisted interpreter — never
             * compiled into SQL, PHP or anything executable. See
             * `LifecycleConditionEvaluator`.
             */
            $table->json('conditions')->nullable();
            $table->string('condition_logic', 3)->default('and');

            $table->unsignedInteger('delay_minutes')->default(0);

            // Caps, so a misconfigured automation cannot mail the whole customer
            // base in one tick.
            $table->unsignedInteger('limit_per_run')->default(200);
            $table->unsignedInteger('limit_per_user_per_day')->default(1);
            $table->unsignedInteger('max_sends')->nullable();

            // Optional active window.
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_until')->nullable();

            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('sent_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'trigger_event']);
        });

        Schema::create('lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The payload the trigger supplied, for conditions and variables.
            $table->json('payload')->nullable();

            /*
             * Where the event came from, so a replayed webhook can be recognised.
             * Unique per (name, user, key): the same domain event delivered twice
             * is one row.
             */
            $table->string('dedupe_key', 64)->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The outbox is drained in occurred_at order.
            $table->index(['processed_at', 'occurred_at']);
            $table->unique(['name', 'user_id', 'dedupe_key']);
        });

        Schema::create('lifecycle_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('automation_id')->constrained('lifecycle_automations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('lifecycle_events')->nullOnDelete();

            $table->string('to_address');
            $table->string('to_name')->nullable();

            /*
             * Idempotency. The key is derived from the automation and the domain
             * event, so processing the same event twice cannot schedule two
             * messages — enforced here rather than in a cache, which is advisory.
             */
            $table->string('dedupe_key', 64);
            $table->unique(['automation_id', 'user_id', 'dedupe_key'], 'lifecycle_messages_idempotency_unique');

            $table->string('template_key', 64);
            $table->unsignedInteger('template_version');

            /*
             * The rendered content, snapshotted at send time.
             *
             * A template edited tomorrow must not change what an audit of today's
             * messages reports, and a retry must resend what was actually sent.
             */
            $table->string('subject');
            $table->longText('html_body');
            $table->longText('text_body')->nullable();

            $table->string('category', 16)->default('marketing');

            /*
             * scheduled → queued → sending → accepted → delivered
             *                                        ↘ bounced / failed / suppressed / cancelled
             *
             * `accepted` and `delivered` are deliberately separate. Accepted means
             * the transport took it; delivered means the provider said it arrived.
             * Reporting the first as the second is the claim this column exists to
             * prevent.
             */
            $table->string('status', 20)->default('scheduled');
            $table->string('status_message', 500)->nullable();

            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('scheduled_for');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Supplied by the provider, when it supplies one.
            $table->string('provider_message_id')->nullable();

            // Hash of the rendered body, so a retry can prove it resends the same
            // content rather than something a template edit produced.
            $table->char('content_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index(['user_id', 'created_at']);
            $table->index('provider_message_id');
        });

        Schema::create('lifecycle_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            // unsubscribe | bounce | complaint | manual
            $table->string('reason', 32);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['email', 'reason']);
            $table->index('email');
        });

        Schema::create('lifecycle_consents', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();

            // Marketing only. Transactional and security mail is not consent-based
            // and is never gated here.
            $table->boolean('marketing_opt_in')->default(true);
            $table->timestamp('opted_out_at')->nullable();
            $table->string('source', 32)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('lifecycle_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'lifecycle_settings',
            'lifecycle_consents',
            'lifecycle_suppressions',
            'lifecycle_messages',
            'lifecycle_events',
            'lifecycle_automations',
            'lifecycle_template_versions',
            'lifecycle_email_templates',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
