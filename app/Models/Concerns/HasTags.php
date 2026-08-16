<?php

namespace App\Models\Concerns;

/**
 * Free-form labels on a row, stored as a json array.
 *
 * Normalized on the way in, once, so every later comparison is a plain string
 * match: trimmed, lower cased, deduplicated, and stripped of the characters
 * that would make a tag hard to type back into a filter box.
 */
trait HasTags
{
    /** Accepts an array or a comma separated string, from a form or the API. */
    public function setTagsAttribute($value): void
    {
        $this->attributes['tags'] = json_encode(self::normalizeTags($value));
    }

    public function tagList(): array
    {
        return (array) ($this->tags ?? []);
    }

    public function hasTag(string $tag): bool
    {
        return in_array(self::normalizeTag($tag), $this->tagList(), true);
    }

    /** @return array<int, string> */
    public static function normalizeTags($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $tag) {
            $tag = self::normalizeTag((string) $tag);
            if ($tag !== '' && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }
        sort($out);

        return array_slice($out, 0, 25);
    }

    public static function normalizeTag(string $tag): string
    {
        $tag = strtolower(trim($tag));
        // Keep letters, digits, and the three separators people actually type.
        // A tag with a comma in it could never be typed back into the filter,
        // because that is the character the filter splits on.
        $tag = preg_replace('/[^a-z0-9 ._:\/-]+/', '', $tag) ?? '';

        return trim(mb_substr(preg_replace('/\s+/', ' ', $tag) ?? '', 0, 40));
    }

    /** Rows carrying every one of the given tags. */
    public function scopeTagged($query, $tags)
    {
        foreach (self::normalizeTags($tags) as $tag) {
            // whereJsonContains works on MySQL and on the SQLite the tests use,
            // which is the whole reason tags are a json array of plain strings
            // rather than objects.
            $query->whereJsonContains('tags', $tag);
        }

        return $query;
    }
}
