<?php

namespace Obuchmann\OdooJsonRpc\Odoo;

use Obuchmann\OdooJsonRpc\Attributes\BelongsTo;
use Obuchmann\OdooJsonRpc\Attributes\Field;
use Obuchmann\OdooJsonRpc\Attributes\HasMany;
use Obuchmann\OdooJsonRpc\Attributes\Key;
use Obuchmann\OdooJsonRpc\Attributes\Model;
use Obuchmann\OdooJsonRpc\Exceptions\ConfigurationException;
use Obuchmann\OdooJsonRpc\Exceptions\OdooModelException;
use Obuchmann\OdooJsonRpc\Exceptions\UndefinedPropertyException;
use Obuchmann\OdooJsonRpc\Odoo;
use Obuchmann\OdooJsonRpc\Odoo\Mapping\HasFields;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

class OdooModel
{
    use HasFields;

    private static Odoo $odoo;
    private static ?string $model = null;

    private static array $relationAttributesCache = [];

    public static function boot(Odoo $odoo)
    {
        self::$odoo = $odoo;
    }

    public static function listFields(?array $fields = null): object
    {
        return self::$odoo->fieldsGet(static::model(), $fields);
    }

    public static function find(int $id): ?static
    {
        $odooInstance = self::$odoo->find(static::model(), $id, static::fieldNames());
        if(null === $odooInstance){
            return null;
        }
        return static::hydrate($odooInstance);
    }

    public static function read(array $ids): array
    {
        return array_map(fn($item) => static::hydrate($item), self::$odoo->read(static::model(), $ids, static::fieldNames()));
    }

    protected static function model()
    {
        $reflectionClass = new \ReflectionClass(static::class);
        $model = $reflectionClass->getAttributes(Model::class)[0] ?? throw new ConfigurationException("Missing Model Attribute");

        return $model->newInstance()->name;
    }

    public static function query()
    {
        //TODO: Lazy evaluate fields only for queries that needs feelds :low
        return new Odoo\Models\ModelQuery(static::newInstance(), self::$odoo->model(static::model())->fields(static::fieldNames()));
    }

    public static function all()
    {
        return static::query()->get();
    }

    public int $id;

    public function exists()
    {
        return isset($this->id);
    }

    /**
     * @return $this
     */
    public function save(): static
    {
        if ($this->exists()) {
            $updateResponse = self::$odoo->write(static::model(), [$this->id], (array)static::dehydrate($this));
            if (false === $updateResponse) {
                throw new OdooModelException("Failed to update model");
            }
        } else {
            $createResponse = self::$odoo->create(static::model(), (array)static::dehydrate($this));
            if (false === $createResponse) {
                throw new OdooModelException("Failed to create model");
            }
            $this->id = $createResponse;
        }

        return $this;
    }

    /**
     * Get relationship definitions (BelongsTo, HasMany) from attributes.
     * Caches the results for performance.
     *
     * @return array<string, array{type: string, attribute: BelongsTo|HasMany, property: ReflectionProperty}>
     */
    protected static function getRelationAttributes(): array
    {
        $class = static::class;
        if (isset(self::$relationAttributesCache[$class])) {
            return self::$relationAttributesCache[$class];
        }

        $relations = [];
        $reflectionClass = new ReflectionClass($class);
        $properties = $reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $belongsToAttrs = $property->getAttributes(BelongsTo::class);
            if (!empty($belongsToAttrs)) {
                if (count($belongsToAttrs) > 1) {
                    throw new ConfigurationException("Property {$property->getName()} on {$class} cannot have multiple BelongsTo attributes.");
                }
                $relations[$property->getName()] = [
                    'type' => 'BelongsTo',
                    'attribute' => $belongsToAttrs[0]->newInstance(),
                    'property' => $property,
                ];
                continue;
            }

            $hasManyAttrs = $property->getAttributes(HasMany::class);
            if (!empty($hasManyAttrs)) {
                if (count($hasManyAttrs) > 1) {
                    throw new ConfigurationException("Property {$property->getName()} on {$class} cannot have multiple HasMany attributes.");
                }
                $type = $property->getType();
                if (!$type || !($type->getName() === 'array' || is_subclass_of($type->getName(), \Traversable::class))) {
                }
                $relations[$property->getName()] = [
                    'type' => 'HasMany',
                    'attribute' => $hasManyAttrs[0]->newInstance(),
                    'property' => $property,
                ];
            }
        }

