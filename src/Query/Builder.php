<?php

namespace Vinelab\NeoEloquent\Query;

use Closure;
use DateTime;
use Carbon\Carbon;
use BadMethodCallException;
use InvalidArgumentException;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\Node;
use Vinelab\NeoEloquent\ConnectionInterface;
use GraphAware\Common\Result\AbstractRecordCursor as Result;
use Vinelab\NeoEloquent\Eloquent\Collection;
use Vinelab\NeoEloquent\Query\Grammars\Grammar;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Vinelab\NeoEloquent\Traits\ResultTrait;

class Builder implements BaseBuilder
{
    use ResultTrait;

    /**
     * The database connection instance.
     *
     * @var Vinelab\NeoEloquent\Connection
     */
    public $connection;

    /**
     * The database active client handler.
     *
     * @var Neoxygen\NeoClient\Client
     */
    public $client;

    /**
     * The database query grammar instance.
     *
     * @var \Vinelab\NeoEloquent\Query\Grammars\Grammar
     */
    public $grammar;

    /**
     * The database query post processor instance.
     *
     * @var \Vinelab\NeoEloquent\Query\Processors\Processor
     */
    public $processor;

    /**
     * The matches constraints for the query.
     *
     * @var array
     */
    public $matches = array();

    /**
     * The WITH parts of the query.
     *
     * @var array
     */
    public $with = array();

    /**
     * The current query value bindings.
     *
     * @var array
     */
    public $bindings = array(
        'matches' => [],
        'select' => [],
        'join' => [],
        'where' => [],
        'having' => [],
        'order' => [],
    );

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    public $operators = array(
        '+', '-', '*', '/', '%', '^',    // Mathematical
        '=', '<>', '<', '>', '<=', '>=', // Comparison
        'is null', 'is not null',
        'and', 'or', 'xor', 'not',       // Boolean
        'in', '[x]', '[x .. y]',         // Collection
        '=~',                             // Regular Expression
    );

    /**
     * An aggregate function and column to be run.
     *
     * @var array
     */
    public $aggregate;

    /**
     * The columns that should be returned.
     *
     * @var array
     */
    public $columns;

    /**
     * Indicates if the query returns distinct results.
     *
     * @var bool
     */
    public $distinct = false;

    /**
     * The table which the query is targeting.
     *
     * @var string
     */
    public $from;

    /**
     * The where constraints for the query.
     *
     * @var array
     */
    public $wheres;

    /**
     * The groupings for the query.
     *
     * @var array
     */
    public $groups;

    /**
     * The having constraints for the query.
     *
     * @var array
     */
    public $havings;

    /**
     * The orderings for the query.
     *
     * @var array
     */
    public $orders;

    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    public $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    public $offset;

    /**
     * The query union statements.
     *
     * @var array
     */
    public $unions;

    /**
     * The maximum number of union records to return.
     *
     * @var int
     */
    public $unionLimit;

    /**
     * The number of union records to skip.
     *
     * @var int
     */
    public $unionOffset;

    /**
     * The orderings for the union query.
     *
     * @var array
     */
    public $unionOrders;

    /**
     * Indicates whether row locking is being used.
     *
     * @var string|bool
     */
    public $lock;

    /**
     * The field backups currently in use.
     *
     * @var array
     */
    public $backups = [];

    /**
     * The binding backups currently in use.
     *
     * @var array
     */
    public $bindingBackups = [];

    /**
     * Create a new query builder instance.
     *
     * @param Vinelab\NeoEloquent\Connection $connection
     */
    public function __construct(ConnectionInterface $connection, Grammar $grammar)
    {
        $this->grammar = $grammar;
        $this->grammar->setQuery($this);

        $this->connection = $connection;

        $this->client = $connection->getClient();
    }

    /**
     * Set the columns to be selected.
     *
     * @param array $columns
     *
     * @return $this
     */
    public function select($columns = ['*'])
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * Add a new "raw" select expression to the query.
     *
     * @param string $expression
     * @param array  $bindings
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function selectRaw($expression, array $bindings = [])
    {
        $this->addSelect(new Expression($expression));

        if ($bindings) {
            $this->addBinding($bindings, 'select');
        }

        return $this;
    }

    /**
     * Add a subselect expression to the query.
     *
     * @param \Closure|\Vinelab\NeoEloquent\Query\Builder|string $query
     * @param string                                             $as
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function selectSub($query, $as)
    {
        if ($query instanceof Closure) {
            $callback = $query;

            $callback($query = $this->newQuery());
        }

        if ($query instanceof self) {
            $bindings = $query->getBindings();

            $query = $query->toCypher();
        } elseif (is_string($query)) {
            $bindings = [];
        } else {
            throw new InvalidArgumentException();
        }

        return $this->selectRaw('('.$query.') as '.$this->grammar->wrap($as), $bindings);
    }

    /**
     * Add a new select column to the query.
     *
     * @param mixed $column
     *
     * @return $this
     */
    public function addSelect($column)
    {
        $column = is_array($column) ? $column : func_get_args();

        $this->columns = array_merge((array) $this->columns, $column);

        return $this;
    }

