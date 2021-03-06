<?php //-->
/*
 * This file is part of the Eden package.
 * (c) 2013-2014 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

namespace Eden\Facebook\Fql;

use Eden\Core\Exception as CoreException;
use Eden\Facebook\Argument;
use Eden\Facebook\Base as FacebookBase;
use Eden\Collection\Base as Collection;
use Eden\Type\StringType;
use Eden\Facebook\Fql;

/**
 * Facebook Search
 *
 * @vendor Eden
 * @package Facebook\Fql
 * @author Ian Mark Muninio <ianmuninio@openovate.com>
 */
class Search extends FacebookBase
{
    const INSTANCE = 0;
    const ASC = 'ASC';
    const DESC = 'DESC';

    protected $database = null;
    protected $table = null;
    protected $columns = array();
    protected $filter = array();
    protected $sort = array();
    protected $groups = array();
    protected $start = 0;
    protected $range = 0;

    /**
     * Preload the database.
     *
     * @param \Eden\Facebook\Fql $database the fql instance
     * @return void
     */
    public function __construct(Fql $database)
    {
        $this->database = $database;
    }

    /**
     * Magic method calling for this class.
     *
     * @param string $name
     * @param array $args
     * @return \Eden\Facebook\Fql\Search
     * @throws \Eden\Facebook\Fql\Exception
     */
    public function __call($name, $args)
    {
        Argument::i()
                ->test(1, 'string')
                ->test(2, 'array');
        
        // if they want magical filtering
        if (strpos($name, 'filterBy') === 0) {
            // filterByUserName('Chris', '-')
            $separator = '_';
            if (isset($args[1]) && is_scalar($args[1])) {
                $separator = (string) $args[1];
            }

            // transform method name to column name
            $key = StringType::i($name)
                    ->substr(8)
                    ->preg_replace("/([A-Z])/", $separator . "$1")
                    ->substr(strlen($separator))
                    ->strtolower()
                    ->get();

            if (!isset($args[0])) {
                $args[0] = null;
            }

            $key = $key . '=%s';

            // add filter
            $this->addFilter($key, $args[0]);

            return $this;
        }

        // if they want magical sorting
        if (strpos($name, 'sortBy') === 0) {
            // filterByUserName('Chris', '-')
            $separator = '_';
            if (isset($args[1]) && is_scalar($args[1])) {
                $separator = (string) $args[1];
            }

            // transform method name to column name
            $key = StringType::i($name)
                    ->substr(6)
                    ->preg_replace("/([A-Z])/", $separator . "$1")
                    ->substr(strlen($separator))
                    ->strtolower()
                    ->get();

            if (!isset($args[0])) {
                $args[0] = self::ASC;
            }

            // add sort
            $this->addSort($key, $args[0]);

            return $this;
        }

        try {
            return parent::__call($name, $args);
        } catch (CoreException $e) {
            Exception::i()
                    ->setMessage($e->getMessage())
                    ->trigger();
        }
    }

    /**
     * Adds filter.
     *
     * @param string
     * @param string[,string..]
     * @return \Eden\Facebook\Fql\Search
     */
    public function addFilter($blank)
    {
        Argument::i()->test(1, 'string');

        $this->filter[] = func_get_args();

        return $this;
    }

    /**
     * Adds sort.
     *
     * @param string $column
     * @param string $order  [optional] (default: ASC)
     * @return \Eden\Facebook\Fql\Search
     */
    public function addSort($column, $order = self::ASC)
    {
        Argument::i()
                ->test(1, 'string')
                ->test(2, 'string');

        if ($order != self::DESC) {
            $order = self::ASC;
        }

        $this->sort[$column] = $order;

        return $this;
    }

    /**
     * Returns the results in a collection.
     *
     * @param string 
     * @return array
     */
    public function getCollection($key = 'last')
    {
        Argument::i()->test(1, 'string');

        $rows = $this->getRows($key);

        if (count($this->groups) == 1) {
            return Collection::i($rows);
        }

        foreach ($rows as $key => $collection) {
            $rows[$key] = Collection::i($collection['fql_result_set']);
        }

        return $rows;
    }

