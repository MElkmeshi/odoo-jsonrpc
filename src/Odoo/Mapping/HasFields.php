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
    protected static function fieldNames(): array
    {
        $fieldNames = [];

        $reflectionClass = new \ReflectionClass(static::class);
        $properties = $reflectionClass->getProperties();

        foreach ($properties as $property) {
            $attributes = $property->getAttributes(Field::class);

            foreach ($attributes as $attribute) {
                $fieldInstance = $attribute->newInstance();
                $fieldNames[] = $fieldInstance->name ?? $property->name;
            }

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

            $hasManyAttrs = $property->getAttributes(HasMany::class);
            if (!empty($hasManyAttrs)) {
                $hasManyInstance = $hasManyAttrs[0]->newInstance();
                 if ($hasManyInstance->odooRelationshipField !== null) {
                    $fieldAttrs = $property->getAttributes(Field::class);
                    foreach ($fieldAttrs as $fieldAttribute) {
                         $fieldInstance = $fieldAttribute->newInstance();
                         if (($fieldInstance->name ?? $property->getName()) === $hasManyInstance->odooRelationshipField) {
                             $fieldNames[] = $hasManyInstance->odooRelationshipField;
                             break;
                         }
                    }
                 }
            }
        }
        return array_unique($fieldNames);
    }

    public static function hydrate(object $response): static
    {
        $castsExists = CastHandler::hasCasts();

        $reflectionClass = new \ReflectionClass(static::class);
        $properties = $reflectionClass->getProperties();

        $instance = static::newInstance();
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
                         $instance->{$property->name} = $castsExists ? CastHandler::cast($property, $value) : $value;
                    } elseif (!isset($instance->{$property->name}) && $property->hasDefaultValue()) {

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
            $fieldAttributes = $property->getAttributes(Field::class);
            foreach ($fieldAttributes as $attribute) {
                $fieldInstance = $attribute->newInstance();
                $field = $fieldInstance->name ?? $property->name;

                if (!empty($property->getAttributes(HasMany::class))) {
                     $hasManyAttrs = $property->getAttributes(HasMany::class);
                     $hasManyInstance = $hasManyAttrs[0]->newInstance();
                     if ($field !== $hasManyInstance->odooRelationshipField) {
                        continue;
                     }
                }
                 if (!empty($property->getAttributes(BelongsTo::class))) {
                    continue;
                 }

                if ($property->isInitialized($model) && property_exists($model, $property->getName())) {
                     $rawValue = $model->{$property->name};
                    $item->{$field} = $castsExists ? CastHandler::uncast($property, $rawValue) : $rawValue;
                }
            }

            $hasManyAttributes = $property->getAttributes(HasMany::class);
            if (!empty($hasManyAttributes)) {
                 $attribute = $hasManyAttributes[0]->newInstance();
                 $odooFieldName = $attribute->odooRelationshipField ?? $property->name;

                 if ($property->isInitialized($model) && property_exists($model, $property->getName())) {
                    $values = $model->{$property->name};

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
                                     } else {
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
        return new static();
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