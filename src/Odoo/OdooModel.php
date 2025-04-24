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
use Traversable;

class OdooModel
{
    use HasFields;

    private static ?Odoo $odooInstance = null;
    private static ?string $modelNameCache = null;

    private static array $relationAttributesCache = [];

    protected array $originalAttributes = [];

    protected array $loadedRelations = [];

    public static function boot(Odoo $odoo): void
    {
        self::$odooInstance = $odoo;
    }

    protected static function odoo(): Odoo
    {
        if (null === self::$odooInstance) {
            throw new RuntimeException('OdooModel has not been booted. Call OdooModel::boot() first, usually done via OdooServiceProvider.');
        }
        return self::$odooInstance;
    }

    public static function model(): string
    {
        if (null === self::$modelNameCache) {
            $reflectionClass = new \ReflectionClass(static::class);
            $modelAttributes = $reflectionClass->getAttributes(Model::class);
            if (empty($modelAttributes)) {
                 throw new ConfigurationException("Missing #[Model] attribute on class " . static::class);
            }
            $modelInstance = $modelAttributes[0]->newInstance();
            self::$modelNameCache = $modelInstance->name;
        }
        return self::$modelNameCache;
    }

    public static function listFields(?array $fields = null, ?array $attributes = ['string', 'type', 'relation']): object
    {
        return self::odoo()->fieldsGet(static::model(), $fields, $attributes);
    }

    public static function find(int $id, ?array $fields = null): ?static
    {
        $fields ??= static::fieldNames();
        $odooData = self::odoo()->find(static::model(), $id, $fields);
        if (null === $odooData) {
            return null;
        }
        return static::hydrate($odooData);
    }

    public static function findMany(array $ids, ?array $fields = null): array
    {
        if (empty($ids)) {
            return [];
        }
        $fields ??= static::fieldNames();
        $odooData = self::odoo()->read(static::model(), $ids, $fields);
        return array_map(fn($item) => static::hydrate($item), $odooData);
    }

    public static function query(): Odoo\Models\ModelQuery
    {
        $builder = self::odoo()->model(static::model())->fields(static::fieldNames());
        return new Odoo\Models\ModelQuery(static::newInstance(), $builder);
    }

    public static function all(): array
    {
        return static::query()->get();
    }

    #[Key]
    #[Field('id')]
    public ?int $id = null;

    public function exists(): bool
    {
        return isset($this->id) && $this->id > 0;
    }

    public function save(): static
    {
        $values = (array) static::dehydrate($this);

        unset($values['id']);

        if (empty($values) && $this->exists()) {
            return $this;
        }

        if ($this->exists()) {
            $updateResponse = self::odoo()->write(static::model(), [$this->id], $values);
            if (false === $updateResponse) {
                throw new OdooModelException("Failed to update model [" . static::class . "] with ID: " . $this->id);
            }
        } else {
            $createResponse = self::odoo()->create(static::model(), $values);
            if (false === $createResponse || !is_int($createResponse) || $createResponse <= 0) {
                 throw new OdooModelException("Failed to create model [" . static::class . "]. Response: " . print_r($createResponse, true));
            }
            $this->id = $createResponse;
        }

        return $this;
    }

    public function delete(): bool
    {
        if (!$this->exists()) {
            throw new OdooModelException("Cannot delete a non-existent model.");
        }
        $result = self::odoo()->unlink(static::model(), [$this->id]);
        if ($result) {
            $this->id = null;
        }
        return $result;
    }


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
            $hasManyAttrs = $property->getAttributes(HasMany::class);

            if (!empty($belongsToAttrs) && !empty($hasManyAttrs)) {
                 throw new ConfigurationException("Property {$property->getName()} on {$class} cannot have both BelongsTo and HasMany attributes.");
            }
            if (count($belongsToAttrs) > 1) {
                 throw new ConfigurationException("Property {$property->getName()} on {$class} cannot have multiple BelongsTo attributes.");
            }
             if (count($hasManyAttrs) > 1) {
                 throw new ConfigurationException("Property {$property->getName()} on {$class} cannot have multiple HasMany attributes.");
            }

            if (!empty($belongsToAttrs)) {
                $relations[$property->getName()] = [
                    'type' => 'BelongsTo',
                    'attribute' => $belongsToAttrs[0]->newInstance(),
                    'property' => $property,
                ];
            }

