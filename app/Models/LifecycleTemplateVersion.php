<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of one template's content at one version.
 *
 * Versions exist so a rollback is a real operation rather than a hope: "put it back
 * the way it was yesterday" needs the content that was served yesterday, and reading
 * it out of the edit history of a mutable row is not possible once it has been
 * overwritten.
 *
 * A `lifecycle_messages` row also records the version it was rendered from, so what a
 * customer actually received stays recoverable after ten further edits.
 */
class LifecycleTemplateVersion extends Model
{
    protected $table = 'lifecycle_template_versions';

    protected $fillable = ['template_id', 'version', 'content', 'note', 'created_by'];

    protected $casts = [
        'content' => 'array',
        'version' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(LifecycleEmailTemplate::class, 'template_id');
    }

    /** Restore this version's content onto its template, as a new version. */
    public function restoreTo(LifecycleEmailTemplate $template, ?int $actorId = null): void
    {
        $content = $this->content;

        $template->fill([
            'subject' => $content['subject'] ?? $template->subject,
            'preview_text' => $content['preview_text'] ?? null,
            'html_body' => $content['html_body'] ?? $template->html_body,
            'text_body' => $content['text_body'] ?? null,
            'cta_buttons' => $content['cta_buttons'] ?? null,
            'category' => $content['category'] ?? $template->category,
            'from_name' => $content['from_name'] ?? null,
            'from_address' => $content['from_address'] ?? null,
        ]);

        $template->save();

        // A restore is an edit like any other, so it gets its own version rather
        // than rewriting history.
        $template->recordVersion("Restored from version {$this->version}", $actorId);
    }
}
