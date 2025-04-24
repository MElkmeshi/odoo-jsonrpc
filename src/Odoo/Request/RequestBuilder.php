<?php


namespace Obuchmann\OdooJsonRpc\Odoo\Request;

use Obuchmann\OdooJsonRpc\Exceptions\ConfigurationException;
use Obuchmann\OdooJsonRpc\Odoo\Endpoint\ObjectEndpoint;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\Domain;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\HasDomain;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\HasFields;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\HasGroupBy;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\HasLimit;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\HasOffset;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\HasOptions;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\HasOrder;
use Obuchmann\OdooJsonRpc\Odoo\Request\Arguments\Options;

class RequestBuilder
{
    use HasDomain, HasOrder, HasOffset, HasLimit, HasFields, HasOptions, HasGroupBy;

    public function __construct(
        private ObjectEndpoint $endpoint,
        protected string $model,
        Domain $domain,
        ?Options $options = null
    )
    {
        $this->domain = $domain;
        $this->options = $options ?? new Options();
    }

    public function can(string $permission): bool
    {
        return $this->endpoint->checkAccessRights($this->model, $permission, $this->options);
    }

    public function get(): array
    {
        if($this->hasGroupBy()){
            return $this->endpoint->readGroup(
                model: $this->model,
                groupBy: $this->groupBy ?? [],
                domain: $this->domain,
                fields: $this->fields,
                offset: $this->offset,
                limit: $this->limit,
                order: $this->getOrderString(),
                options: $this->options
            );
        }
        return $this->endpoint->searchRead(
            model: $this->model,
            domain: $this->domain,
            fields: $this->fields,
            offset: $this->offset,
            limit: $this->limit,
            order: $this->getOrderString(),
            options: $this->options
        );
    }

     public function hasGroupBy(): bool
     {
        return isset($this->groupBy) && is_array($this->groupBy) && !empty($this->groupBy);
    }


    public function collect(): \Illuminate\SupportCollection
    {
        if(!function_exists('collect')){
            throw new ConfigurationException("Laravel 'collect' helper function is not defined. Ensure Laravel framework is available.");
        }
        return collect($this->get());
    }

    public function first(): ?object
    {
        $originalLimit = $this->limit;
        $this->limit(1);
        $result = $this->get()[0] ?? null;
        $this->limit = $originalLimit;

        return is_object($result) ? $result : null;
    }

    public function ids(): array
    {
        return $this->endpoint->search(
            model: $this->model,
            domain: $this->domain,
            offset: $this->offset,
            limit: $this->limit,
            order: $this->getOrderString(),
            options: $this->options
        );
    }

    public function count(): int
    {
        return $this->endpoint->count(
            model: $this->model,
            domain: $this->domain,
            offset: $this->offset,
            limit: $this->limit,
            order: $this->getOrderString(),
            options: $this->options
        );
    }

    public function delete(): bool
    {
        $ids = $this->ids();
        if (empty($ids)) {
            return true;
        }
        return $this->endpoint->unlink($this->model, $ids, $this->options);
    }

    public function create(array $values): bool|int
    {
        return $this->endpoint->create($this->model, $values, $this->options);
    }

    public function write(array $values): bool
    {
        unset($values['id']);
        if (empty($values)) {
            return true;
        }

        $ids = $this->ids();
        if (empty($ids)) {
            return true;
        }

        return $this->endpoint->write($this->model, $ids, $values, $this->options);
    }

    public function update(array $values): bool
    {
        return $this->write($values);
    }

}