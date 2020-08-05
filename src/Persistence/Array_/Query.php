<?php

declare(strict_types=1);

namespace atk4\data\Persistence\Array_;

use atk4\data\Exception;
use atk4\data\Field;
use atk4\data\Model;
use atk4\data\Persistence\AbstractQuery;

/**
 * Class Array_ is returned by $model->toQuery(). Compatible with DSQL to a certain point as it implements
 * specific actions such as getOne() or get().
 */
class Query extends AbstractQuery
{
    private $iterator;

    private $data;

    private $fields;

    public function __construct(Model $model)
    {
        parent::__construct($model);

        $this->data = $model->persistence->getRawDataByTable($model->table);

        $this->iterator = new \ArrayIterator($this->data);
    }

    protected function initSelect($fields = null): void
    {
        if ($fields) {
            $this->fields = $fields;

            $keys = array_flip((array) $fields);

            $data = array_map(function ($row) use ($keys) {
                return array_intersect_key($row, $keys);
            }, $this->get());

            $this->iterator = new \ArrayIterator($data);
        }
    }

    protected function initInsert(array $data): void
    {
    }

    protected function initUpdate(array $data): void
    {
    }

    protected function initDelete($id = null): void
    {
    }

    /**
     * Applies sorting on Iterator.
     *
     * @param array $fields
     *
     * @return $this
     */
    protected function initOrder(): void
    {
        if ($this->order) {
            $data = $this->get();

            // prepare arguments for array_multisort()
            $args = [];
            foreach ($this->order as [$field, $desc]) {
                $args[] = array_column($data, $field);
                $args[] = $desc ? SORT_DESC : SORT_ASC;
            }
            $args[] = &$data;

            // call sorting
            array_multisort(...$args);

            // put data back in generator
            $this->iterator = new \ArrayIterator(array_pop($args));
        }
    }

    protected function initLimit(): void
    {
        if ($args = $this->getLimitArgs()) {
            [$limit, $offset] = $args;

            $this->iterator = new \LimitIterator($this->iterator, $offset, $limit);
        }
    }

    /**
     * Counts number of rows and replaces our generator with just a single number.
     *
     * @return $this
     */
    protected function initCount($alias = null): void
    {
        // @todo: kept for BC, inconstent results with SQL count!
        $this->initLimit();

        $alias = $alias ?? 'count';

        $this->iterator = new \ArrayIterator([[$alias => iterator_count($this->iterator)]]);
    }

    /**
     * Checks if iterator has any rows.
     *
     * @return $this
     */
    protected function initExists(): void
    {
        $this->iterator = new \ArrayIterator([[$this->iterator->valid() ? 1 : 0]]);
    }

    protected function initField($fieldName, string $alias = null): void
    {
        if (!$fieldName) {
            throw new Exception('Field query requires field name');
        }

        $field = $fieldName;
        if (!is_string($fieldName)) {
            $field = $this->model->getField($fieldName);
            $fieldName = $field->short_name;
        }

        $this->initSelect([$fieldName]);

        // get first record
        if ($row = $this->getRow()) {
            if ($alias && array_key_exists($field, $row)) {
                $row[$alias] = $row[$field];
                unset($row[$field]);
            }
        }

        $this->iterator = new \ArrayIterator([[$row]]);
    }

    protected function doExecute()
    {
        return $this->iterator;
    }

    /**
     * Return all data inside array.
     */
    protected function doGet(): array
    {
        return iterator_to_array($this->iterator, true);
    }

    /**
     * Return one row of data.
     */
    protected function doGetRow(): ?array
    {
        $this->iterator->rewind();

        return $this->iterator->current();
    }

    /**
     * Return one value from one row of data.
     *
     * @return mixed
     */
    protected function doGetOne()
    {
        $data = $this->getRow();

        return reset($data);
    }

