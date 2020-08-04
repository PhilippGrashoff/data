<?php

declare(strict_types=1);

namespace atk4\data\Persistence;

use atk4\data\Exception;
use atk4\data\Field;
use atk4\data\FieldSqlExpression;
use atk4\data\Model;
use atk4\data\Persistence;
use atk4\dsql\Connection;
use atk4\dsql\Expression;
use atk4\dsql\Query;

/**
 * Persistence\Sql class.
 */
class Sql extends Persistence
{
    /**
     * Connection object.
     *
     * @var \atk4\dsql\Connection
     */
    public $connection;

    /**
     * Default class when adding new field.
     *
     * @var string
     */
    public $_default_seed_addField = [\atk4\data\FieldSql::class];

    /**
     * Default class when adding hasOne field.
     *
     * @var string
     */
    public $_default_seed_hasOne = [\atk4\data\Reference\HasOneSql::class];

    /**
     * Default class when adding hasMany field.
     *
     * @var string
     */
    public $_default_seed_hasMany; // [\atk4\data\Reference\HasMany::class];

    /**
     * Default class when adding Expression field.
     *
     * @var string
     */
    public $_default_seed_addExpression = [FieldSqlExpression::class];

    /**
     * Default class when adding join.
     *
     * @var string
     */
    public $_default_seed_join = [Sql\Join::class];

    /**
     * Constructor.
     *
     * @param Connection|string $connection
     * @param string            $user
     * @param string            $password
     * @param array             $args
     */
    public function __construct($connection, $user = null, $password = null, $args = [])
    {
        if ($connection instanceof \atk4\dsql\Connection) {
            $this->connection = $connection;

            return;
        }

        if (is_object($connection)) {
            throw (new Exception('You can only use Persistance_SQL with Connection class from atk4\dsql'))
                ->addMoreInfo('connection', $connection);
        }

        // attempt to connect.
        $this->connection = \atk4\dsql\Connection::connect(
            $connection,
            $user,
            $password,
            $args
        );
    }

    /**
     * Disconnect from database explicitly.
     */
    public function disconnect()
    {
        parent::disconnect();

        $this->connection = null;
    }

    /**
     * Returns Query instance.
     */
    public function dsql(): Query
    {
        return $this->connection->dsql();
    }

    /**
     * Atomic executes operations within one begin/end transaction, so if
     * the code inside callback will fail, then all of the transaction
     * will be also rolled back.
     *
     * @param callable $fx
     *
     * @return mixed
     */
    public function atomic($fx)
    {
        return $this->connection->atomic($fx);
    }

    /**
     * {@inheritdoc}
     */
    public function add(Model $model, array $defaults = []): Model
    {
        // Use our own classes for fields, references and expressions unless
        // $defaults specify them otherwise.
        $defaults = array_merge([
            '_default_seed_addField' => $this->_default_seed_addField,
            '_default_seed_hasOne' => $this->_default_seed_hasOne,
            '_default_seed_hasMany' => $this->_default_seed_hasMany,
            '_default_seed_addExpression' => $this->_default_seed_addExpression,
            '_default_seed_join' => $this->_default_seed_join,
        ], $defaults);

        $model = parent::add($model, $defaults);

        if (!isset($model->table) || (!is_string($model->table) && $model->table !== false)) {
            throw (new Exception('Property $table must be specified for a model'))
                ->addMoreInfo('model', $model);
        }

        // When we work without table, we can't have any IDs
        if ($model->table === false) {
            $model->removeField($model->id_field);
            $model->addExpression($model->id_field, '1');
            //} else {
            // SQL databases use ID of int by default
            //$m->getField($m->id_field)->type = 'integer';
        }

        // Sequence support
        if ($model->sequence && $model->hasField($model->id_field)) {
            $model->getField($model->id_field)->default = $this->dsql()->mode('seq_nextval')->sequence($model->sequence);
        }

        return $model;
    }

    /**
     * Initialize persistence.
     */
    protected function initPersistence(Model $model)
    {
        parent::initPersistence($model);

        $model->addMethod('expr', \Closure::fromCallable([$this, 'expr']));
        $model->addMethod('dsql', \Closure::fromCallable([$this, 'dsql']));
        $model->addMethod('exprNow', \Closure::fromCallable([$this, 'exprNow']));
    }

