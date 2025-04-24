<?php

namespace Obuchmann\OdooJsonRpc\Odoo\Models;

use JetBrains\PhpStorm\ExpectedValues;
use Obuchmann\OdooJsonRpc\Odoo\OdooModel;
use Obuchmann\OdooJsonRpc\Odoo\Request\RequestBuilder;

class ModelQuery
{
    protected array $with = [];

    public function __construct(
        protected OdooModel $model,
        protected RequestBuilder $builder,
    )
    {
    }

    private function newInstance(object $values): OdooModel
    {
        $class = get_class($this->model);
        $hydratedModel = $class::hydrate($values);
        return $hydratedModel;
    }


    public function can(string $permission): bool
    {
        return $this->builder->can($permission);
    }

    public function with(string ...$relations): static
    {
        $this->with = array_unique(array_merge($this->with, $relations));
        return $this;
    }

    public function get(): array
    {
        $results = $this->builder->get();

        if ($this->builder->hasGroupBy()) {
             return $results;
        }

        $models = [];
        foreach ($results as $item) {
            if (is_object($item)) {
                 $models[] = $this->newInstance($item);
            }
        }

        if (!empty($this->with) && !empty($models)) {
             $modelClass = get_class($this->model);
             $loadedModels = $modelClass::loadRelations($models, ...$this->with);
             return is_array($loadedModels) ? $loadedModels : iterator_to_array($loadedModels);
        }

        return $models;
    }


    public function first(): ?OdooModel
    {
        $item = $this->builder->first();
        if (null !== $item && is_object($item)) {
            $model = $this->newInstance($item);

            if (!empty($this->with) && $model->exists()) {
                 $model->load(...$this->with);
            }
            return $model;
        }
        return null;
    }

    public function count(): int
    {
        return $this->builder->count();
    }

    public function delete(): bool
    {
        return $this->builder->delete();
    }

    public function update(array $values): bool
    {
        unset($values['id']);
        if (empty($values)) {
            return true;
        }
        return $this->builder->update($values);
    }

    public function where(string $field, string $operator, $value): static
    {
        $this->builder->where($field, $operator, $value);
        return $this;
    }

    public function orWhere(string $field, string $operator, $value): static
    {
        $this->builder->orWhere($field, $operator, $value);
        return $this;
    }

    public function orderBy(string $order, #[ExpectedValues(['asc', 'desc'])] string $direction = 'asc'): static
    {
        $this->builder->orderBy($order, $direction);
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->builder->offset($offset);
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->builder->limit($limit);
        return $this;
    }

    public function fields(array $fields): static
    {
        $this->builder->fields($fields);
        return $this;
    }

    public function groupBy(array $groupBy): static
    {
        $this->builder->groupBy($groupBy);
        return $this;
    }

     public function hasGroupBy(): bool
     {
        return $this->builder->hasGroupBy();
     }

}