    /**
     * Calculates SUM|AVG|MIN|MAX aggragate values for $field.
     *
     * @param string $fieldName
     * @param string $alias
     */
    protected function initAggregate(string $functionName, $field, string $alias = null, bool $coalesce = false): void
    {
        $field = is_string($field) ? $field : $field->short_name;

        $result = 0;
        $column = array_column($this->get(), $field);

        switch (strtoupper($functionName)) {
            case 'SUM':
                $result = array_sum($column);

            break;
            case 'AVG':
                $column = $coalesce ? $column : array_filter($column, function ($value) {
                    return $value !== null;
                });

                $result = array_sum($column) / count($column);

            break;
            case 'MAX':
                $result = max($column);

            break;
            case 'MIN':
                $result = min($column);

            break;
            default:
                throw (new Exception('Persistence\Array_ query unsupported aggregate function'))
                    ->addMoreInfo('function', $functionName);
        }

        $this->iterator = new \ArrayIterator([[$result]]);
    }

    /**
     * Applies FilterIterator.
     */
    protected function initWhere(): void
    {
        if (!$this->scope->isEmpty()) {
            $this->iterator = new \CallbackFilterIterator($this->iterator, function ($row, $id) {
                // make sure we use the complete row with the filter
                $row = $this->data[$id];

                return $this->match($row, $this->scope);
            });
        }
    }

    /**
     * Checks if $row matches $condition.
     */
    protected function match(array $row, Model\Scope\AbstractScope $condition): bool
    {
        $match = false;

        // simple condition
        if ($condition instanceof Model\Scope\Condition) {
            $args = $condition->toQueryArguments();

            $field = $args[0];
            $operator = $args[1] ?? null;
            $value = $args[2] ?? null;
            if (count($args) == 2) {
                $value = $operator;

                $operator = '=';
            }

            if (!is_a($field, Field::class)) {
                throw (new Exception('Persistence\Array_ driver condition unsupported format'))
                    ->addMoreInfo('reason', 'Unsupported object instance ' . get_class($field))
                    ->addMoreInfo('condition', $condition);
            }

            $match = $this->evaluateIf($row[$field->short_name] ?? null, $operator, $value);
        }

        // nested conditions
        if ($condition instanceof Model\Scope) {
            $matches = [];

            foreach ($condition->getNestedConditions() as $nestedCondition) {
                $matches[] = $subMatch = (bool) $this->match($row, $nestedCondition);

                // do not check all conditions if any match required
                if ($condition->isOr() && $subMatch) {
                    break;
                }
            }

            // any matches && all matches the same (if all required)
            $match = array_filter($matches) && ($condition->isAnd() ? count(array_unique($matches)) === 1 : true);
        }

        return $match;
    }

    protected function evaluateIf($v1, $operator, $v2): bool
    {
        switch (strtoupper((string) $operator)) {
            case '=':
                $result = is_array($v2) ? $this->evaluateIf($v1, 'IN', $v2) : $v1 === $v2;

            break;
            case '>':
                $result = $v1 > $v2;

            break;
            case '>=':
                $result = $v1 >= $v2;

            break;
            case '<':
                $result = $v1 < $v2;

            break;
            case '<=':
                $result = $v1 <= $v2;

            break;
            case '!=':
            case '<>':
                $result = !$this->evaluateIf($v1, '=', $v2);

            break;
            case 'LIKE':
                $pattern = str_ireplace('%', '(.*?)', preg_quote($v2));

                $result = (bool) preg_match('/^' . $pattern . '$/', (string) $v1);

            break;
            case 'NOT LIKE':
                $result = !$this->evaluateIf($v1, 'LIKE', $v2);

            break;
            case 'IN':
                $result = is_array($v2) ? in_array($v1, $v2, true) : $this->evaluateIf($v1, '=', $v2);

            break;
            case 'NOT IN':
                $result = !$this->evaluateIf($v1, 'IN', $v2);

            break;
            case 'REGEXP':
                $result = (bool) preg_match('/' . $v2 . '/', $v1);

            break;
            case 'NOT REGEXP':
                $result = !$this->evaluateIf($v1, 'REGEXP', $v2);

            break;
            default:
                throw (new Exception('Unsupported operator'))
                    ->addMoreInfo('operator', $operator);
        }

        return $result;
    }

    public function getDebug(): array
    {
        return array_merge([
            'fields' => $this->fields,
        ], parent::getDebug());
    }
}