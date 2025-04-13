<?php

namespace Obuchmann\OdooJsonRpc\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsTo implements OdooAttribute
{
    /**
     * @param class-string<OdooModel> $related
     * @param string $foreignKey
     */
    public function __construct(
        public string $related,
        public string $foreignKey
    )
    {
    }
}