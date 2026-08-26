<?php

namespace App\Repositories\Source;

use App\Constants\SourceStatus;
use App\Models\SourceArtifact;
use App\Models\WikiSource;
use Illuminate\Database\Eloquent\Collection;

class SourceRepository
{
    public function recordScan(
        string $path,
        string $type,
        string $sha256,
        int $size,
        int $mtime,
    ): WikiSource {
        $source = WikiSource::query()->firstOrNew(['path' => $path]);
        $changed = $source->exists && $source->sha256 !== $sha256;

        $source->fill([
            'type' => $type,
            'sha256' => $sha256,
            'size' => $size,
            'mtime' => $mtime,
            'revision' => $changed ? $source->revision + 1 : ($source->revision ?: 1),
            'status' => $changed || ! $source->exists || $source->status === SourceStatus::Missing->value
                ? SourceStatus::Pending->value
                : $source->status,
            'processed_at' => $changed ? null : $source->processed_at,
            'last_scanned_at' => now(),
        ])->save();

        return $source;
    }

    /** @param list<string> $seenPaths */
    public function markMissingExcept(array $seenPaths): int
    {
        $query = WikiSource::query()->where('status', '!=', SourceStatus::Missing->value);
        if ($seenPaths !== []) {
            $query->whereNotIn('path', $seenPaths);
        }

        return $query->update(['status' => SourceStatus::Missing->value]);
    }

    public function findByPath(string $path): ?WikiSource
    {
        return WikiSource::query()->where('path', $path)->first();
    }

    public function find(int $id): WikiSource
    {
        return WikiSource::query()->findOrFail($id);
    }

    /** @return Collection<int, WikiSource> */
    public function listed(string $filter = ''): Collection
    {
        return WikiSource::query()
            ->when($filter !== '', fn ($query) => $query->where('path', 'like', '%'.$filter.'%'))
            ->orderBy('path')
            ->get();
    }

    /** @return Collection<int, WikiSource> */
    public function pending(?string $path = null): Collection
    {
        return WikiSource::query()
            ->whereIn('status', [
                SourceStatus::Pending->value,
                SourceStatus::Failed->value,
                SourceStatus::BlockedModelCapability->value,
            ])
            ->when($path !== null, fn ($query) => $query->where('path', $path))
            ->orderBy('path')
            ->get();
    }

    /** @return Collection<int, SourceArtifact> */
    public function artifacts(WikiSource $source): Collection
    {
        return $source->artifacts()->orderBy('sequence')->get();
    }

    public function resetArtifacts(WikiSource $source): void
    {
        $source->artifacts()->delete();
    }

    /** @param array<string, mixed>|null $metadata */
    public function recordArtifact(
        WikiSource $source,
        string $kind,
        string $path,
        ?int $sequence = null,
        ?int $page = null,
        ?array $metadata = null,
    ): SourceArtifact {
        return $source->artifacts()->create([
            'kind' => $kind,
            'path' => $path,
            'sequence' => $sequence,
            'page' => $page,
            'metadata' => $metadata,
        ]);
    }

    /** @return Collection<int, WikiSource> */
    public function recent(int $limit = 100): Collection
    {
        return WikiSource::query()->orderByDesc('updated_at')->limit($limit)->get();
    }

    public function save(WikiSource $source): void
    {
        $source->save();
    }

    /** @param list<string>|null $warnings */
    public function markProcessing(WikiSource $source, ?array $warnings = null): void
    {
        $source->update([
            'status' => SourceStatus::Processing->value,
            'warnings' => $warnings,
        ]);
    }

    /** @param list<string> $warnings */
    public function updateWarnings(WikiSource $source, array $warnings): void
    {
        $source->update(['warnings' => $warnings ?: null]);
    }

    public function markReady(WikiSource $source): void
    {
        $source->update([
            'status' => SourceStatus::Ready->value,
            'processed_at' => now(),
        ]);
    }

    public function markPending(WikiSource $source): void
    {
        $source->update(['status' => SourceStatus::Pending->value]);
    }

    public function markBlockedModelCapability(WikiSource $source): void
    {
        $source->update(['status' => SourceStatus::BlockedModelCapability->value]);
    }

    public function markFailedUnlessBlocked(WikiSource $source, bool $cancelled): void
    {
        $source->refresh();
        if ($source->status === SourceStatus::BlockedModelCapability->value) {
            return;
        }

        $source->update([
            'status' => $cancelled ? SourceStatus::Pending->value : SourceStatus::Failed->value,
        ]);
    }
}
