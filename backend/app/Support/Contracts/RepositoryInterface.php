<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract every concrete repository fulfils.
 *
 * Controllers and services depend on this abstraction rather than on Eloquent
 * directly, which keeps the persistence mechanism swappable and makes the
 * service layer trivially mockable in unit tests.
 *
 * @template TModel of Model
 */
interface RepositoryInterface
{
    /** @return Collection<int, TModel> */
    public function all(array $columns = ['*']): Collection;

    /** @return LengthAwarePaginator<TModel> */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /** @return TModel|null */
    public function find(int $id, array $relations = []): ?Model;

    /**
     * @return TModel
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $id, array $relations = []): Model;

    /** @return TModel */
    public function create(array $attributes): Model;

    /** @param TModel $model */
    public function update(Model $model, array $attributes): Model;

    /** @param TModel $model */
    public function delete(Model $model): bool;

    public function existsBy(string $column, mixed $value): bool;
}
