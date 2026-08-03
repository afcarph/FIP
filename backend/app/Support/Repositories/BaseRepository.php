<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared Eloquent implementation of {@see RepositoryInterface}.
 *
 * Subclasses declare the model they wrap and, optionally, the columns that may
 * be filtered or sorted. Filtering is deliberately allow-listed: user supplied
 * keys never reach the query builder unless the subclass opted them in, which
 * closes the usual mass-filtering and SQL injection vectors.
 *
 * @template TModel of Model
 *
 * @implements RepositoryInterface<TModel>
 */
abstract class BaseRepository implements RepositoryInterface
{
    /** Columns that `paginate()` accepts as equality filters. */
    protected array $filterable = [];

    /** Columns that `paginate()` accepts in the `sort` parameter. */
    protected array $sortable = ['id', 'created_at', 'updated_at'];

    /** Columns scanned by the `search` filter (LIKE). */
    protected array $searchable = [];

    protected string $defaultSort = '-id';

    /** @return class-string<TModel> */
    abstract protected function model(): string;

    /** @return Builder<TModel> */
    public function query(): Builder
    {
        // The static call is on a class-string<TModel>, which PHPStan cannot
        // follow back to the concrete builder type on its own.
        /** @var Builder<TModel> $query */
        $query = $this->model()::query();

        return $query;
    }

    /** @return TModel */
    public function newModel(): Model
    {
        $class = $this->model();

        return new $class;
    }

    public function all(array $columns = ['*']): Collection
    {
        /** @var Collection<int, TModel> $models */
        $models = $this->query()->get($columns);

        return $models;
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->applyFilters($this->query(), $filters)
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return $this->query()->with($relations)->find($id);
    }

    public function findOrFail(int $id, array $relations = []): Model
    {
        return $this->query()->with($relations)->findOrFail($id);
    }

    public function findBy(string $column, mixed $value, array $relations = []): ?Model
    {
        return $this->query()->with($relations)->where($column, $value)->first();
    }

    public function create(array $attributes): Model
    {
        return $this->query()->create($attributes);
    }

    public function update(Model $model, array $attributes): Model
    {
        $model->fill($attributes)->save();

        return $model->refresh();
    }

    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    public function existsBy(string $column, mixed $value): bool
    {
        return $this->query()->where($column, $value)->exists();
    }

    /**
     * Apply allow-listed filters, free-text search, sorting and eager loads.
     *
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($this->filterable as $column) {
            if (! array_key_exists($column, $filters) || $filters[$column] === null || $filters[$column] === '') {
                continue;
            }

            $value = $filters[$column];

            is_array($value)
                ? $query->whereIn($column, $value)
                : $query->where($column, $value);
        }

        if (! empty($filters['search']) && $this->searchable !== []) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']).'%';

            $query->where(function (Builder $inner) use ($term): void {
                foreach ($this->searchable as $column) {
                    $inner->orWhere($column, 'like', $term);
                }
            });
        }

        if (! empty($filters['from'])) {
            $query->where($this->dateColumn(), '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where($this->dateColumn(), '<=', $filters['to']);
        }

        return $this->applySort($query, $filters['sort'] ?? $this->defaultSort);
    }

    /**
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    protected function applySort(Builder $query, string $sort): Builder
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-+');

        if (! in_array($column, $this->sortable, true)) {
            $column = ltrim($this->defaultSort, '-+');
            $direction = str_starts_with($this->defaultSort, '-') ? 'desc' : 'asc';
        }

        return $query->orderBy($column, $direction);
    }

    protected function dateColumn(): string
    {
        return 'created_at';
    }
}
