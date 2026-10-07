<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TermsPrivacy extends Model
{
    use HasFactory;

    public const TYPES = ['terms', 'privacy'];

    protected $table = 'terms_privacies';

    protected $fillable = [
        'type',
        'content',
        'updated_by',
        'version_date',
        'is_active',
    ];

    protected $casts = [
        'version_date' => 'datetime',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    private const CACHE_KEYS = [
        'terms' => 'legal.terms.active',
        'privacy' => 'legal.privacy.active',
    ];

    /**
     * Reject anything that is not a known document type.
     *
     * The `{type}` route parameter reached these lookups unvalidated, so a
     * caller could probe with arbitrary strings and read the resulting error
     * or, for some drivers, produce a malformed query.
     */
    public static function assertValidType(string $type): string
    {
        $type = Str::lower(trim($type));

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown legal document type.');
        }

        return $type;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /* =====================================================================
     | Reads
     |=================================================================== */

    public static function getTerms(): ?self
    {
        return self::getByType('terms');
    }

    public static function getPrivacy(): ?self
    {
        return self::getByType('privacy');
    }

    public static function getByType(string $type): ?self
    {
        $type = self::assertValidType($type);
        $key = self::CACHE_KEYS[$type];

        // Cache the primary key, not the model: serialising an Eloquent model
        // into the file cache is both brittle and large.
        $id = Cache::remember($key, 3600, function () use ($type) {
            return self::where('type', $type)->active()->orderByDesc('version_date')->value('id');
        });

        return $id ? self::find($id) : null;
    }

    /**
     * Current document, falling back to the newest row of that type even if it
     * has been deactivated (so the admin editor still has something to show).
     */
    public static function getByTypeAndVersion(string $type, ?string $version = null): ?self
    {
        $type = self::assertValidType($type);

        if ($version === null) {
            return self::getByType($type)
                ?? self::where('type', $type)->orderByDesc('version_date')->first();
        }

        $record = self::where('type', $type)->orderByDesc('version_date')->first();

        return $record;
    }

    public static function history(string $type, int $limit = 20)
    {
        $type = self::assertValidType($type);

        return DB::table('terms_privacy_versions')
            ->where('type', $type)
            ->orderByDesc('version_date')
            ->limit($limit)
            ->get();
    }

    public static function getAllActive()
    {
        return collect(self::TYPES)
            ->mapWithKeys(fn ($type) => [$type => self::getByType($type)]);
    }

    /* =====================================================================
     | Writes
     |=================================================================== */

    /**
     * Publish a new revision: update the live row and append an immutable
     * snapshot in one transaction, then drop the cached pointer.
     */
    public static function updateOrCreateDocument(string $type, string $content, ?int $userId): self
    {
        $type = self::assertValidType($type);

        $document = DB::transaction(function () use ($type, $content, $userId) {
            $document = self::where('type', $type)->first();

            if ($document) {
                $document->fill([
                    'content' => $content,
                    'updated_by' => $userId,
                    'version_date' => now(),
                    'is_active' => true,
                ])->save();
            } else {
                $document = self::create([
                    'type' => $type,
                    'content' => $content,
                    'updated_by' => $userId,
                    'version_date' => now(),
                    'is_active' => true,
                ]);
            }

            DB::table('terms_privacy_versions')->insert([
                'type' => $type,
                'content' => $content,
                'updated_by' => $userId,
                'version_date' => now(),
                'checksum' => hash('sha256', $content),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $document;
        });

        self::flushCache($type);

        return $document;
    }

    /**
     * Roll the live document back to a stored revision.
     *
     * Rollback is itself a forward change: the historical content becomes the
     * new current revision, so the audit trail is never rewritten.
     */
    public static function restoreVersion(int $versionId, ?int $userId): self
    {
        $version = DB::table('terms_privacy_versions')->where('id', $versionId)->first();

        if (! $version) {
            throw new InvalidArgumentException('That revision does not exist.');
        }

        return self::updateOrCreateDocument($version->type, $version->content, $userId);
    }

    public static function setActive(int $id, bool $active): self
    {
        $document = self::findOrFail($id);
        $document->update(['is_active' => $active]);

        self::flushCache($document->type);

        return $document;
    }

    public static function flushCache(?string $type = null): void
    {
        foreach (self::CACHE_KEYS as $key => $cacheKey) {
            if ($type === null || $type === $key) {
                Cache::forget($cacheKey);
            }
        }
    }

    /* =====================================================================
     | Accessors
     |=================================================================== */

    public function getTypeNameAttribute(): string
    {
        return $this->type === 'terms' ? 'Terms of Service' : 'Privacy Policy';
    }

    public function getFormattedVersionDateAttribute(): string
    {
        return $this->version_date ? $this->version_date->format('F j, Y') : 'N/A';
    }

    /**
     * A trimmed plain-text rendering for preview panes.
     */
    public function getPreviewContentAttribute(): string
    {
        $text = strip_tags((string) $this->content);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return Str::limit($text, 600);
    }

    /**
     * Summary figures shown beside the editor.
     */
    public function getStatisticsAttribute(): array
    {
        $plain = trim(strip_tags((string) $this->content));

        return [
            'characters' => mb_strlen((string) $this->content),
            'words' => $plain === '' ? 0 : str_word_count($plain),
            'versions' => DB::table('terms_privacy_versions')->where('type', $this->type)->count(),
            'last_updated' => $this->version_date?->diffForHumans() ?? 'never',
        ];
    }
}