        self::$relationAttributesCache[$class] = $relations;
        return $relations;
    }

    /**
     * Load relationships for the current model instance, supporting nested loading.
     *
     * @param string ...$relations Names of the relationship properties to load.
     * @return $this
     * @throws ConfigurationException|OdooModelException
     */
    public function load(string ...$relations): static
    {
        if (!$this->exists()) {
            throw new OdooModelException("Cannot load relations on a non-existent model.");
        }
        static::loadRelations([$this], ...$relations);
        return $this;
    }

    public function executeKw(string $method, array $args = [])
    {
        return self::$odoo->executeKw(static::model(), $method, [$this->id,...$args]);
    }

    public function fill(iterable $properties)
    {
        $reflectionClass = new \ReflectionClass(static::class);

        foreach ($properties as $name => $value) {
            if($reflectionClass->hasProperty($name)){
                $this->{$name} = $value;
            }else {
                throw new UndefinedPropertyException("Property $name not defined");
            }
        }

        return $this;
    }


    /**
     * Eager load relationships for a collection of models, supporting nested loading.
     *
     * @param iterable<OdooModel> $models The collection of models.
     * @param string ...$relations Names of the relationship properties to load.
     * @return iterable<OdooModel> The collection with relations loaded.
     * @throws ConfigurationException|RuntimeException
     */
    public static function loadRelations(iterable $models, string ...$relations): iterable
    {
        $modelsArray = is_array($models) ? $models : iterator_to_array($models);
        if (empty($modelsArray) || empty($relations)) {
            return $modelsArray;
        }

        $firstModel = reset($modelsArray);
        if (!$firstModel instanceof OdooModel) {
            return $modelsArray;
        }
        $modelClass = get_class($firstModel);

        $parsedRelations = [];
        foreach ($relations as $relation) {
            $segments = explode('.', $relation, 2);
            $baseRelation = $segments[0];
            $nested = isset($segments[1]) ? $segments[1] : null;

            if (!isset($parsedRelations[$baseRelation])) {
                $parsedRelations[$baseRelation] = [];
            }

            if ($nested !== null) {
                 if (!in_array($nested, $parsedRelations[$baseRelation])) {
                    $parsedRelations[$baseRelation][] = $nested;
                 }
            }
        }

        $allRelationDefs = $modelClass::getRelationAttributes();

        foreach ($parsedRelations as $baseRelation => $nestedRelationsToLoad) {
            $alreadyLoaded = true;
            foreach($modelsArray as $model) {
                if (!isset($model->{$baseRelation})) {
                     $alreadyLoaded = false;
                     break;
                }
            }
            if ($alreadyLoaded) continue;

            if (!isset($allRelationDefs[$baseRelation])) {
                throw new ConfigurationException("Relation '{$baseRelation}' not defined on " . $modelClass);
            }

            $definition = $allRelationDefs[$baseRelation];
            $attribute = $definition['attribute'];
            $relatedClass = $attribute->related;

            if (!class_exists($relatedClass) || !is_subclass_of($relatedClass, OdooModel::class)) {
                throw new ConfigurationException("Relation '{$baseRelation}' on {$modelClass} points to an invalid OdooModel class '{$relatedClass}'.");
            }

            match ($definition['type']) {
                'BelongsTo' => static::loadBelongsToRelation($modelsArray, $baseRelation, $attribute),
                'HasMany'   => static::loadHasManyRelation($modelsArray, $baseRelation, $attribute),
                default     => throw new RuntimeException("Unknown relation type {$definition['type']}"),
            };

            if (!empty($nestedRelationsToLoad)) {
                $relatedModelsToLoadNestedOn = [];
                foreach ($modelsArray as $model) {
                    $relatedData = $model->{$baseRelation} ?? null;

                    if ($relatedData === null) continue;

                    if (is_array($relatedData)) {
                        foreach($relatedData as $relatedItem){
                            if($relatedItem instanceof OdooModel && $relatedItem->exists()){
                                 $relatedModelsToLoadNestedOn[$relatedItem->id] = $relatedItem;
                            }
                        }
                    } elseif ($relatedData instanceof OdooModel && $relatedData->exists()) {
                         $relatedModelsToLoadNestedOn[$relatedData->id] = $relatedData;
                    }
                }

                if (!empty($relatedModelsToLoadNestedOn)) {
                    $relatedClass::loadRelations($relatedModelsToLoadNestedOn, ...$nestedRelationsToLoad);
                }
            }
        }

        return $modelsArray;
    }

    /**
     * Helper to load a BelongsTo relation for a collection of models.
     *
     * @param array<OdooModel> $models
     * @param string $relationName
     * @param BelongsTo $attribute
     * @return void
     */
    protected static function loadBelongsToRelation(array $models, string $relationName, BelongsTo $attribute): void
    {
        $relatedClass = $attribute->related;
        $foreignKeyProperty = $attribute->foreignKey;

        $foreignKeys = [];
        foreach ($models as $model) {
             if (property_exists($model, $foreignKeyProperty) && isset($model->{$foreignKeyProperty})) {
                $fkValue = $model->{$foreignKeyProperty};
                if (is_int($fkValue) && $fkValue > 0) {
                    $foreignKeys[$fkValue] = $fkValue;
                }
             } else {
                throw new ConfigurationException("Property {$foreignKeyProperty} not found on model " . get_class($model));
             }
        }

        if (empty($foreignKeys)) {
             foreach ($models as $model) {
                 $model->{$relationName} = null;
             }
            return;
        }

        $relatedModels = $relatedClass::query()
            ->where('id', 'in', array_values($foreignKeys))
            ->get();

        $relatedDictionary = [];
        foreach ($relatedModels as $relatedModel) {
            $relatedDictionary[$relatedModel->id] = $relatedModel;
        }

        foreach ($models as $model) {
            $fkValue = property_exists($model, $foreignKeyProperty) ? ($model->{$foreignKeyProperty} ?? null) : null;
             if ($fkValue && isset($relatedDictionary[$fkValue])) {
                 $model->{$relationName} = $relatedDictionary[$fkValue];
             } else {
                 $model->{$relationName} = null;
             }
        }
    }

     /**
     * Helper to load a HasMany relation for a collection of models.
     *
     * @param array<OdooModel> $models
     * @param string $relationName
     * @param HasMany $attribute
     * @return void
     */
    protected static function loadHasManyRelation(array $models, string $relationName, HasMany $attribute): void
    {
        $relatedClass = $attribute->related;
        $foreignKeyOnRelated = $attribute->foreignKey;

        $parentIds = [];
        foreach ($models as $model) {
             if ($model->exists()) {
                $parentIds[$model->id] = $model->id;
             }
        }

        if (empty($parentIds)) {
             foreach ($models as $model) {
                 $model->{$relationName} = [];
             }
            return;
        }

        $relatedModels = $relatedClass::query()
            ->where($foreignKeyOnRelated, 'in', array_values($parentIds))
            ->get();

        $groupedRelated = [];
        foreach ($relatedModels as $relatedModel) {
            $fkValue = null;
            $relatedReflection = new ReflectionClass($relatedModel);
            foreach ($relatedReflection->getProperties() as $prop) {
                 $fieldAttrs = $prop->getAttributes(Field::class);
                 $keyAttrs = $prop->getAttributes(Key::class);
                 if (!empty($fieldAttrs)) {
                     $fieldAttrInstance = $fieldAttrs[0]->newInstance();
                     $odooFieldName = $fieldAttrInstance->name ?? $prop->getName();
                     if ($odooFieldName === $foreignKeyOnRelated) {
                          if (!empty($keyAttrs) && property_exists($relatedModel, $prop->getName())) {
                            $fkValue = $relatedModel->{$prop->getName()};
                            break;
                          }
                          elseif (property_exists($relatedModel, $prop->getName()) && is_array($relatedModel->{$prop->getName()}) && isset($relatedModel->{$prop->getName()}[0])) {
                             $fkValue = $relatedModel->{$prop->getName()}[0];
                             break;
                          }
                           elseif (property_exists($relatedModel, $prop->getName()) && is_int($relatedModel->{$prop->getName()})) {
                             $fkValue = $relatedModel->{$prop->getName()};
                             break;
                           }
                     }
                 }
            }
            if ($fkValue !== null && is_int($fkValue)) {
                $groupedRelated[$fkValue][] = $relatedModel;
            }
        }

        foreach ($models as $model) {
            if (isset($groupedRelated[$model->id])) {
                $model->{$relationName} = $groupedRelated[$model->id];
            } else {
                $model->{$relationName} = [];
            }
        }
    }


    public function equals(OdooModel $model)
    {
        $reflectionClass = new \ReflectionClass(static::class);
        $properties = $reflectionClass->getProperties();

        foreach ($properties as $property) {
            if($property->isInitialized($this)){
                if(!$property->isInitialized($model)){
                    return false;
                }
                if($this->{$property->name} !== $model->{$property->name}){
                    return false;
                }
            }else{
                if($property->isInitialized($model)){
                    return false;
                }
            }

        }
        return true;
    }

}