            if (!empty($hasManyAttrs)) {
                $type = $property->getType();
                 if (!$type || !($type->getName() === 'array' || is_subclass_of($type->getName(), Traversable::class) || (string)$type === 'iterable')) {
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

    public function load(string ...$relations): static
    {
        if (!$this->exists()) {
            throw new OdooModelException("Cannot load relations on a non-existent model [" . static::class . "].");
        }
        static::loadRelations([$this], ...$relations);
        return $this;
    }

    public function relationLoaded(string $relationName): bool
    {
        $baseRelation = explode('.', $relationName, 2)[0];
        return in_array($baseRelation, $this->loadedRelations);
    }

    public function executeKw(string $method, array $args = [], array $kwargs = []): mixed
    {
        if (!$this->exists()) {
            throw new OdooModelException("Cannot call executeKw on a non-existent model [" . static::class . "].");
        }
        $odooArgs = [ [$this->id] ];
        if (!empty($args)) {
            $odooArgs = array_merge($odooArgs, $args);
        }

        return self::odoo()->executeKw(static::model(), $method, $odooArgs, $kwargs);
    }

    public function fill(iterable $attributes): static
    {
        $reflectionClass = new \ReflectionClass(static::class);

        foreach ($attributes as $key => $value) {
            if ($reflectionClass->hasProperty($key)) {
                $property = $reflectionClass->getProperty($key);
                if ($property->isPublic()) {
                    $this->{$key} = $value;
                } else {
                }
            } else {
                throw new UndefinedPropertyException("Property {$key} does not exist on model " . static::class);
            }
        }
        return $this;
    }


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

        $parsedRelations = self::parseRelationStrings($relations);

        $allRelationDefs = $modelClass::getRelationAttributes();

        foreach ($parsedRelations as $baseRelation => $nestedRelationsToLoad) {
            if (!isset($allRelationDefs[$baseRelation])) {
                throw new ConfigurationException("Relation '{$baseRelation}' not defined on model " . $modelClass);
            }

            $definition = $allRelationDefs[$baseRelation];
            $attribute = $definition['attribute'];
            $relatedClass = $attribute->related;

            if (!class_exists($relatedClass) || !is_subclass_of($relatedClass, OdooModel::class)) {
                 throw new ConfigurationException("Relation '{$baseRelation}' on {$modelClass} points to an invalid OdooModel class '{$relatedClass}'.");
            }

            $modelsToLoadRelationOn = array_filter(
                 $modelsArray,
                 fn(OdooModel $model) => !in_array($baseRelation, $model->loadedRelations)
            );


            if (!empty($modelsToLoadRelationOn)) {
                 match ($definition['type']) {
                     'BelongsTo' => static::loadBelongsToRelation($modelsToLoadRelationOn, $baseRelation, $attribute),
                     'HasMany'   => static::loadHasManyRelation($modelsToLoadRelationOn, $baseRelation, $attribute),
                     default     => throw new RuntimeException("Unknown relation type {$definition['type']}"),
                 };

                 foreach ($modelsToLoadRelationOn as $model) {
                    $model->loadedRelations[] = $baseRelation;
                 }
             }


            if (!empty($nestedRelationsToLoad)) {
                $relatedModelsToLoadNestedOn = [];
                foreach ($modelsArray as $model) {
                    $relationProperty = $definition['property'];
                    if ($relationProperty->isInitialized($model)) {
                         $relatedData = $relationProperty->getValue($model);

                        if ($relatedData === null) continue;

                        if (is_array($relatedData) || $relatedData instanceof Traversable) {
                            foreach ($relatedData as $relatedItem) {
                                if ($relatedItem instanceof OdooModel && $relatedItem->exists()) {
                                    $relatedModelsToLoadNestedOn[$relatedItem->id] = $relatedItem;
                                }
                            }
                        } elseif ($relatedData instanceof OdooModel && $relatedData->exists()) {
                            $relatedModelsToLoadNestedOn[$relatedData->id] = $relatedData;
                        }
                    }
                }

                if (!empty($relatedModelsToLoadNestedOn)) {
                    $relatedClass::loadRelations($relatedModelsToLoadNestedOn, ...$nestedRelationsToLoad);
                }
            }
        }

        return $modelsArray;
    }

    protected static function parseRelationStrings(array $relations): array
    {
         $parsed = [];
         foreach ($relations as $relation) {
             $segments = explode('.', $relation, 2);
             $baseRelation = $segments[0];
             $nested = $segments[1] ?? null;

             if (!isset($parsed[$baseRelation])) {
                 $parsed[$baseRelation] = [];
             }
             if ($nested !== null && !in_array($nested, $parsed[$baseRelation])) {
                 $parsed[$baseRelation][] = $nested;
             }
         }
         return $parsed;
    }


    protected static function loadBelongsToRelation(array $models, string $relationName, BelongsTo $attribute): void
    {
        if (empty($models)) return;

        $relatedClass = $attribute->related;
        $foreignKeyProperty = $attribute->foreignKey;

        $modelClass = get_class(reset($models));
        $reflectionClass = new ReflectionClass($modelClass);
        if (!$reflectionClass->hasProperty($foreignKeyProperty)) {
             throw new ConfigurationException("BelongsTo relation '{$relationName}' on {$modelClass} defines foreign key property '{$foreignKeyProperty}' which does not exist.");
        }
        $fkIdProperty = $reflectionClass->getProperty($foreignKeyProperty);

        $foreignKeys = [];
        foreach ($models as $model) {
            if ($fkIdProperty->isInitialized($model)) {
                $fkValue = $fkIdProperty->getValue($model);
                if (is_int($fkValue) && $fkValue > 0) {
                    $foreignKeys[$fkValue] = $fkValue;
                }
            }
        }

        $relationProperty = $reflectionClass->getProperty($relationName);
        foreach ($models as $model) {
            if (!$relationProperty->isInitialized($model)) {
                 $relationProperty->setValue($model, null);
            }
        }

        if (empty($foreignKeys)) {
            return;
        }

        $relatedModels = $relatedClass::query()
            ->where('id', 'in', array_values($foreignKeys))
            ->get();

        $relatedDictionary = [];
        foreach ($relatedModels as $relatedModel) {
            if ($relatedModel instanceof OdooModel && $relatedModel->exists()) {
                $relatedDictionary[$relatedModel->id] = $relatedModel;
            }
        }

        foreach ($models as $model) {
             if ($fkIdProperty->isInitialized($model)) {
                $fkValue = $fkIdProperty->getValue($model);
                 if ($fkValue && isset($relatedDictionary[$fkValue])) {
                     $relationProperty->setValue($model, $relatedDictionary[$fkValue]);
                 }
             }
        }
    }

    protected static function loadHasManyRelation(array $models, string $relationName, HasMany $attribute): void
    {
        if (empty($models)) return;

        $relatedClass = $attribute->related;
        $foreignKeyOnRelated = $attribute->foreignKey;

        $parentIds = [];
        foreach ($models as $model) {
             if ($model->exists()) {
                $parentIds[$model->id] = $model->id;
             }
        }

         $modelClass = get_class(reset($models));
         $reflectionClass = new ReflectionClass($modelClass);
         $relationProperty = $reflectionClass->getProperty($relationName);
         foreach ($models as $model) {
              if (!$relationProperty->isInitialized($model)) {
                  $relationProperty->setValue($model, []);
              }
         }

        if (empty($parentIds)) {
            return;
        }

        $relatedModels = $relatedClass::query()
            ->where($foreignKeyOnRelated, 'in', array_values($parentIds))
            ->get();

        $groupedRelated = [];
        foreach ($relatedModels as $relatedModel) {
             if (!$relatedModel instanceof OdooModel) continue;

             $fkValue = self::getForeignKeyValueFromRelated($relatedModel, $foreignKeyOnRelated);

             if ($fkValue !== null && is_int($fkValue)) {
                 if (!isset($groupedRelated[$fkValue])) {
                     $groupedRelated[$fkValue] = [];
                 }
                 $groupedRelated[$fkValue][] = $relatedModel;
             }
        }

        foreach ($models as $model) {
            if ($model->exists() && isset($groupedRelated[$model->id])) {
                $relationProperty->setValue($model, $groupedRelated[$model->id]);
            }
        }
    }

    private static function getForeignKeyValueFromRelated(OdooModel $relatedModel, string $foreignKeyOdooName): ?int
    {
        $relatedReflection = new ReflectionClass($relatedModel);
        foreach ($relatedReflection->getProperties() as $prop) {
            $fieldAttrs = $prop->getAttributes(Field::class);
            if (empty($fieldAttrs)) continue;

            $fieldAttrInstance = $fieldAttrs[0]->newInstance();
            $odooFieldName = $fieldAttrInstance->name ?? $prop->getName();

            if ($odooFieldName === $foreignKeyOdooName) {
                 if (!$prop->isInitialized($relatedModel)) {
                     return null;
                 }
                 $value = $prop->getValue($relatedModel);

                 $isKey = !empty($prop->getAttributes(Key::class));
                 if ($isKey && is_int($value)) {
                     return $value > 0 ? $value : null;
                 }
                 if (is_int($value)) {
                     return $value > 0 ? $value : null;
                 }

                 if (is_array($value) && isset($value[0]) && is_int($value[0])) {
                      return $value[0] > 0 ? $value[0] : null;
                 }

                 return null;
            }
        }
        return null;
    }


    public function equals(OdooModel $other): bool
    {
        if (static::class !== get_class($other)) {
            return false;
        }
        if ($this->id !== $other->id) {
            return false;
        }

        $reflectionClass = new \ReflectionClass(static::class);
        $properties = $reflectionClass->getProperties();

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            if ($property->isStatic() || in_array($propertyName, ['odooInstance', 'modelNameCache', 'relationAttributesCache', 'originalAttributes', 'loadedRelations'])) {
                 continue;
            }

            $thisIsInitialized = $property->isInitialized($this);
            $otherIsInitialized = $property->isInitialized($other);

            if ($thisIsInitialized !== $otherIsInitialized) {
                return false;
            }

            if ($thisIsInitialized) {
                 $thisValue = $property->getValue($this);
                 $otherValue = $property->getValue($other);

                 if ($thisValue !== $otherValue) {
                      if ($thisValue instanceof \DateTimeInterface && $otherValue instanceof \DateTimeInterface) {
                           if ($thisValue->getTimestamp() !== $otherValue->getTimestamp()) {
                               return false;
                           }
                      } elseif (is_object($thisValue) && is_object($otherValue)) {
                            return false;
                      } else {
                           return false;
                      }
                 }
            }
        }
        return true;
    }

}