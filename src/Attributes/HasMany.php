<?php

namespace Obuchmann\OdooJsonRpc\Attributes;

use Attribute;
use Obuchmann\OdooJsonRpc\Odoo\OdooModel;

#[Attribute(Attribute::TARGET_PROPERTY)]
class HasMany implements OdooAttribute
{
    /**
     * @param class-string<OdooModel> $related
     * @param string $foreignKey
     * @param string|null $odooRelationshipField
     */
    public function __construct(
        public string $related,
        public string $foreignKey,
        public ?string $odooRelationshipField = null 
    )
    {
    }
}