<?php
namespace Obuchmann\OdooJsonRpc\Odoo\Mapping;

use Obuchmann\OdooJsonRpc\Attributes\HasMany;
use Obuchmann\OdooJsonRpc\Attributes\BelongsTo;
use Obuchmann\OdooJsonRpc\Odoo\OdooModel;
use ReflectionClass;

class EagerLoader
{
    public static function loadRelations(array $models, array $relations): void
    {
        foreach ($relations as $path) {
            $parts = explode('.', $path);
            static::loadRelation($models, $parts);
        }
    }

    private static function loadRelation(array $models, array $parts): void
    {
        if (empty($models) || empty($parts)) {
            return;
        }

        $relationName = array_shift($parts);
        $first = $models[0];
        $rc = new ReflectionClass($first);
        $prop = $rc->getProperty($relationName);

        if ($attr = $prop->getAttributes(HasMany::class)[0] ?? null) {
            $inst = $attr->newInstance();
            $related = $inst->class;
            $fk = $inst->name;

            $ids = array_map(fn($m) => $m->id, $models);
            $children = $related::query()
                ->where($fk, 'in', $ids)
                ->get();

            $map = [];
            foreach ($children as $c) {
                $pid = $c->{$fk};
                $map[$pid][] = $c;
            }
            foreach ($models as $m) {
                $m->{$relationName} = $map[$m->id] ?? [];
            }

            if ($parts) {
                $allChildren = array_merge(...array_values($map));
                static::loadRelation($allChildren, $parts);
            }

        } elseif ($attr = $prop->getAttributes(BelongsTo::class)[0] ?? null) {
            $inst = $attr->newInstance();
            $related = $inst->class;
            $fk = $inst->name;

            $fks = array_filter(array_map(fn($m) => $m->{$fk}, $models));
            $parents = $related::query()
                ->where('id', 'in', $fks)
                ->get();
            $map = [];
            foreach ($parents as $p) {
                $map[$p->id] = $p;
            }
            foreach ($models as $m) {
                $mid = $m->{$fk};
                $m->{$relationName} = $map[$mid] ?? null;
            }

            if ($parts) {
                static::loadRelation(array_values($map), $parts);
            }
        }
    }
}