    /**
     * Creates new Expression object from expression string.
     *
     * @param mixed $expr
     * @param array $args
     */
    public function expr(Model $model, $expr, $args = []): Expression
    {
        if (!is_string($expr)) {
            return $this->connection->expr($expr, $args);
        }
        preg_replace_callback(
            '/\[[a-z0-9_]*\]|{[a-z0-9_]*}/i',
            function ($matches) use (&$args, $model) {
                $identifier = substr($matches[0], 1, -1);
                if ($identifier && !isset($args[$identifier])) {
                    $args[$identifier] = $model->getField($identifier);
                }

                return $matches[0];
            },
            $expr
        );

        return $this->connection->expr($expr, $args);
    }

    /**
     * Creates new Query object with current_timestamp(precision) expression.
     */
    public function exprNow(int $precision = null): Expression
    {
        return $this->connection->dsql()->exprNow($precision);
    }

    /**
     * This is the actual field typecasting, which you can override in your
     * persistence to implement necessary typecasting.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function _typecastSaveField(Field $field, $value)
    {
        // work only on copied value not real one !!!
        $v = is_object($value) ? clone $value : $value;

        switch ($field->type) {
            case 'boolean':
                // if enum is not set, then simply cast value to integer
                if (!isset($field->enum) || !$field->enum) {
                    $v = (int) $v;

                    break;
                }

                // if enum is set, first lets see if it matches one of those precisely
                if ($v === $field->enum[1]) {
                    $v = true;
                } elseif ($v === $field->enum[0]) {
                    $v = false;
                }

                // finally, convert into appropriate value
                $v = $v ? $field->enum[1] : $field->enum[0];

                break;
            case 'date':
            case 'datetime':
            case 'time':
                $dt_class = $field->dateTimeClass ?? \DateTime::class;
                $tz_class = $field->dateTimeZoneClass ?? \DateTimeZone::class;

                if ($v instanceof $dt_class || $v instanceof \DateTimeInterface) {
                    $format = ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s.u', 'time' => 'H:i:s.u'];
                    $format = $field->persist_format ?: $format[$field->type];

                    // datetime only - set to persisting timezone
                    if ($field->type === 'datetime' && isset($field->persist_timezone)) {
                        $v = new \DateTime($v->format('Y-m-d H:i:s.u'), $v->getTimezone());
                        $v->setTimezone(new $tz_class($field->persist_timezone));
                    }
                    $v = $v->format($format);
                }

                break;
            case 'array':
            case 'object':
                // don't encode if we already use some kind of serialization
                $v = $field->serialize ? $v : $this->jsonEncode($field, $v);

                break;
        }

        return $v;
    }

    /**
     * This is the actual field typecasting, which you can override in your
     * persistence to implement necessary typecasting.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function _typecastLoadField(Field $field, $value)
    {
        // LOB fields return resource stream
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        // work only on copied value not real one !!!
        $v = is_object($value) ? clone $value : $value;

        switch ($field->type) {
            case 'string':
            case 'text':
                // do nothing - it's ok as it is
                break;
            case 'integer':
                $v = (int) $v;

                break;
            case 'float':
                $v = (float) $v;

                break;
            case 'money':
                $v = round((float) $v, 4);

                break;
            case 'boolean':
                if (isset($field->enum) && is_array($field->enum)) {
                    if (isset($field->enum[0]) && $v == $field->enum[0]) {
                        $v = false;
                    } elseif (isset($field->enum[1]) && $v == $field->enum[1]) {
                        $v = true;
                    } else {
                        $v = null;
                    }
                } elseif ($v === '') {
                    $v = null;
                } else {
                    $v = (bool) $v;
                }

                break;
            case 'date':
            case 'datetime':
            case 'time':
                $dt_class = $field->dateTimeClass ?? \DateTime::class;
                $tz_class = $field->dateTimeZoneClass ?? \DateTimeZone::class;

                if (is_numeric($v)) {
                    $v = new $dt_class('@' . $v);
                } elseif (is_string($v)) {
                    // ! symbol in date format is essential here to remove time part of DateTime - don't remove, this is not a bug
                    $format = ['date' => '+!Y-m-d', 'datetime' => '+!Y-m-d H:i:s', 'time' => '+!H:i:s'];
                    if ($field->persist_format) {
                        $format = $field->persist_format;
                    } else {
                        $format = $format[$field->type];
                        if (strpos($v, '.') !== false) { // time possibly with microseconds, otherwise invalid format
                            $format = preg_replace('~(?<=H:i:s)(?![. ]*u)~', '.u', $format);
                        }
                    }

                    // datetime only - set from persisting timezone
                    if ($field->type === 'datetime' && isset($field->persist_timezone)) {
                        $v = $dt_class::createFromFormat($format, $v, new $tz_class($field->persist_timezone));
                        if ($v !== false) {
                            $v->setTimezone(new $tz_class(date_default_timezone_get()));
                        }
                    } else {
                        $v = $dt_class::createFromFormat($format, $v);
                    }

                    if ($v === false) {
                        throw (new Exception('Incorrectly formatted date/time'))
                            ->addMoreInfo('format', $format)
                            ->addMoreInfo('value', $value)
                            ->addMoreInfo('field', $field);
                    }

                    // need to cast here because DateTime::createFromFormat returns DateTime object not $dt_class
                    // this is what Carbon::instance(DateTime $dt) method does for example
                    if ($dt_class !== 'DateTime') {
                        $v = new $dt_class($v->format('Y-m-d H:i:s.u'), $v->getTimezone());
                    }
                }

                break;
            case 'array':
                // don't decode if we already use some kind of serialization
                $v = $field->serialize ? $v : $this->jsonDecode($field, $v, true);

                break;
            case 'object':
                // don't decode if we already use some kind of serialization
                $v = $field->serialize ? $v : $this->jsonDecode($field, $v, false);

                break;
        }

        return $v;
    }

    public function query(Model $model): AbstractQuery
    {
        return new Sql\Query($model);
    }

    /**
     * Tries to load data record, but will not fail if record can't be loaded.
     *
     * @param mixed $id
     */
    public function tryLoad(Model $model, $id): ?array
    {
        if (!$model->id_field) {
            throw (new Exception('Unable to load field by "id" when Model->id_field is not defined.'))
                ->addMoreInfo('id', $id);
        }

        $query = $this->query($model);

        // execute action
        try {
            $dataRaw = $query->find($id);
            if ($dataRaw === null) {
                return null;
            }
            $data = $this->typecastLoadRow($model, $dataRaw);
        } catch (\PDOException $e) {
            throw (new Exception('Unable to load due to query error', 0, $e))
                ->addMoreInfo('query', $query->getDebugQuery())
                ->addMoreInfo('message', $e->getMessage())
                ->addMoreInfo('model', $model)
                ->addMoreInfo('scope', $model->scope()->toWords());
        }

        if (!isset($data[$model->id_field]) || $data[$model->id_field] === null) {
            throw (new Exception('Model uses "id_field" but it wasn\'t available in the database'))
                ->addMoreInfo('model', $model)
                ->addMoreInfo('id_field', $model->id_field)
                ->addMoreInfo('id', $id)
                ->addMoreInfo('data', $data);
        }

        $model->id = $data[$model->id_field];

        return $data;
    }

