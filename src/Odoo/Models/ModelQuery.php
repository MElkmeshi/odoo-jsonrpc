<?php

namespace Obuchmann\OdooJsonRpc\Odoo\Models;

use JetBrains\PhpStorm\ExpectedValues;
use Obuchmann\OdooJsonRpc\Odoo\OdooModel;
use Obuchmann\OdooJsonRpc\Odoo\Request\RequestBuilder;

class ModelQuery
{
    /** @var array<string> Relations to eager load */
    protected array $with = [];

    public function __construct(
        protected OdooModel $model,
        protected RequestBuilder $builder,
    )
    {
    }

    /**
     * Create a new instance of the model from raw Odoo data.
     * @param object $values
     * @return OdooModel
     */
    private function newInstance(object $values): OdooModel
    {
        // Use the hydrate method from the *specific* model class
        $class = get_class($this->model);
        return $class::hydrate($values);
    }


    public function can(string $permission): bool
    {
        return $this->builder->can($permission);
    }

    /**
     * Specify relationships to eager load.
     *
     * @param string ...$relations
     * @return $this
     */
    public function with(string ...$relations): static
    {
        $this->with = array_unique(array_merge($this->with, $relations));
        return $this;
    }

    /**
     * Execute the query and get the results.
     *
     * @return array<OdooModel>
     */
        /**
     * Execute the query and get the results.
     * Detects if groupBy is set and calls the appropriate read method.
     *
     * @return array<OdooModel|object> Returns OdooModel instances if not grouping,
     *                                or plain objects/arrays from read_group if grouping.
     */
    public function get(): array
    {
        // The RequestBuilder's get() method handles the logic
        // of calling searchRead vs readGroup based on whether groupBy is set.
        $results = $this->builder->get();

        // If NOT grouping, results are raw data suitable for hydration.
        // If grouping, results are already the grouped summary objects.
        // We need to check if grouping was performed to decide whether to hydrate.

        // Access the underlying builder to check if groupBy was used.
        // This relies on RequestBuilder having a way to check this, like hasGroupBy()
        // Or examining the internal state if HasGroupBy trait doesn't provide a public checker
        // Let's assume RequestBuilder's get already returned the correct format.
        // If the builder->get() returned raw data (from searchRead), hydrate it.
        // If it returned grouped data (from readGroup), return it as is.

        // Check if the result looks like grouped data (contains __count or __domain usually)
        // Or rely on the fact that hydration would likely fail for grouped data structure
        if ($this->builder->hasGroupBy() ) { // Assuming RequestBuilder has this public check from the trait
             return $results; // Return the raw grouped data
        } else {
            // Hydrate only if it was a standard searchRead result
            $models = array_map(fn($item) => $this->newInstance($item), $results);
             // Eager load relations if requested
            if (!empty($this->with)) {
                 $modelClass = get_class($this->model);
                 // Ensure loadRelations returns array, not Collection if used internally
                 $loadedModels = $modelClass::loadRelations($models, ...$this->with);
                 return is_array($loadedModels) ? $loadedModels : iterator_to_array($loadedModels);
            }
            return $models;
        }
    }


    /**
     * Execute the query and get the first result.
     *
     * @return OdooModel|null
     */
    public function first(): ?OdooModel
    {
        $item = $this->builder->first(); // Returns stdClass object or null
        if (null !== $item) {
            $model = $this->newInstance($item);

            // Eager load relations if requested
             if (!empty($this->with)) {
                 // Use the instance load method for a single model
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
    /**
     * Specify fields to group the results by.
     * This triggers the use of Odoo's read_group method.
     *
     * @param array $groupBy Odoo field names to group by.
     * @return static
     */
    public function groupBy(array $groupBy): static
    {
        $this->builder->groupBy($groupBy); // Delegate to RequestBuilder
        return $this;
    }

     /**
      * Check if a group by clause has been added.
      * Useful for determining return type from get().
      *
      * @return bool
      */
     public function hasGroupBy(): bool
     {
        return $this->builder->hasGroupBy(); // Delegate check to RequestBuilder
     }

}