    /**
     * Force the query to only return distinct results.
     *
     * @return $this
     */
    public function distinct()
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * Set the node's label which the query is targeting.
     *
     * @param string $label
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function from($label, $as = null)
    {
        $this->from = $label;

        return $this;
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param array  $values
     * @param string $sequence
     *
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $cypher = $this->grammar->compileCreate($this, $values);

        $bindings = $this->getBindingsMergedWithValues($values);

        /** @var CypherList $results */
        $results = $this->connection->insert($cypher, $bindings);

        /** @var Node $node */
        $node = $results->first()->first()->getValue();
        return $node->getId();
    }

    /**
     * Update a record in the database.
     *
     * @param array $values
     *
     * @return int
     */
    public function update(array $values)
    {
        $cypher = $this->grammar->compileUpdate($this, $values);

        $bindings = $this->getBindingsMergedWithValues($values, true);

        $updated = $this->connection->update($cypher, $bindings);

        return ($updated) ? count(current($this->getRecordsByPlaceholders($updated))) : 0;
    }

    /**
     *  Bindings should have the keys postfixed with _update as used
     *  in the CypherGrammar so that we differentiate them from
     *  query bindings avoiding clashing values.
     *
     * @param array $values
     *
     * @return array
     */
    protected function getBindingsMergedWithValues(array $values, $updating = false)
    {
        $bindings = [];

        $values = $this->getGrammar()->postfixValues($values, $updating);

        foreach ($values as $key => $value) {
            $bindings[$key] = $value;
        }

        return array_merge($this->getBindings(), $bindings);
    }

    /**
     * Get the current query value bindings in a flattened array
     * of $key => $value.
     *
     * @return array
     */
    public function getBindings()
    {
        $bindings = [];

        // We will run through all the bindings and pluck out
        // the component (select, where, etc.)
        foreach ($this->bindings as $component => $binding) {
            if (!empty($binding)) {
                // For every binding there could be multiple
                // values set so we need to add all of them as
                // flat $key => $value item in our $bindings.
                foreach ($binding as $key => $value) {
                    $bindings[$key] = $value;
                }
            }
        }

        return $bindings;
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     *
     * @throws \InvalidArgumentException
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // First we check whether the operator is 'IN' so that we call whereIn() on it
        // as a helping hand and centralization strategy, whereIn knows what to do with the IN operator.
        if (mb_strtolower($operator) == 'in') {
            return $this->whereIn($column, $value, $boolean);
        }

        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            return $this->whereNested(function (self $query) use ($column) {
                foreach ($column as $key => $value) {
                    $query->where($key, '=', $value);
                }
            }, $boolean);
        }

        if (func_num_args() == 2) {
            list($value, $operator) = array($operator, '=');
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new \InvalidArgumentException('Value must be provided.');
        }

        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if (!in_array(mb_strtolower($operator), $this->operators, true)) {
            list($value, $operator) = array($operator, '=');
        }

        // If the value is a Closure, it means the developer is performing an entire
        // sub-select within the query and we will need to compile the sub-select
        // within the where clause to get the appropriate query record results.
        if ($value instanceof Closure) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        // If the value is "null", we will just assume the developer wants to add a
        // where null clause to the query. So, we will allow a short-cut here to
        // that method for convenience so the developer doesn't have to check.
        if (is_null($value)) {
            return $this->whereNull($column, $boolean, $operator != '=');
        }

        // Now that we are working with just a simple query we can put the elements
        // in our array and add the query binding to our array of bindings that
        // will be bound to each SQL statements when it is finally executed.
        $type = 'Basic';

        $property = $column;

        // When the column is an id we need to treat it as a graph db id and transform it
        // into the form of id(n) and the typecast the value into int.
        if ($column == 'id') {
            $column = 'id('.$this->modelAsNode().')';
            $value = intval($value);
        }
        // When it's been already passed in the form of NodeLabel.id we'll have to
        // re-format it into id(NodeLabel)
        elseif (preg_match('/^.*\.id$/', $column)) {
            $parts = explode('.', $column);
            $column = sprintf('%s(%s)', $parts[1], $parts[0]);
            $value = intval($value);
        }
        // Also if the $column is already a form of id(n) we'd have to type-cast the value into int.
        elseif (preg_match('/^id\(.*\)$/', $column)) {
            $value = intval($value);
        }

        $binding = $this->prepareBindingColumn($column);

        $this->wheres[] = compact('type', 'binding', 'column', 'operator', 'value', 'boolean');

        $property = $this->wrap($binding);

        if (!$value instanceof Expression) {
            $this->addBinding([$property => $value], 'where');
        }

        return $this;
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * @param string $operator
     * @param mixed  $value
     *
     * @return bool
     */
    protected function invalidOperatorAndValue($operator, $value)
    {
        $isOperator = in_array($operator, $this->operators);

        return $isOperator && $operator != '=' && is_null($value);
    }

    /**
     * Add a raw where clause to the query.
     *
     * @param string $sql
     * @param array  $bindings
     * @param string $boolean
     *
     * @return $this
     */
    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
        $type = 'raw';

        $this->wheres[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'where');