    /**
     * Loads a record from model and returns a associative array.
     *
     * @param mixed $id
     */
    public function load(Model $model, $id): array
    {
        if (!$data = $this->tryLoad($model, $id)) {
            throw (new Exception('Record was not found', 404))
                ->addMoreInfo('model', $model)
                ->addMoreInfo('id', $id)
                ->addMoreInfo('scope', $model->scope()->toWords());
        }

        return $data;
    }

    /**
     * Tries to load any one record.
     */
    public function tryLoadAny(Model $model): ?array
    {
        $rawData = $this->query($model)->getRow();

        if ($rawData === null) {
            return null;
        }

        $data = $this->typecastLoadRow($model, $rawData);

//         $load = $model->toQuery('select');
//         $load->limit(1);

//         // execute action
//         try {
//             $dataRaw = $load->getRow();
//             if ($dataRaw === null) {
//                 return null;
//             }
//             $data = $this->typecastLoadRow($model, $dataRaw);
//         } catch (\PDOException $e) {
//             throw (new Exception('Unable to load due to query error', 0, $e))
//                 ->addMoreInfo('query', $load->getDebugQuery())
//                 ->addMoreInfo('message', $e->getMessage())
//                 ->addMoreInfo('model', $model)
//                 ->addMoreInfo('scope', $model->scope()->toWords());
//         }

        if ($model->id_field) {
            // If id_field is not set, model will be read-only
            if (isset($data[$model->id_field])) {
                $model->id = $data[$model->id_field];
            } else {
                throw (new Exception('Model uses "id_field" but it was not available in the database'))
                    ->addMoreInfo('model', $model)
                    ->addMoreInfo('id_field', $model->id_field)
                    ->addMoreInfo('data', $data);
            }
        }

        return $data;
    }

