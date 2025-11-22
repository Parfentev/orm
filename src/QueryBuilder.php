<?php

namespace ORM;

class QueryBuilder
{
    private array  $select  = [];
    private array  $from    = [];
    private array  $join    = [];
    private array  $where   = [];
    private array  $groupBy = [];
    private array  $having  = [];
    private array  $orderBy = [];
    private string $limit   = '';

    private array $update = [];
    private array $set    = [];

    private array $tableAliases = [];
    private array $columnAliases = [];

    private array $args = [];

    const JOIN_TYPE_INNER = 'INNER';
    const JOIN_TYPE_LEFT  = 'LEFT';

    const DIRECTION_ASC  = 'ASC';
    const DIRECTION_DESC = 'DESC';

    /**
     * Добавляет выборку столбца в SELECT
     *
     * @param string $field Столбец
     * @param string|null $alias Алиас столбца
     *
     * @return QueryBuilder
     */
    public function addSelect(string $field, ?string $alias = ''): self
    {
        if ($alias && in_array($alias, $this->columnAliases)) {
            return $this;
        }
        $field                 = $alias ? sprintf("%s AS '%s'", $field, $alias) : $field;
        $this->select[]        = $field;
        $alias && $this->columnAliases[] = $alias;

        return $this;
    }

    /**
     * Добавляет таблицы в FROM
     *
     * @param string $table Название таблицы
     * @param string|null $alias Алиас таблицы
     *
     * @return QueryBuilder
     */
    public function addFrom(string $table, ?string $alias = ''): self
    {
        if ($alias && in_array($alias, $this->tableAliases)) {
            return $this;
        }
        $table                = $this->getTableString($table, $alias);
        $this->from[]         = $table;
        $alias && $this->tableAliases[] = $alias;

        return $this;
    }

    /**
     * Добавляет JOIN с таблицей
     *
     * @param string $table Таблица
     * @param string|null $alias Алиас таблицы
     * @param string|null $clause Условие (ON/USING)
     * @param string $type Тип JOIN'a
     *
     * @return QueryBuilder
     */
    public function addJoin(string $table, ?string $alias = '', ?string $clause = '', $type = self::JOIN_TYPE_INNER): self
    {
        if ($alias && in_array($alias, $this->tableAliases)) {
            return $this;
        }

        if ($clause && preg_match('#^(ON|USING)#i', $clause) !== 1) {
            $this->join[] = "***$table: Отсутствует ON или USING в условии для JOIN. ***";
            return $this;
        }

        $table = $this->getTableString($table, $alias);
        $join  = sprintf('%s JOIN %s', $type, $table);

        $this->join[]         = $clause ? sprintf("%s\r\n    %s", $join, $clause) : $join;
        $alias && $this->tableAliases[] = $alias;
        return $this;
    }

    /**
     * Добавляет LEFT JOIN
     *
     * @param string $table Таблица
     * @param string|null $alias Алиас таблицы
     * @param string|null $clause Условие (ON/USING)
     *
     * @return QueryBuilder
     */
    public function addLeftJoin(string $table, ?string $alias = '', string $clause = ''): self
    {
        return $this->addJoin($table, $alias, $clause, self::JOIN_TYPE_LEFT);
    }

    /**
     * Добавляет необработанную строку с JOIN
     *
     * @param string $join Строка запроса
     *
     * @return QueryBuilder
     */
    public function addRawJoin(string $join): self
    {
        $this->join[] = $join;
        return $this;
    }

    /**
     * Добавляет условие в WHERE
     *
     * @param string $clause Условие
     *
     * @return QueryBuilder
     */
    public function addWhere(string $clause): self
    {
        if ($this->where && !preg_match('#^\s*(AND|OR)#i', $clause)) {
            $clause = 'AND ' . $clause;
        }

        $this->where[] = $clause;
        return $this;
    }

    public function addExists(QueryBuilder $query): self
    {
        return $this->addWhere($this->getExistsQueryString($query));
    }

    public function getExistsQueryString(?QueryBuilder $query = null): string
    {
        global $wpdb;

        !$query && $query = $this;

        $query
            ->removeSelect()
            ->removeGroupBy()
            ->addSelect(1);

        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $query = $args ? $wpdb->prepare($query, $args) : $query;

        return "EXISTS ($query)";
    }

    /**
     * Добавляет выражение для группировки в GROUP BY
     *
     * @param string $expr Столбец/Выражение/Позиция
     *
     * @return QueryBuilder
     */
    public function addGroupBy(string $expr): self
    {
        $this->groupBy[] = $expr;
        return $this;
    }

    /**
     * Добавляет условие в HAVING
     *
     * @param string $clause Условие
     *
     * @return QueryBuilder
     */
    public function addHaving(string $clause): self
    {
        $this->having[] = $clause;
        return $this;
    }

