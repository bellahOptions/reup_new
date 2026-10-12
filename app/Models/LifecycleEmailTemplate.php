<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lifecycle email template, and the head of its version history.
 *
 * `html_body` is author-supplied HTML, which is the one genuinely dangerous input in
 * this module: it is rendered into an email and, on the preview screen, into the
 * admin console. It is sanitised on save through an explicit allowlist of tags and
 * attributes — see `App\Lifecycle\HtmlSanitizer` — and every customer-supplied
 * *variable* substituted into it is HTML-escaped, so a name containing markup cannot
 * become markup.
 *
 * Nothing here is ever evaluated. A template is data: text with placeholders. The
 * placeholders are substituted from an allowlist and there is no expression
 * language, no partials and no way to reach application code from a template.
 */
class LifecycleEmailTemplate extends Model
{
    protected $table = 'lifecycle_email_templates';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const CATEGORY_MARKETING = 'marketing';
    public const CATEGORY_TRANSACTIONAL = 'transactional';

    protected $fillable = [
        'key', 'name', 'subject', 'preview_text', 'html_body', 'text_body',
        'cta_buttons', 'category', 'from_name', 'from_address', 'status',
        'current_version', 'created_by',
    ];

    protected $casts = [
        'cta_buttons' => 'array',
        'current_version' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(LifecycleTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    public function automations(): HasMany
    {
        return $this->hasMany(LifecycleAutomation::class, 'template_id');
    }

    public function isMarketing(): bool
    {
        return $this->category === self::CATEGORY_MARKETING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Snapshot the current content as the next version.
     *
     * Called on every save that changes something renderable, so "roll back" always
     * has something to roll back to. The version is issued from the row's own counter
     * rather than from `versions()->count()`, which would reuse a number after a
     * deletion and make a `lifecycle_messages.template_version` ambiguous.
     */
    public function recordVersion(?string $note = null, ?int $actorId = null): LifecycleTemplateVersion
    {
        $version = (int) $this->current_version;

        $record = LifecycleTemplateVersion::create([
            'template_id' => $this->getKey(),
            'version' => $version,
            'content' => [
                'subject' => $this->subject,
                'preview_text' => $this->preview_text,
                'html_body' => $this->html_body,
                'text_body' => $this->text_body,
                'cta_buttons' => $this->cta_buttons,
                'category' => $this->category,
                'from_name' => $this->from_name,
                'from_address' => $this->from_address,
            ],
            'note' => $note,
            'created_by' => $actorId,
        ]);

        $this->forceFill(['current_version' => $version + 1])->saveQuietly();

        return $record;
    }

    public function duplicate(int $actorId): self
    {
        $copy = $this->replicate(['key', 'current_version', 'created_by']);
        $copy->key = $this->key . '-copy-' . substr((string) now()->getTimestamp(), -5);
        $copy->name = $this->name . ' (copy)';
        $copy->status = self::STATUS_DRAFT;
        $copy->current_version = 1;
        $copy->created_by = $actorId;
        $copy->save();

        $copy->recordVersion('Duplicated from ' . $this->key, $actorId);

        return $copy;
    }
}