    /**
     * Loads any one record.
     */
    public function loadAny(Model $model): array
    {
        if (!$data = $this->tryLoadAny($model)) {
            throw (new Exception('No matching records were found', 404))
                ->addMoreInfo('model', $model)
                ->addMoreInfo('scope', $model->scope()->toWords());
        }

        return $data;
    }

    /**
     * Inserts record in database and returns new record ID.
     *
     * @return mixed
     */
    public function insert(Model $model, array $data)
    {
        // don't set id field at all if it's NULL
        if ($model->id_field && array_key_exists($model->id_field, $data) && $data[$model->id_field] === null) {
            unset($data[$model->id_field]);
        }

        $data = $this->typecastSaveRow($model, $data);

        $this->query($model)->insert($data)->tryExecute();

        return $this->lastInsertId($model);
    }

    /**
     * Updates record in database.
     *
     * @param mixed $id
     * @param array $data
     */
    public function update(Model $model, $id, $data)
    {
        $data = $this->typecastSaveRow($model, $data);

        $query = $this->query($model)->whereId($id)->update($data);

        $model->onHook(AbstractQuery::HOOK_AFTER_UPDATE, function (Model $model, AbstractQuery $query) use ($data) {
            if ($model->id_field && isset($data[$model->id_field]) && $model->dirty[$model->id_field]) {
                // ID was changed
                $model->id = $data[$model->id_field];
            }
        }, [], -1000);

        $result = $query->tryExecute();

        // if any rows were updated in database, and we had expressions, reload
        if ($model->reload_after_save === true && (!$result || $result->rowCount())) {
            $dirty = $model->dirty;
            $model->reload();
            $model->_dirty_after_reload = $model->dirty;
            $model->dirty = $dirty;
        }
    }

    /**
     * Deletes record from database.
     *
     * @param mixed $id
     */
    public function delete(Model $model, $id)
    {
        $this->query($model)->delete($id)->tryExecute();
    }

    public function getFieldSqlExpression(Field $field, Expression $expression)
    {
        if (isset($field->owner->persistence_data['use_table_prefixes'])) {
            $mask = '{{}}.{}';
            $prop = [
                $field->join
                    ? ($field->join->foreign_alias ?: $field->join->short_name)
                    : ($field->owner->table_alias ?: $field->owner->table),
                $field->actual ?: $field->short_name,
            ];
        } else {
            // references set flag use_table_prefixes, so no need to check them here
            $mask = '{}';
            $prop = [
                $field->actual ?: $field->short_name,
            ];
        }

        // If our Model has expr() method (inherited from Persistence\Sql) then use it
        if ($field->owner->hasMethod('expr')) {
            $field->owner->expr($mask, $prop);
        }

        // Otherwise call method from expression
        return $expression->expr($mask, $prop);
    }

    /**
     * Last ID inserted.
     *
     * @return mixed
     */
    public function lastInsertId(Model $model)
    {
        $seq = $model->sequence ?: null;

        // PostgreSQL PDO always requires sequence name in lastInsertId method as parameter
        // So let's use its default one if no specific is set
        if ($this->connection instanceof \atk4\dsql\Postgresql\Connection && $seq === null) {
            $seq = $model->table . '_' . $model->id_field . '_seq';
        }

        return $this->connection->lastInsertId($seq);
    }
}
