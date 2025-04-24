<?php


namespace Obuchmann\OdooJsonRpc\Odoo\Mapping;


use Obuchmann\OdooJsonRpc\Attributes\BelongsTo;
use Obuchmann\OdooJsonRpc\Attributes\Field;
use Obuchmann\OdooJsonRpc\Attributes\HasMany;
use Obuchmann\OdooJsonRpc\Attributes\Key;
use Obuchmann\OdooJsonRpc\Attributes\KeyName;
use Obuchmann\OdooJsonRpc\Odoo\Casts\CastHandler;
use Obuchmann\OdooJsonRpc\Odoo\OdooModel;
use ReflectionProperty;
use stdClass;

trait HasFields
{
    // fieldNames is now defined in OdooModel to incorporate relation fields

    public static function hydrate(object $response): static
    {
        $castsExists = CastHandler::hasCasts();

        $reflectionClass = new \ReflectionClass(static::class);
        $properties = $reflectionClass->getProperties();

        $instance = static::newInstance();
        // Store raw response data for potential later use (like getting related IDs)
        $instance->_hydratedData = (array) $response;
        $instance->id = $response->id ?? null;

        foreach ($properties as $property) {
            $isKey = !empty($property->getAttributes(Key::class));
            $isKeyName = !empty($property->getAttributes(KeyName::class));
            $attributes = $property->getAttributes(Field::class);

            foreach ($attributes as $attribute) {
                $fieldInstance = $attribute->newInstance();
                $field = $fieldInstance->name ?? $property->name;
                if (isset($response->{$field})) {
                    $rawValue = $response->{$field};
                    $value = null;

                    if ($isKey) {
                        $value = is_array($rawValue) ? ($rawValue[0] ?? null) : null;
                    } elseif ($isKeyName) {
                        $value = is_array($rawValue) ? ($rawValue[1] ?? null) : null;
                    } else {
                        $value = $rawValue;
                    }

                    $propertyType = $property->getType();
                    if ($value !== null || ($propertyType && $propertyType->allowsNull())) {
                         // Check if the property itself is the target for the HasMany ID list
                         $hasManyAttrs = $property->getAttributes(HasMany::class);
                         $isHasManyIdListField = false;
                         if(!empty($hasManyAttrs)){
                             $hmInstance = $hasManyAttrs[0]->newInstance();
                             if($field === $hmInstance->odooRelationshipField) {
                                 $isHasManyIdListField = true;
                             }
                         }

                         // Only hydrate if it's not the special HasMany ID list field OR if it is,
                         // ensure the value is actually an array (as expected for ID lists)
                         if(!$isHasManyIdListField || ($isHasManyIdListField && is_array($value))) {
                            $instance->{$property->name} = $castsExists ? CastHandler::cast($property, $value) : $value;
                         }

                    } elseif (!isset($instance->{$property->name}) && $property->hasDefaultValue()) {
                         // Avoid overwriting defaults
                    } elseif (!$property->isInitialized($instance)) {
                        if ($propertyType && $propertyType->allowsNull()) {
                           $instance->{$property->name} = null;
                        }
                    }
                } elseif (!isset($instance->{$property->name}) && !$property->isInitialized($instance)) {
                     $propertyType = $property->getType();
                     if ($propertyType && $propertyType->allowsNull()) {
                        $instance->{$property->name} = null;
                    }
                }
            }
        }

        return $instance;
    }

    public static function dehydrate(OdooModel $model): object
    {
        $castsExists = CastHandler::hasCasts();
        $item = new stdClass();

        $reflectionClass = new \ReflectionClass(static::class);
        $properties = $reflectionClass->getProperties();

        foreach ($properties as $property) {
            // Skip internal hydration data property
            if ($property->getName() === '_hydratedData') {
                continue;
            }

            $fieldAttributes = $property->getAttributes(Field::class);
            foreach ($fieldAttributes as $attribute) {
                $fieldInstance = $attribute->newInstance();
                $field = $fieldInstance->name ?? $property->name;

                $isHasManyObjectProperty = !empty($property->getAttributes(HasMany::class));
                $isBelongsToObjectProperty = !empty($property->getAttributes(BelongsTo::class));

                // Determine if this #[Field] corresponds to the odooRelationshipField of a HasMany
                $isOdooRelationshipIdListField = false;
                if($isHasManyObjectProperty) {
                    $hmAttr = $property->getAttributes(HasMany::class)[0]->newInstance();
                    if ($field === $hmAttr->odooRelationshipField) {
                        $isOdooRelationshipIdListField = true;
                    }
                }


                if ($isBelongsToObjectProperty) {
                    continue; // Skip dehydrating the object property itself
                }

                // If it's the HasMany object property AND not specifically the ID list field, skip it.
                 if ($isHasManyObjectProperty && !$isOdooRelationshipIdListField) {
                     continue;
                 }


                if ($property->isInitialized($model) && property_exists($model, $property->getName())) {
                     $rawValue = $model->{$property->name};
                    $item->{$field} = $castsExists ? CastHandler::uncast($property, $rawValue) : $rawValue;
                }
            }

            // Handle HasMany relationships specifically for generating Odoo commands
            // This targets the property holding the ARRAY OF MODELS (e.g., public array $variants)
            $hasManyAttributes = $property->getAttributes(HasMany::class);
            if (!empty($hasManyAttributes)) {
                 $attribute = $hasManyAttributes[0]->newInstance();
                 $odooFieldName = $attribute->odooRelationshipField; // USE the defined field for commands

                 // If no field defined for commands, we cannot dehydrate this relation meaningfully
                 if ($odooFieldName === null) {
                      continue;
                 }

                 if ($property->isInitialized($model) && property_exists($model, $property->getName())) {
                    $values = $model->{$property->name}; // Should be an array of OdooModel instances or IDs

                    if ($values === null) {
                         continue;
                    }

                    if (!is_array($values)) {
                        continue;
                    }

                    if (self::isIdArray($values)) {
                        $item->{$odooFieldName} = [[6, 0, $values]];
                    } else {
                        $commands = [];
                        foreach ($values as $value) {
                            if ($value instanceof OdooModel) {
                                $dehydratedValue = $value::dehydrate($value);
                                if ($value->exists()) {
                                     if (!empty((array)$dehydratedValue)) {
                                         $commands[] = [1, $value->id, $dehydratedValue];
                                     }
                                } else {
                                    $commands[] = [0, 0, $dehydratedValue];
                                }
                            }
                        }

                        if (!empty($commands)) {
                             $item->{$odooFieldName} = $commands;
                        } else {
                             if (is_array($values) && empty($values)) {
                                $item->{$odooFieldName} = [[6, 0, []]];
                             }
                        }
                    }
                 }
            }
        }

        return $item;
    }

    protected static function newInstance()
    {
        $instance = new static();
        // Add a placeholder for raw data if needed by relation loading
        $instance->_hydratedData = [];
        return $instance;
    }

    private static function isIdArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        foreach ($arr as $item) {
            if (!is_int($item)) {
                return false;
            }
        }
        return true;
    }
}