    /**
     * Добавление столбец в сортировку ORDER BY
     *
     * @param string $field Столбец
     * @param string $direction Направление сортировки (ASC/DESC)
     *
     * @return QueryBuilder
     */
    public function addOrderBy(string $field, string $direction = self::DIRECTION_ASC): self
    {
        $direction = mb_strtoupper($direction);
        $this->orderBy[] = sprintf('%s %s', $field, $direction);
        return $this;
    }

    /**
     * Устанавливает LIMIT
     *
     * @param int $limit Максимальное кол-во записей
     * @param int $offset Смещение
     *
     * @return QueryBuilder
     */
    public function setLimit(int $limit, int $offset = 0): self
    {
        $this->limit = $offset ? sprintf('%d, %d', $offset, $limit) : $limit;
        return $this;
    }

    /**
     * Добавляет UPDATE
     *
     * @param string $table Таблица
     * @param string|null $alias Алиас таблицы
     *
     * @return QueryBuilder
     */
    public function addUpdate(string $table, ?string $alias = ''): self
    {
        if ($alias && in_array($alias, $this->tableAliases)) {
            return $this;
        }

        $table = $this->getTableString($table, $alias);
        $this->update[] = $table;
        $alias && $this->tableAliases[] = $alias;

        return $this;
    }

    /**
     * Добавляет SET
     *
     * @param string $field Столбец
     * @param mixed $value Новое значение
     *
     * @return QueryBuilder
     */
    public function addSet(string $field, $value): self
    {
        $this->set[] = $field . ' = ' . $value;
        return $this;
    }

    /**
     * Удаляет SELECT
     *
     * @return QueryBuilder
     */
    public function removeSelect(): self
    {
        $this->select = [];
        return $this;
    }

    /**
     * Удаляет FROM
     *
     * @return QueryBuilder
     */
    public function removeFrom(): self
    {
        $this->from = [];
        return $this;
    }

    /**
     * Удаляет WHERE
     *
     * @return QueryBuilder
     */
    public function removeWhere(): self
    {
        $this->where = [];
        return $this;
    }

    /**
     * Удаляет GROUP BY
     *
     * @return QueryBuilder
     */
    public function removeGroupBy(): self
    {
        $this->groupBy = [];
        return $this;
    }

    /**
     * Удаляет HAVING
     *
     * @return QueryBuilder
     */
    public function removeHaving(): self
    {
        $this->having = [];
        return $this;
    }

    /**
     * Удаляет ORDER BY
     *
     * @return QueryBuilder
     */
    public function removeOrderBy(): self
    {
        $this->orderBy = [];
        return $this;
    }

    /**
     * Удаляет LIMIT
     *
     * @return QueryBuilder
     */
    public function removeLimit(): self
    {
        $this->limit = '';
        return $this;
    }

    /**
     * Подготавливает и возвращает строку SQL запроса
     *
     * @return string
     */
    public function getQueryString()
    {
        $query = [
            'select'   => '',
            'update'   => '',
            'from'     => '',
            'join'     => '',
            'set'      => '',
            'where'    => '',
            'group_by' => '',
            'having'   => '',
            'order_by' => '',
            'limit'    => '',
        ];

        if (count($this->update) > 1) {
            // Не работает с многотабличным обновлением, удаляем
            $this->removeOrderBy();
            $this->removeLimit();
        }

        if ($this->select) {
            $query['select'] = 'SELECT ' . implode(', ', $this->select);
        }

        if ($this->update) {
            $query['update'] = 'UPDATE ' . implode(', ', $this->update);
        }

        if ($this->from) {
            $query['from'] = 'FROM ' . implode(', ', $this->from);
        }

        if ($this->join) {
            $query['join'] = implode(' ', $this->join);
        }

        if ($this->set) {
            $query['set'] = 'SET ' . implode(', ', $this->set);
        }

        if ($this->where) {
            $query['where'] = 'WHERE ' . implode(' ', $this->where);
        }

        if ($this->groupBy) {
            $query['group_by'] = 'GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having) {
            $query['having'] = 'HAVING ' . implode(' ', $this->having);
        }

        if ($this->orderBy) {
            $query['order_by'] = 'ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit) {
            $query['limit'] = 'LIMIT ' . $this->limit;
        }

        $query = array_filter($query);
        $query = implode(' ', $query);

        return trim($query);
    }

    /**
     * Возвращает название таблицы с алиасом или без него
     *
     * @param string $table Таблица
     * @param string|null $alias Алиас таблицы
     *
     * @return string
     */
    private function getTableString(string $table, ?string $alias = ''): string
    {
        return $alias ? sprintf('%s %s', $table, $alias) : $table;
    }

    public function getWhere()
    {
        return $this->where;
    }

    final public function setArgument($value): self
    {
        $this->args[] = $value;
        return $this;
    }

    final public function getArguments(): array
    {
        return $this->args;
    }
}