    /**
     * Returns the results in array format.
     *
     * @param string $key [optional] (default: last)
     * @return array
     */
    public function getRows($key = 'last')
    {
        Argument::i()->test(1, 'string');
        
        // defne search group
        $this->group($key);

        // if groups are empty
        if (empty($this->groups)) {
            // do nothing
            return array();
        }

        $group = array();
        // we want to run the query now
        foreach ($this->groups as $key => $query) {
            $this->table = $query['table'];
            $this->columns = $query['columns'];
            $this->filter = $query['filter'];
            $this->sort = $query['sort'];
            $this->start = $query['start'];
            $this->range = $query['range'];

            // now get the query
            $query = $this->getQuery();

            // if columns
            if (!empty($this->columns)) {
                // make it into a string
                $query->select(implode(', ', $this->columns));
            }

            // add all the sorts
            foreach ($this->sort as $name => $value) {
                $query->sortBy($name, $value);
            }

            // if range
            if ($this->range) {
                // add pagination
                $query->limit($this->start, $this->range);
            }

            // put it into out temp group
            $group[$key] = $query;
        }

        // run it through FB REST/CURL
        $query = $group;

        if (count($query) == 1) {
            $query = $group[$key];
        }

        return $this->database
                ->query($query);
    }

    /**
     * Returns the total results.
     *
     * @return int
     */
    public function getTotal()
    {
        $query = $this->getQuery()
                ->select('COUNT(*)');

        $rows = $this->database
                ->query($query);

        if (isset($rows)) {
            return sizeOf($rows);
        }

        return 0;
    }

    /**
     * Stores this search and resets class. Useful for multiple queries.
     *
     * @param scalar $key
     * @return \Eden\Facebook\Fql\Search
     */
    public function group($key)
    {
        Argument::i()->test(1, 'scalar');

        // if no table
        if (is_null($this->table)) {
            // theres no point in continuing
            return $this;
        }

        // add the group
        $this->groups[$key] = array(
            'table' => $this->table,
            'columns' => $this->columns,
            'filter' => $this->filter,
            'sort' => $this->sort,
            'start' => $this->start,
            'range' => $this->range);

        // reset the instance
        $this->table = null;
        $this->columns = array();
        $this->filter = array();
        $this->sort = array();
        $this->start = 0;
        $this->range = 0;

        return $this;
    }

    /**
     * Sets Columns.
     *
     * @param string[,string..]|array $columns
     * @return \Eden\Facebook\Fql\Search
     */
    public function setColumns($columns)
    {
        Argument::i()->test(1, 'string', 'array');
        
        // if columns is not an array
        if (!is_array($columns)) {
            // they defined the columns as arguments
            $columns = func_get_args();
        }

        $this->columns = $columns;

        return $this;
    }

    /**
     * Sets the pagination page.
     *
     * @param int $page
     * @return \Eden\Facebook\Fql\Search
     */
    public function setPage($page)
    {
        Argument::i()->test(1, 'int');

        if ($page < 1) {
            $page = 1;
        }

        $this->start = ($page - 1) * $this->range;

        return $this;
    }

    /**
     * Sets the pagination range.
     *
     * @param int $range
     * @return \Eden\Facebook\Fql\Search
     */
    public function setRange($range)
    {
        Argument::i()->test(1, 'int');

        if ($range < 0) {
            $range = 25;
        }

        $this->range = $range;

        return $this;
    }

    /**
     * Sets the pagination start.
     *
     * @param int $start
     * @return \Eden\Facebook\Fql\Search
     */
    public function setStart($start)
    {
        Argument::i()->test(1, 'int');

        if ($start < 0) {
            $start = 0;
        }

        $this->start = $start;

        return $this;
    }

    /**
     * Sets Table.
     *
     * @param string $table
     * @return \Eden\Facebook\Fql\Search
     */
    public function setTable($table)
    {
        Argument::i()->test(1, 'string');
        
        $this->table = $table;

        return $this;
    }

    /**
     * Returns the complete Select class statement.
     *
     * @return \Eden\Facebook\Fql\Select
     */
    protected function getQuery()
    {
        $select = $this->database
                ->select()
                ->from($this->table);

        foreach ($this->filter as $i => $filter) {
            // array('post_id=%s AND post_title IN %s', 123, array('asd'));
            $where = array_shift($filter);
            // make where into a string
            if (!empty($filter)) {
                foreach ($filter as $i => $value) {
                    if (!is_string($value)) {
                        continue;
                    }

                    $filter[$i] = "'" . $value . "'";
                }

                $where = vsprintf($where, $filter);
            }

            $select->where($where);
        }

        return $select;
    }
}