        return $this;
    }

    /**
     * Add a raw or where clause to the query.
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereRaw($sql, $bindings = [])
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a where not between statement to the query.
     *
     * @param string $column
     * @param array  $values
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereNotBetween($column, array $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add an or where not between statement to the query.
     *
     * @param string $column
     * @param array  $values
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereNotBetween($column, array $values)
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param \Closure $callback
     * @param string   $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereNested(Closure $callback, $boolean = 'and')
    {
        // To handle nested queries we'll actually create a brand new query instance
        // and pass it off to the Closure that we have. The Closure can simply do
        // do whatever it wants to a query then we will store it for compiling.
        $query = $this->newQuery();

        $query->from($this->from);

        call_user_func($callback, $query);

        return $this->addNestedWhereQuery($query, $boolean);
    }

    /**
     * Add another query builder as a nested where to the query builder.
     *
     * @param \Vinelab\NeoEloquent\Query\Builder|static $query
     * @param string                                    $boolean
     *
     * @return $this
     */
    public function addNestedWhereQuery($query, $boolean = 'and')
    {
        if (count($query->wheres)) {
            $type = 'Nested';

            $this->wheres[] = compact('type', 'query', 'boolean');

            // Now that all the nested queries are been compiled,
            // we need to propagate the matches to the parent model.
            $this->matches = $query->matches;

            // Set the returned columns.
            $this->columns = $query->columns;

            // Set to carry the required nodes and relations
            $this->with = $query->with;

            $this->addBinding($query->getBindings(), 'where');
        }

        return $this;
    }

    /**
     * Add an or where between statement to the query.
     *
     * @param string $column
     * @param array  $values
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * Add a full sub-select to the query.
     *
     * @param string   $column
     * @param string   $operator
     * @param \Closure $callback
     * @param string   $boolean
     *
     * @return $this
     */
    protected function whereSub($column, $operator, $callback, $boolean)
    {
        $type = 'Sub';

        $query = $this->newQuery();

        // Once we have the query instance we can simply execute it so it can add all
        // of the sub-select's conditions to itself, and then we can cache it off
        // in the array of where clauses for the "main" parent query instance.
        call_user_func($callback, $query);

        $this->wheres[] = compact('type', 'column', 'operator', 'query', 'boolean');

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add an exists clause to the query.
     *
     * @param \Closure $callback
     * @param string   $boolean
     * @param bool     $not
     *
     * @return $this
     */
    public function whereExists($callback, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotExists' : 'Exists';

        $query = $this->newQuery();

        // Similar to the sub-select clause, we will create a new query instance so
        // the developer may cleanly specify the entire exists query and we will
        // compile the whole thing in the grammar and insert it into the SQL.
        call_user_func($callback, $query);

        $this->wheres[] = compact('type', 'operator', 'query', 'boolean');

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add an or exists clause to the query.
     *
     * @param \Closure $callback
     * @param bool     $not
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereExists($callback, $not = false)
    {
        return $this->whereExists($callback, 'or', $not);
    }

    /**
     * Add a where not exists clause to the query.
     *
     * @param \Closure $callback
     * @param string   $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereNotExists($callback, $boolean = 'and')
    {
        return $this->whereExists($callback, $boolean, true);
    }

    /**
     * Add a where not exists clause to the query.
     *
     * @param \Closure $callback
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereNotExists($callback)
    {
        return $this->orWhereExists($callback, true);
    }

    /**
     * Add an "or where in" clause to the query.
     *
     * @param string $column
     * @param mixed  $values
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param string $column
     * @param mixed  $values
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not in" clause to the query.
     *
     * @param string $column
     * @param mixed  $values
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereNotIn($column, $values)
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * Add a where in with a sub-select to the query.
     *
     * @param string   $column
     * @param \Closure $callback
     * @param string   $boolean
     * @param bool     $not
     *
     * @return $this
     */
    protected function whereInSub($column, Closure $callback, $boolean, $not)
    {
        $type = $not ? 'NotInSub' : 'InSub';

        // To create the exists sub-select, we will actually create a query and call the
        // provided callback with the query so the developer may set any of the query
        // conditions they want for the in clause, then we'll put it in this array.
        call_user_func($callback, $query = $this->newQuery());

        $this->wheres[] = compact('type', 'column', 'query', 'boolean');

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add an "or where null" clause to the query.
     *
     * @param string $column
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereNull($column)
    {
        return $this->whereNull($column, 'or');
    }

    /**
     * Add a "where not null" clause to the query.
     *
     * @param string $column
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereNotNull($column, $boolean = 'and')
    {
        return $this->whereNull($column, $boolean, true);
    }

    /**
     * Add an "or where not null" clause to the query.
     *
     * @param string $column
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orWhereNotNull($column)
    {
        return $this->whereNotNull($column, 'or');
    }

    /**
     * Increment the value of an existing column on a where clause.
     * Used to allow querying on the same attribute with different values.
     *
     * @param string $column
     *
     * @return string
     */
    protected function prepareBindingColumn($column)
    {
        $count = $this->columnCountForWhereClause($column);

        $binding = ($count > 0) ? $column.'_'.($count + 1) : $column;

        $prefix = $this->from;
        if (is_array($prefix)) {
            $prefix = implode('_', $prefix);
        }

        // we prefix when we do have a prefix ($this->from) and when the column isn't an id (id(abc..)).
        $prefix = (!preg_match('/id([a-zA-Z0-9]?)/', $column) && !empty($this->from)) ? mb_strtolower($prefix) : '';

        return $prefix.$binding;
    }

    /**
     * Add a "where date" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param int    $value
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereDate($column, $operator, $value = null, $boolean = 'and')
    {
        return $this->addDateBasedWhere('Date', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where day" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param int    $value
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereDay($column, $operator, $value = null, $boolean = 'and')
    {
        return $this->addDateBasedWhere('Day', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where month" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param int    $value
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereMonth($column, $operator, $value = null, $boolean = 'and')
    {
        return $this->addDateBasedWhere('Month', $column, $operator, $value, $boolean);
    }

    /**
     * Add a "where year" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param int    $value
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereYear($column, $operator, $value = null, $boolean = 'and')
    {
        return $this->addDateBasedWhere('Year', $column, $operator, $value, $boolean);
    }

    /**
     * Add a date based (year, month, day) statement to the query.
     *
     * @param string $type
     * @param string $column
     * @param string $operator
     * @param int    $value
     * @param string $boolean
     *
     * @return $this
     */
    protected function addDateBasedWhere($type, $column, $operator, $value, $boolean = 'and')
    {
        $this->wheres[] = compact('column', 'type', 'boolean', 'operator', 'value');

        $this->addBinding($value, 'where');

        return $this;
    }

    /**
     * Handles dynamic "where" clauses to the query.
     *
     * @param string $method
     * @param string $parameters
     *
     * @return $this
     */
    public function dynamicWhere($method, $parameters)
    {
        $finder = substr($method, 5);

        $segments = preg_split('/(And|Or)(?=[A-Z])/', $finder, -1, PREG_SPLIT_DELIM_CAPTURE);

        // The connector variable will determine which connector will be used for the
        // query condition. We will change it as we come across new boolean values
        // in the dynamic method strings, which could contain a number of these.
        $connector = 'and';

        $index = 0;

        foreach ($segments as $segment) {
            // If the segment is not a boolean connector, we can assume it is a column's name
            // and we will add it to the query as a new constraint as a where clause, then
            // we can keep iterating through the dynamic method string's segments again.
            if ($segment != 'And' && $segment != 'Or') {
                $this->addDynamic($segment, $connector, $parameters, $index);

                ++$index;
            }

            // Otherwise, we will store the connector so we know how the next where clause we
            // find in the query should be connected to the previous ones, meaning we will
            // have the proper boolean connector to connect the next where clause found.
            else {
                $connector = $segment;
            }
        }

        return $this;
    }

    /**
     * Add a single dynamic where clause statement to the query.
     *
     * @param string $segment
     * @param string $connector
     * @param array  $parameters
     * @param int    $index
     */
    protected function addDynamic($segment, $connector, $parameters, $index)
    {
        // Once we have parsed out the columns and formatted the boolean operators we
        // are ready to add it to this query as a where clause just like any other
        // clause on the query. Then we'll increment the parameter index values.
        $bool = strtolower($connector);

        $this->where(Str::snake($segment), '=', $parameters[$index], $bool);
    }

    /**
     * Add a "group by" clause to the query.
     *
     * @param array|string $column,...
     *
     * @return $this
     */
    public function groupBy(...$groups)
    {
        foreach (func_get_args() as $arg) {
            $this->groups = array_merge((array) $this->groups, is_array($arg) ? $arg : [$arg]);
        }

        return $this;
    }

    /**
     * Add a "having" clause to the query.
     *
     * @param string $column
     * @param string $operator
     * @param string $value
     * @param string $boolean
     *
     * @return $this
     */
    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        $type = 'basic';

        $this->havings[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (!$value instanceof Expression) {
            $this->addBinding($value, 'having');
        }

        return $this;
    }

    /**
     * Add a "or having" clause to the query.
     *
     * @param string $column
     * @param string $operator
     * @param string $value
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orHaving($column, $operator = null, $value = null)
    {
        return $this->having($column, $operator, $value, 'or');
    }

    /**
     * Add a raw having clause to the query.
     *
     * @param string $sql
     * @param array  $bindings
     * @param string $boolean
     *
     * @return $this
     */
    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        $type = 'raw';

        $this->havings[] = compact('type', 'sql', 'boolean');

        $this->addBinding($bindings, 'having');

        return $this;
    }

    /**
     * Add a raw or having clause to the query.
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function orHavingRaw($sql, array $bindings = [])
    {
        return $this->havingRaw($sql, $bindings, 'or');
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction
     *
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $property = $this->unions ? 'unionOrders' : 'orders';
        $direction = strtolower($direction) == 'asc' ? 'asc' : 'desc';

        $this->{$property}[] = compact('column', 'direction');

        return $this;
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param string $column
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function latest($column = 'created_at')
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param string $column
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function oldest($column = 'created_at')
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Add a raw "order by" clause to the query.
     *
     * @param string $sql
     * @param array  $bindings
     *
     * @return $this
     */
    public function orderByRaw($sql, $bindings = [])
    {
        $property = $this->unions ? 'unionOrders' : 'orders';

        $type = 'raw';

        $this->{$property}[] = compact('type', 'sql');

        $this->addBinding($bindings, 'order');

        return $this;
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param int $value
     *
     * @return $this
     */
    public function offset($value)
    {
        $property = $this->unions ? 'unionOffset' : 'offset';

        $this->$property = max(0, $value);

        return $this;
    }

    /**
     * Alias to set the "offset" value of the query.
     *
     * @param int $value
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function skip($value)
    {
        return $this->offset($value);
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param int $value
     *
     * @return $this
     */
    public function limit($value)
    {
        $property = $this->unions ? 'unionLimit' : 'limit';

        if ($value > 0) {
            $this->$property = $value;
        }

        return $this;
    }

    /**
     * Alias to set the "limit" value of the query.
     *
     * @param int $value
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function take($value)
    {
        return $this->limit($value);
    }

    /**
     * Set the limit and offset for a given page.
     *
     * @param int $page
     * @param int $perPage
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function forPage($page, $perPage = 15)
    {
        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }

    /**
     * Add a union statement to the query.
     *
     * @param \Vinelab\NeoEloquent\Query\Builder|\Closure $query
     * @param bool                                        $all
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function union($query, $all = false)
    {
        if ($query instanceof Closure) {
            call_user_func($query, $query = $this->newQuery());
        }

        $this->unions[] = compact('query', 'all');

        $this->addBinding($query->bindings, 'union');

        return $this;
    }

    /**
     * Add a union all statement to the query.
     *
     * @param \Vinelab\NeoEloquent\Query\Builder|\Closure $query
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function unionAll($query)
    {
        return $this->union($query, true);
    }

    /**
     * Lock the selected rows in the table.
     *
     * @param bool $value
     *
     * @return $this
     */
    public function lock($value = true)
    {
        $this->lock = $value;

        return $this;
    }

    /**
     * Lock the selected rows in the table for updating.
     *
     * @return \Vinelab\NeoEloquent\Query\Builder
     */
    public function lockForUpdate()
    {
        return $this->lock(true);
    }

    /**
     * Share lock the selected rows in the table.
     *
     * @return \Vinelab\NeoEloquent\Query\Builder
     */
    public function sharedLock()
    {
        return $this->lock(false);
    }

    /**
     * Execute a query for a single record by ID.
     *
     * @param int   $id
     * @param array $columns
     *
     * @return mixed|static
     */
    public function find($id, $columns = ['*'])
    {
        return $this->where('id', '=', $id)->first($columns);
    }

    /**
     * Get a single column's value from the first result of a query.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function value($column)
    {
        $result = (array) $this->first([$column]);

        return count($result) > 0 ? reset($result) : null;
    }

    /**
     * Get a single column's value from the first result of a query.
     *
     * This is an alias for the "value" method.
     *
     * @param string $column
     *
     * @return mixed
     *
     * @deprecated since version 5.1.
     */
    public function pluck($column, $key = null)
    {
        return $this->value($column);
    }

    /**
     * Execute the query and get the first result.
     *
     * @param array $columns
     *
     * @return mixed|static
     */
    public function first($columns = ['*'])
    {
        $results = $this->take(1)->get($columns);

        return count($results) > 0 ? reset($results) : null;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param array $columns
     *
     * @return array|static[]
     */
    public function get($columns = ['*'])
    {
        return $this->getFresh($columns);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param int      $perPage
     * @param array    $columns
     * @param string   $pageName
     * @param int|null $page
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $total = $this->getCountForPagination($columns);

        $results = $this->forPage($page, $perPage)->get($columns);

        return new LengthAwarePaginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Get a paginator only supporting simple next and previous links.
     *
     * This is more efficient on larger data-sets, etc.
     *
     * @param int    $perPage
     * @param array  $columns
     * @param string $pageName
     *
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = Paginator::resolveCurrentPage($pageName);

        $this->skip(($page - 1) * $perPage)->take($perPage + 1);

        return new Paginator($this->get($columns), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Get the count of the total records for the paginator.
     *
     * @param array $columns
     *
     * @return int
     */
    public function getCountForPagination($columns = ['*'])
    {
        $this->backupFieldsForCount();

        $this->aggregate = ['function' => 'count', 'columns' => $columns];

        $results = $this->get();

        $this->aggregate = null;

        $this->restoreFieldsForCount();

        if (isset($this->groups)) {
            return count($results);
        }

        return isset($results[0]) ? (int) array_change_key_case((array) $results[0])['aggregate'] : 0;
    }

    /**
     * Backup some fields for the pagination count.
     */
    protected function backupFieldsForCount()
    {
        foreach (['orders', 'limit', 'offset', 'columns'] as $field) {
            $this->backups[$field] = $this->{$field};

            $this->{$field} = null;
        }

        foreach (['order', 'select'] as $key) {
            $this->bindingBackups[$key] = $this->bindings[$key];

            $this->bindings[$key] = [];
        }
    }

    /**
     * Restore some fields after the pagination count.
     */
    protected function restoreFieldsForCount()
    {
        foreach (['orders', 'limit', 'offset', 'columns'] as $field) {
            $this->{$field} = $this->backups[$field];
        }

        foreach (['order', 'select'] as $key) {
            $this->bindings[$key] = $this->bindingBackups[$key];
        }

        $this->backups = [];
        $this->bindingBackups = [];
    }

    /**
     * Chunk the results of the query.
     *
     * @param int      $count
     * @param callable $callback
     */
    public function chunk($count, callable $callback)
    {
        $results = $this->forPage($page = 1, $count)->get();

        while (count($results) > 0) {
            // On each chunk result set, we will pass them to the callback and then let the
            // developer take care of everything within the callback, which allows us to
            // keep the memory low for spinning through large result sets for working.
            if (call_user_func($callback, $results) === false) {
                break;
            }

            ++$page;

            $results = $this->forPage($page, $count)->get();
        }
    }

    /**
     * Get an array with the values of a given column.
     *
     * @param string $column
     * @param string $key
     *
     * @return array
     */
    public function lists($column, $key = null)
    {
        $columns = $this->getListSelect($column, $key);

        $results = new Collection($this->get($columns));

        return $results->pluck($columns[0], Arr::get($columns, 1))->all();
    }

    /**
     * Get the columns that should be used in a list array.
     *
     * @param string $column
     * @param string $key
     *
     * @return array
     */
    protected function getListSelect($column, $key)
    {
        $select = is_null($key) ? [$column] : [$column, $key];

        // If the selected column contains a "dot", we will remove it so that the list
        // operation can run normally. Specifying the table is not needed, since we
        // really want the names of the columns as it is in this resulting array.
        return array_map(function ($column) {
            $dot = strpos($column, '.');

            return $dot === false ? $column : substr($column, $dot + 1);
        }, $select);
    }

    /**
     * Concatenate values of a given column as a string.
     *
     * @param string $column
     * @param string $glue
     *
     * @return string
     */
    public function implode($column, $glue = null)
    {
        if (is_null($glue)) {
            return implode($this->lists($column));
        }

        return implode($glue, $this->lists($column));
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists()
    {
        $limit = $this->limit;

        $result = $this->limit(1)->count() > 0;

        $this->limit($limit);

        return $result;
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param string $columns
     *
     * @return int
     */
    public function count($columns = '*')
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        return (int) $this->aggregate(__FUNCTION__, $columns);
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * @param string $column
     *
     * @return float|int
     */
    public function min($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * @param string $column
     *
     * @return float|int
     */
    public function max($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the sum of the values of a given column.
     *
     * @param string $column
     *
     * @return float|int
     */
    public function sum($column)
    {
        $result = $this->aggregate(__FUNCTION__, [$column]);

        return $result ?: 0;
    }

    /**
     * Retrieve the average of the values of a given column.
     *
     * @param string $column
     *
     * @return float|int
     */
    public function avg($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param string $column
     * @param int    $amount
     * @param array  $extra
     *
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $wrapped = $this->grammar->wrap($column);

        $columns = array_merge([$column => $this->raw("$wrapped + $amount")], $extra);

        return $this->update($columns);
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param string $column
     * @param int    $amount
     * @param array  $extra
     *
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $wrapped = $this->grammar->wrap($column);

        $columns = array_merge([$column => $this->raw("$wrapped - $amount")], $extra);

        return $this->update($columns);
    }

    /**
     * Delete a record from the database.
     *
     * @param mixed $id
     *
     * @return int
     */
    public function delete($id = null)
    {
        // If an ID is passed to the method, we will set the where clause to check
        // the ID to allow developers to simply and quickly remove a single row
        // from their database without manually specifying the where clauses.
        if (!is_null($id)) {
            $this->where('id', '=', $id);
        }

        $cypher = $this->grammar->compileDelete($this);

        $result = $this->connection->delete($cypher, $this->getBindings());

        if ($result instanceof Result) {
            $result = true;
        }

        return $result;
    }

    /**
     * Run a truncate statement on the table.
     */
    public function truncate()
    {
        foreach ($this->grammar->compileTruncate($this) as $sql => $bindings) {
            $this->connection->statement($sql, $bindings);
        }
    }

    /**
     * Remove all of the expressions from a list of bindings.
     *
     * @param array $bindings
     *
     * @return array
     */
    public function cleanBindings(array $bindings)
    {
        return array_values(array_filter($bindings, function ($binding) {
            return !$binding instanceof Expression;
        }));
    }

    /**
     * Create a raw database expression.
     *
     * @param mixed $value
     *
     * @return \Vinelab\NeoEloquent\Query\Expression
     */
    public function raw($value)
    {
        return $this->connection->raw($value);
    }

    /**
     * Get the raw array of bindings.
     *
     * @return array
     */
    public function getRawBindings()
    {
        return $this->bindings;
    }

    /**
     * Set the bindings on the query builder.
     *
     * @param array  $bindings
     * @param string $type
     *
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function setBindings(array $bindings, $type = 'where')
    {
        if (!array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        $this->bindings[$type] = $bindings;

        return $this;
    }

    /**
     * Merge an array of bindings into our bindings.
     *
     * @param \Vinelab\NeoEloquent\Query\Builder $query
     *
     * @return $this
     */
    public function mergeBindings(BaseBuilder $query)
    {
        $this->bindings = array_merge_recursive($this->bindings, $query->bindings);

        return $this;
    }

    /**
     * Get the database connection instance.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get the database query processor instance.
     *
     * @return \Vinelab\NeoEloquent\Query\Processors\Processor
     */
    public function getProcessor()
    {
        return $this->processor;
    }

    /**
     * Get the query grammar instance.
     *
     * @return \Vinelab\NeoEloquent\Query\Grammars\Grammar
     */
    public function getGrammar()
    {
        return $this->grammar;
    }

    /**
     * Get the number of occurrences of a column in where clauses.
     *
     * @param string $column
     *
     * @return int
     */
    protected function columnCountForWhereClause($column)
    {
        if (is_array($this->wheres)) {
            return count(array_filter($this->wheres, function ($where) use ($column) {
                return $where['column'] == $column;
            }));
        }
    }

    /**
     * Add a "where in" clause to the query.
     *
     * @param string $column
     * @param mixed  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

        // If the value of the where in clause is actually a Closure, we will assume that
        // the developer is using a full sub-select for this "in" statement, and will
        // execute those Closures, then we can re-construct the entire sub-selects.
        if ($values instanceof Closure) {
            return $this->whereInSub($column, $values, $boolean, $not);
        }

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $property = $column;

        if ($column == 'id') {
            $column = 'id('.$this->modelAsNode().')';
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        $property = $this->wrap($property);

        $this->addBinding([$property => $values], 'where');

        return $this;
    }

    /**
     * Add a where between statement to the query.
     *
     * @param string $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        $property = $column;

        if ($column == 'id') {
            $column = 'id('.$this->modelAsNode().')';
        }

        $this->wheres[] = compact('column', 'type', 'boolean', 'not');

        $this->addBinding([$property => $values], 'where');

        return $this;
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param string $column
     * @param string $boolean
     * @param bool   $not
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';

        if ($column == 'id') {
            $column = 'id('.$this->modelAsNode().')';
        }

        $binding = $this->prepareBindingColumn($column);

        $this->wheres[] = compact('type', 'column', 'boolean', 'binding');

        return $this;
    }

    /**
     * Add a WHERE statement with carried identifier to the query.
     *
     * @param string $column
     * @param string $operator
     * @param string $value
     * @param string $boolean
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function whereCarried($column, $operator = null, $value = null, $boolean = 'and')
    {
        $type = 'Carried';

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add a WITH clause to the query.
     *
     * @param array $parts
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function with(array $parts)
    {
        if($this->isAssocArray($parts)) {
            foreach ($parts as $key => $part) {
                if (!in_array($part, $this->with)) {
                    $this->with[$key] = $part;
                }
            }
        } else {
            foreach ($parts as $part) {
                if (!in_array($part, $this->with)) {
                    $this->with[] = $part;
                }
            }
        }

        return $this;
    }

    /**
     * Insert a new record into the database.
     *
     * @param array $values
     *
     * @return bool
     */
    public function insert(array $values)
    {
        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        if (!is_array(reset($values))) {
            $values = array($values);
        }

        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        else {
            foreach ($values as $key => $value) {
                $value = $this->formatValue($value);
                ksort($value);
                $values[$key] = $value;
            }
        }

        // We'll treat every insert like a batch insert so we can easily insert each
        // of the records into the database consistently. This will make it much
        // easier on the grammars to just handle one type of record insertion.
        $bindings = array();

        foreach ($values as $record) {
            $bindings[] = $record;
        }

        $cypher = $this->grammar->compileInsert($this, $values);

        // Once we have compiled the insert statement's Cypher we can execute it on the
        // connection and return a result as a boolean success indicator as that
        // is the same type of result returned by the raw connection instance.
        $bindings = $this->cleanBindings($bindings);

        $results = $this->connection->insert($cypher, $bindings);

        return !!$results;
    }

    /**
     * Create a new node with related nodes with one database hit.
     *
     * @param array $model
     * @param array $related
     *
     * @return \Vinelab\NeoEloquent\Eloquent\Model
     */
    public function createWith(array $model, array $related)
    {
        $cypher = $this->grammar->compileCreateWith($this, compact('model', 'related'));

        // Indicate that we need the result returned as is.
        return $this->connection->statement($cypher, [], true);
    }

    /**
     * Execute the query as a fresh "select" statement.
     *
     * @param array $columns
     *
     * @return array|static[]
     */
    public function getFresh($columns = array('*'))
    {
        if (is_null($this->columns)) {
            $this->columns = $columns;
        }

        return $this->runSelect();
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        return $this->connection->select($this->toCypher(), $this->getBindings());
    }

    /**
     * Get the Cypher representation of the traversal.
     *
     * @return string
     */
    public function toCypher()
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Add a relationship MATCH clause to the query.
     *
     * @param \Vinelab\NeoEloquent\Eloquent\Model $parent       The parent model of the relationship
     * @param \Vinelab\NeoEloquent\Eloquent\Model $related      The related model
     * @param string                              $relatedNode  The related node' placeholder
     * @param string                              $relationship The relationship title
     * @param string                              $property     The parent's property we are matching against
     * @param string                              $value
     * @param string                              $direction    Possible values are in, out and in-out
     * @param string                              $boolean      And, or operators
     *
     * @return \Vinelab\NeoEloquent\Query\Builder|static
     */
    public function matchRelation($parent, $related, $relatedNode, $relationship, $property, $value = null, $direction = 'out', $boolean = 'and')
    {
        $parentLabels = $parent->nodeLabel();
        $relatedLabels = $related->nodeLabel();
        $parentNode = $this->modelAsNode($parentLabels);

        $this->matches[] = array(
            'type' => 'Relation',
            'optional' => $boolean,
            'property' => $property,
            'direction' => $direction,
            'relationship' => $relationship,
            'parent' => array(
                'node' => $parentNode,
                'labels' => $parentLabels,
            ),
            'related' => array(
                'node' => $relatedNode,
                'labels' => $relatedLabels,
            ),
        );

        $this->addBinding(array($this->wrap($property) => $value), 'matches');

        return $this;
    }

    public function matchMorphRelation($parent, $relatedNode, $property, $value = null, $direction = 'out', $boolean = 'and')
    {
        $parentLabels = $parent->nodeLabel();
        $parentNode = $this->modelAsNode($parentLabels);

        $this->matches[] = array(
            'type' => 'MorphTo',
            'optional' => 'and',
            'property' => $property,
            'direction' => $direction,
            'related' => array('node' => $relatedNode),
            'parent' => array(
                'node' => $parentNode,
                'labels' => $parentLabels,
            ),
        );

        $this->addBinding(array($property => $value), 'matches');

        return $this;
    }

    /**
     * the percentile of a given value over a group,
     * with a percentile from 0.0 to 1.0.
     * It uses a rounding method, returning the nearest value to the percentile.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function percentileDisc($column, $percentile = 0.0)
    {
        return $this->aggregate(__FUNCTION__, array($column), $percentile);
    }

    /**
     * Retrieve the percentile of a given value over a group,
     * with a percentile from 0.0 to 1.0. It uses a linear interpolation method,
     * calculating a weighted average between two values,
     * if the desired percentile lies between them.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function percentileCont($column, $percentile = 0.0)
    {
        return $this->aggregate(__FUNCTION__, array($column), $percentile);
    }

    /**
     * Retrieve the standard deviation for a given column.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function stdev($column)
    {
        return $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Retrieve the standard deviation of an entire group for a given column.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function stdevp($column)
    {
        return $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Get the collected values of the give column.
     *
     * @param string $column
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function collect($column)
    {
        $row = $this->aggregate(__FUNCTION__, array($column));

        $collected = [];

        foreach ($row as $value) {
            $collected[] = $value;
        }

        return new Collection($collected);
    }

    /**
     * Get the count of the disctinct values of a given column.
     *
     * @param string $column
     *
     * @return int
     */
    public function countDistinct($column)
    {
        return (int) $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Execute an aggregate function on the database.
     *
     * @param string $function
     * @param array  $columns
     *
     * @return mixed
     */
    public function aggregate($function, $columns = array('*'), $percentile = null)
    {
        $this->aggregate = array_merge([
            'label' => $this->from,
        ], compact('function', 'columns', 'percentile'));

        $previousColumns = $this->columns;

        $results = $this->get($columns);

        // Once we have executed the query, we will reset the aggregate property so
        // that more select queries can be executed against the database without
        // the aggregate value getting in the way when the grammar builds it.
        $this->aggregate = null;

        $this->columns = $previousColumns;

        $values = $this->getRecordsByPlaceholders($results);

        $value = reset($values);
        if(is_array($value)) {
            return current($value);
        } else {
            return $value;
        }
    }

    /**
     * Add a binding to the query.
     *
     * @param mixed  $value
     * @param string $type
     *
     * @return \Vinelab\NeoEloquent\Query\Builder
     */
    public function addBinding($value, $type = 'where')
    {
        if (is_array($value)) {
            $key = array_keys($value)[0];

            if (strpos($key, '.') !== false) {
                $binding = $value[$key];
                unset($value[$key]);
                $key = explode('.', $key)[1];
                $value[$key] = $binding;
            }
        }

        if (!array_key_exists($type, $this->bindings)) {
            throw new \InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_merge($this->bindings[$type], $value);
        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }

    /**
     * Convert a string into a Neo4j Label.
     *
     * @param string $label
     *
     * @return Everyman\Neo4j\Label
     */
    public function makeLabel($label)
    {
        return $this->client->makeLabel($label);
    }

    /**
     * Tranfrom a model's name into a placeholder
     * for fetched properties. i.e.:.
     *
     * MATCH (user:`User`)... "user" is what this method returns
     * out of User (and other labels).
     * PS: It consideres the first value in $labels
     *
     * @param array $labels
     *
     * @return string
     */
    public function modelAsNode(array $labels = null)
    {
        $labels = (!is_null($labels)) ? $labels : $this->from;

        return $this->grammar->modelAsNode($labels);
    }

    /**
     * Merge an array of where clauses and bindings.
     *
     * @param array $wheres
     * @param array $bindings
     */
    public function mergeWheres($wheres, $bindings)
    {
        $this->wheres = array_merge((array) $this->wheres, (array) $wheres);

        $this->bindings['where'] = array_merge_recursive($this->bindings['where'], (array) $bindings);
    }

    public function wrap($property)
    {
        return $this->grammar->getIdReplacement($property);
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return \Vinelab\NeoEloquent\Query\Builder
     */
    public function newQuery()
    {
        return new self($this->connection, $this->grammar);
    }

    /**
     * Fromat the value into its string representation.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function formatValue($value)
    {
        // If the value is a date we'll format it according to the specified
        // date format.
        if ($value instanceof DateTime || $value instanceof Carbon) {
            $value = $value->format($this->grammar->getDateFormat());
        }

        return $value;
    }

    /*
     * Add/Drop labels
     * @param $labels array array of strings(labels)
     * @param $operation string 'add' or 'drop'
     * @return bool true if success, otherwise false
     */
    public function updateLabels($labels, $operation = 'add')
    {
        $cypher = $this->grammar->compileUpdateLabels($this, $labels, $operation);

        $result = $this->connection->update($cypher, $this->getBindings());

        return (bool) $result;
    }

    public function getNodesCount($result)
    {
        return count($this->getNodeRecords($result));
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        if (Str::startsWith($method, 'where')) {
            return $this->dynamicWhere($method, $parameters);
        }

        $className = get_class($this);

        throw new BadMethodCallException("Call to undefined method {$className}::{$method}()");
    }

    /**
     * Determine whether an array is associative.
     *
     * @param array $array
     *
     * @return bool
     */
    protected function isAssocArray($array)
    {
        return is_array($array) && array_keys($array) !== range(0, count($array) - 1);
    }

}
