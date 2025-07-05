<?php


namespace Obuchmann\OdooJsonRpc\Odoo\Models;


use JetBrains\PhpStorm\ExpectedValues;
use Obuchmann\OdooJsonRpc\Odoo\OdooModel;
use Obuchmann\OdooJsonRpc\Odoo\Request\RequestBuilder;

class ModelQuery
{
    protected array $with = [];


   public function with(array $relations): static
   {
       $this->with = $relations;
       return $this;
   }

   public function __construct(
       protected OdooModel $model,
       protected RequestBuilder $builder,
   )
   {
    }

    private function newInstance(object $values): OdooModel
    {
        return $this->model->hydrate($values);
    }


    public function can(string $permission): bool
    {
        return $this->builder->can($permission);
    }

    public function get(): array
    {
        $items = array_map(fn($item) => $this->newInstance($item), $this->builder->get());

        if (!empty($this->with)) {
            \Obuchmann\OdooJsonRpc\Odoo\Mapping\EagerLoader::loadRelations($items, $this->with);
        }
        return $items;
    }

    public function first(): ?OdooModel
    {
        $item = $this->builder->first();
       if ($item && !empty($this->with)) {
           $model = $this->newInstance($item);
           \Obuchmann\OdooJsonRpc\Odoo\Mapping\EagerLoader::loadRelations([$model], $this->with);
           return $model;
       }
        return $item ? $this->newInstance($item) : null;
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
        return $this->builder->update($values);
    }

    public function where(string $field, string $operator, $value)
    {
        $this->builder->where($field, $operator, $value);
        return $this;
    }

    public function orWhere(string $field, string $operator, $value)
    {
        $this->builder->orWhere($field, $operator, $value);
        return $this;
    }

    public function orderBy(string $order, #[ExpectedValues(['asc', 'desc'])] string $direction = 'asc')
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

    public function fields(array $fields)
    {
        $this->builder->fields($fields);
        return $this;
    }
}