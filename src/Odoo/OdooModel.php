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
    private static array $fieldNamesCache = []; // Cache for field names

    protected array $originalAttributes = [];
    protected array $loadedRelations = [];
    // Holds raw data used during hydration, accessible for relation loading
    public array $_hydratedData = [];


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
        $class = static::class;
        if (!isset(self::$modelNameCache[$class])) {
            $reflectionClass = new \ReflectionClass($class);
            $modelAttributes = $reflectionClass->getAttributes(Model::class);
            if (empty($modelAttributes)) {
                 throw new ConfigurationException("Missing #[Model] attribute on class " . $class);
            }
            $modelInstance = $modelAttributes[0]->newInstance();
            self::$modelNameCache[$class] = $modelInstance->name;
        }
        return self::$modelNameCache[$class];
    }

    /**
     * Calculates and caches the list of Odoo field names to fetch for this model.
     * Includes fields marked with #[Field] and the odooRelationshipField from #[HasMany].
     */
    protected static function fieldNames(): array
    {
        $class = static::class;
        if (isset(self::$fieldNamesCache[$class])) {
            return self::$fieldNamesCache[$class];
        }

        $fieldNames = [];
        $reflectionClass = new \ReflectionClass($class);
        $properties = $reflectionClass->getProperties();

        foreach ($properties as $property) {
            // Get fields marked with #[Field]
            $fieldAttrs = $property->getAttributes(Field::class);
            foreach ($fieldAttrs as $attribute) {
                $fieldInstance = $attribute->newInstance();
                $fieldNames[] = $fieldInstance->name ?? $property->getName();
            }

            // Get BelongsTo foreign key fields if they have #[Field]
             $belongsToAttrs = $property->getAttributes(BelongsTo::class);
             if (!empty($belongsToAttrs)) {
                  $belongsToInstance = $belongsToAttrs[0]->newInstance();
                  $fkPropertyName = $belongsToInstance->foreignKey;
                  if ($reflectionClass->hasProperty($fkPropertyName)) {
                      $fkProperty = $reflectionClass->getProperty($fkPropertyName);
                      $fkFieldAttrs = $fkProperty->getAttributes(Field::class);
                      if (!empty($fkFieldAttrs)) {
                          $fkFieldInstance = $fkFieldAttrs[0]->newInstance();
                          $odooFieldName = $fkFieldInstance->name ?? $fkProperty->getName();
                          $fieldNames[] = $odooFieldName;
                      }
                  }
             }

            // Get HasMany relationship ID list field if defined
            $hasManyAttrs = $property->getAttributes(HasMany::class);
            if (!empty($hasManyAttrs)) {
                /** @var HasMany $hasManyInstance */
                $hasManyInstance = $hasManyAttrs[0]->newInstance();
                // *** ADD the Odoo field holding the IDs ***
                if ($hasManyInstance->odooRelationshipField !== null) {
                    $fieldNames[] = $hasManyInstance->odooRelationshipField;
                }
                // Check if the PHP property itself is mapped via Field to this odooRelationshipField
                // This handles cases where the user wants the ID list directly on the HasMany property
                $propFieldAttrs = $property->getAttributes(Field::class);
                 foreach($propFieldAttrs as $propFieldAttr) {
                      $propFieldInstance = $propFieldAttr->newInstance();
                      $propOdooFieldName = $propFieldInstance->name ?? $property->getName();
                      if ($hasManyInstance->odooRelationshipField !== null && $propOdooFieldName === $hasManyInstance->odooRelationshipField) {
                           $fieldNames[] = $propOdooFieldName; // Already added above, but ensures it's included if mapped directly
                      }
                 }
            }
        }

        // Always include 'id'
        $fieldNames[] = 'id';
        self::$fieldNamesCache[$class] = array_values(array_unique($fieldNames)); // Ensure unique and re-index
        return self::$fieldNamesCache[$class];
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
        // Clear internal hydration data after save? Or re-fetch?
        $this->_hydratedData = []; // Clear potentially stale data
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
            $this->_hydratedData = [];
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
            if ($key === '_hydratedData') continue; // Don't allow filling internal property

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
        // Filling might invalidate hydrated data if relations depend on it
        $this->_hydratedData = [];
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
            $relationProperty = $definition['property']; // ReflectionProperty

            if (!class_exists($relatedClass) || !is_subclass_of($relatedClass, OdooModel::class)) {
                 throw new ConfigurationException("Relation '{$baseRelation}' on {$modelClass} points to an invalid OdooModel class '{$relatedClass}'.");
            }

            $modelsToLoadRelationOn = array_filter(
                 $modelsArray,
                 fn(OdooModel $model) => !in_array($baseRelation, $model->loadedRelations)
            );

            if (!empty($modelsToLoadRelationOn)) {
                 match ($definition['type']) {
                     'BelongsTo' => static::loadBelongsToRelation($modelsToLoadRelationOn, $relationProperty->getName(), $attribute),
                     'HasMany'   => static::loadHasManyRelation($modelsToLoadRelationOn, $relationProperty->getName(), $attribute), // Pass attribute instance
                     default     => throw new RuntimeException("Unknown relation type {$definition['type']}"),
                 };

                 foreach ($modelsToLoadRelationOn as $model) {
                    $model->loadedRelations[] = $baseRelation;
                 }
             }

            if (!empty($nestedRelationsToLoad)) {
                $relatedModelsToLoadNestedOn = [];
                foreach ($modelsArray as $model) {
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

    /**
     * Loads HasMany relation based on IDs fetched previously on the parent model.
     */
    protected static function loadHasManyRelation(array $models, string $relationName, HasMany $attribute): void
    {
        if (empty($models)) return;

        /** @var class-string<OdooModel> $relatedClass */
        $relatedClass = $attribute->related;
        $odooRelationshipField = $attribute->odooRelationshipField; // e.g., 'product_variant_ids'

        if ($odooRelationshipField === null) {
            throw new ConfigurationException("Cannot load HasMany relation '{$relationName}' on " . static::class . " using ID list method because 'odooRelationshipField' is not defined in the #[HasMany] attribute.");
        }

        $modelClass = get_class(reset($models));
        $reflectionClass = new ReflectionClass($modelClass);
        $relationProperty = $reflectionClass->getProperty($relationName);

        // Initialize relation to empty array for all models initially
        foreach ($models as $model) {
             if (!$relationProperty->isInitialized($model)) {
                 $relationProperty->setValue($model, []);
             }
        }

        // Collect all related IDs needed across all parent models
        $allRelatedIds = [];
        $modelIdToRelatedIdsMap = []; // Map parent ID -> list of its related IDs

        foreach ($models as $model) {
            // Access the previously fetched ID list from the raw hydrated data
            // This assumes fieldNames() included $odooRelationshipField and it was hydrated
            if (isset($model->_hydratedData[$odooRelationshipField]) && is_array($model->_hydratedData[$odooRelationshipField])) {
                $relatedIds = $model->_hydratedData[$odooRelationshipField];
                 // Filter out potential false values or non-integers if Odoo returns them
                 $relatedIds = array_filter($relatedIds, fn($id) => is_int($id) && $id > 0);

                if (!empty($relatedIds)) {
                    $modelIdToRelatedIdsMap[$model->id] = $relatedIds;
                    $allRelatedIds = array_merge($allRelatedIds, $relatedIds);
                } else {
                     $modelIdToRelatedIdsMap[$model->id] = []; // Ensure entry exists even if empty
                }
            } else {
                 $modelIdToRelatedIdsMap[$model->id] = []; // Field missing or not array
            }
        }

        $uniqueRelatedIds = array_values(array_unique($allRelatedIds));

        if (empty($uniqueRelatedIds)) {
            // No related IDs found for any model, relation remains empty array
            return;
        }

        // Fetch all required related models in a single query
        $relatedModels = $relatedClass::query()
            ->where('id', 'in', $uniqueRelatedIds)
            ->get(); // Returns array of related OdooModel instances

        // Build a dictionary of fetched related models keyed by their ID
        $relatedDictionary = [];
        foreach ($relatedModels as $relatedModel) {
             if ($relatedModel instanceof OdooModel && $relatedModel->exists()) {
                 $relatedDictionary[$relatedModel->id] = $relatedModel;
             }
        }

        // Assign the fetched models back to the correct parent model
        foreach ($models as $model) {
             $relatedModelsForThisParent = [];
             $idsForThisParent = $modelIdToRelatedIdsMap[$model->id] ?? [];

             foreach ($idsForThisParent as $relatedId) {
                 if (isset($relatedDictionary[$relatedId])) {
                     $relatedModelsForThisParent[] = $relatedDictionary[$relatedId];
                 }
                 // else: Related ID existed in parent list but wasn't found (deleted?), skip it.
             }
             // Set the property on the parent model
             $relationProperty->setValue($model, $relatedModelsForThisParent);
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

            if ($property->isStatic() || in_array($propertyName, ['odooInstance', 'modelNameCache', 'relationAttributesCache', 'fieldNamesCache', 'originalAttributes', 'loadedRelations', '_hydratedData'])) {
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