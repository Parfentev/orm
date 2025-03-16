<?php

namespace ORM\Repository;

use ORM\Util\StringUtil;

class AbstractRepository
{
    protected Table  $table;
    protected Database $db;
    protected string $entityClass;
    protected string $entityNameLog;

    private array  $columnNames = [];
    private string $alias;
    /** @var array Локальное кэширование сущностей */
    private array $entities = [];

    public function __construct($entityClass)
    {
        global $db;
        $this->db          = $db;
        $this->table       = Manager::getTable($entityClass);
        $this->entityClass = $entityClass;

        $this->entityNameLog = substr($entityClass, strrpos($entityClass, '\\') + 1);
        $this->entityNameLog = StringUtil::toSnakeCase($this->entityNameLog);
    }

    // Поиск

    /**
     * Находит запись по первичному ключу
     *
     * @param int $id
     * @param bool $cache
     *
     * @return AbstractEntity
     * @throws NotFoundException
     */
    public function find(int|string $primaryValue, bool $cache = true): AbstractEntity
    {
        if ($cache && isset($this->entities[$this->entityClass][$primaryValue])) {
            return $this->entities[$this->entityClass][$primaryValue];
        }

        $entity = $this->findOneBy([$this->table->getPrimaryKey() => $primaryValue]);

        $this->entities[$this->entityClass][$primaryValue] = $entity;
        return $entity;
    }

    /**
     * Находит запись по указанным критериям
     *
     * @param array $criteria
     *
     * @return AbstractEntity
     * @throws NotFoundException
     */
    public function findOneBy(array $criteria = []): AbstractEntity
    {
        $item = $this->queryRow($this->getQueryBuilder($criteria));
        if (!$item) {
            throw new NotFoundException();
        }

        return $this->prepareItem($item);
    }

    /**
     * Находит записи по указанным критериям, возвращает коллекцию
     * (временно решение, необходимо поправить вызовы findAll)
     *
     * @param array $criteria
     * @param array|null $orderBy
     * @param int|null $limit
     * @param int|null $offset
     * @param bool $withTotal
     *
     * @return Collection
     */
    public function findAll(array $criteria = [], ?array $orderBy = null, ?int $limit = null, ?int $offset = null, bool $withTotal = false): Collection
    {
        $query = $this->getQueryBuilder($criteria);
        $query->removeGroupBy(); // Костыль, нужно перевести все места findAll на этом метод
        $totalQuery = $withTotal ? clone $query : null;
        $this->applySorts($query, $orderBy ?? [], $criteria);
        is_numeric($limit) && $limit > 0 && $query->setLimit($limit, $offset ?? 0);

        return $this->prepareCollection($this->query($query), $totalQuery);
    }

    // Изменение

    /**
     * Создает новый ряд в таблице
     *
     * @param AbstractEntity $entity
     *
     * @return bool
     */
    public function create(AbstractEntity $entity): bool
    {
        $data = [];

        $modifiedColumns = $entity->getModifiedColumns();

        foreach ($this->table->getColumns() as $column) {
            // Не отправляем неизмененные поля, кроме обязательных
            if (!in_array($column->getPropertyName(), $modifiedColumns) && !$column->isRequired()) {
                continue;
            }

            $value = $column->getValue($entity);
            $name  = $column->getName();

            isset($value) && $data[$name] = $value;
        }

        //getenv('RB_ENV') !== 'live' && \RB\Logger::debug('props_test db_insert ' . get_class($entity), $data, true);

        $result = $this->db->insert($this->table->getName(), $data);
        if ($result === false) {
            //Logger::error('props_error entity_create ' . get_class($entity), $this->db->last_error, true);
            return false;
        }

        $lastId = $this->db->lastInsertId();
        // Получаем id созданной записи
        if ($lastId) {
            $primarySetter = 'set'. ucfirst($this->table->getPrimaryKey());
            $entity->{$primarySetter}($lastId);
        }

        $this->table->flushValue($entity);

        //do_action('rb/props/create', $entity);
        return true;
    }

    /**
     * Обновляет ряд в таблице
     *
     * @param AbstractEntity $entity
     *
     * @return bool
     */
    public function update(AbstractEntity $entity): bool
    {
        $data  = [];
        $where = [];

        $modifiedColumns = $entity->getModifiedColumns();
        if (!$modifiedColumns) {
            return true;
        }

        foreach ($this->table->getColumns() as $column) {
            if (!in_array($column->getPropertyName(), $modifiedColumns) && !$column->isPrimary()) {
                continue;
            }

            $value = $column->getValue($entity);
            $name  = $column->getName();

            $column->isPrimary()
                ? $where[$name] = $value
                : $data[$name] = $value;
        }

        if (!$data) {
            //Logger::error('props_error entity_update no_data ' . get_class($entity), [
            //    'modify' => $entity->getModifiedColumns()
            //], true);
            return true;
        }

        if (!$where) {
            //Logger::error('props_error entity_update no_where ' . get_class($entity), 'Не указан первичный ключ', true);
            return false;
        }

        //getenv('RB_ENV') !== 'live' && \RB\Logger::debug('props_test db_update ' . get_class($entity), $data, true);
        $result = $this->db->update($this->table->getName(), $data, $where);
        if ($result === false) {
            //Logger::error('props_error entity_update ' . get_class($entity), $this->db->last_error, true);
            return false;
        }

        //do_action('rb/props/update', $entity);
        $this->table->flushValue($entity);
        return true;
    }

    /**
     * Удаляет ряд из таблицы
     *
     * @param AbstractEntity $entity
     *
     * @return bool
     */
    public function delete(AbstractEntity $entity): bool
    {
        $where = [];
        foreach ($this->table->getColumns() as $column) {
            if (!$column->isPrimary()) {
                continue;
            }

            $value = $column->getValue($entity);
            $name  = $column->getName();
            $where[$name] = $value;
            break;
        }

        if (!$where) {
            return false;
        }

        $result = $this->db->delete($this->table->getName(), $where);
        if ($result === false) {
            return false;
        }

        return true;
    }

    /**
     * Записывает переданные данные, при необходимости создавая новый ряд в таблице.
     *
     * @param array $data
     *
     * @return AbstractEntity
     */
    public function saveData(array $data): AbstractEntity
    {
        $primaryKey = $this->table->getPrimaryKey();
        try {
            $entity = $this->find($data[$primaryKey], false);
            $method = 'update';
        } catch (NotFoundException $e) {
            /** @var AbstractEntity $entity */
            $entity = new $this->entityClass();
            $method = 'create';
        }

        $this->{$method}($entity->fromArray($data));
        return $entity;
    }

    // Другое

    /**
     * Получает конструктор sql запроса
     *
     * @param array $criteria
     *
     * @return QueryBuilder
     */
    public function getQueryBuilder(array $criteria = []): QueryBuilder
    {
        $alias      = $this->getAlias();
        $primaryKey = $this->table->getPrimaryKey();

        $query = (new QueryBuilder())
            ->addSelect("$alias.*")
            ->addFrom($this->table->getName(), $alias)
            ->addGroupBy("$alias.$primaryKey");

        $this->applyFilter($query, $criteria);

        return $query;
    }

    /**
     * Добавляет фильтрацию по наличию колонки
     *
     * @param QueryBuilder $query
     * @param array $criteria
     */
    public function applyFilter(QueryBuilder $query, array $criteria): void
    {
        foreach ($this->prepareCriteria($criteria) as $name => $value) {
           $this->setCriterion($query, $name, $value);
        }
    }

    /**
     * Добавляет сортировки
     *
     * @param QueryBuilder $query
     * @param array $orderBy
     * @param array $criteria
     */
    public function applySorts(QueryBuilder $query, array $orderBy, array $criteria = []): void
    {
        foreach ($orderBy as $field => $direction) {
            $this->applySort($query, $field, $direction, $criteria);
        }
    }

    /**
     * Добавляет сортировку по наличию колонки
     *
     * @param QueryBuilder $query
     * @param string $field
     * @param string $direction
     * @param array $criteria
     */
    public function applySort(QueryBuilder $query, string $field, string $direction = 'ASC', array $criteria = []): void
    {
        $columnsName = array_map(fn (Column $column) => $column->getName(), $this->table->getColumns());
        in_array($field, $columnsName) && $query->addOrderBy($field, $direction);
    }

    /**
     * Выполняет кастомный запрос
     *
     * @param QueryBuilder $query
     *
     * @return AbstractEntity[]
     */
    public function query(QueryBuilder $query): array
    {
        //RequestManager::startTimer("props query $this->entityNameLog");
        $this->db->lastError = '';

        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $data = $this->db
            ->prepare($query, $args)
            ->fetchAll();

        if (!$data && $this->db->lastError) {
            //Logger::alert('props_error query', $this->db->last_error, true);
        }

        //RequestManager::stopTimer();
        return $data ?: [];
    }

    public function queryRow(QueryBuilder $query): ?array
    {
        //RequestManager::startTimer("props query_row $this->entityNameLog");

        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $item = $this->db->prepare($query, $args)->fetch();

        //RequestManager::stopTimer();
        return $item ?: null;
    }

    public function queryTotal(QueryBuilder $query): int
    {
        //RequestManager::startTimer("props query_total $this->entityNameLog");
        $alias      = $this->getAlias();
        $primaryKey = $this->table->getPrimaryKey();

        $query
            ->removeSelect()
            ->removeGroupBy()
            ->removeOrderBy()
            ->removeLimit()
            ->addSelect("COUNT($alias.$primaryKey)");

        $args  = $query->getArguments();
        $query = $query->getQueryString();

        $total = $this->db->get_var($args ? $this->db->prepare($query, $args) : $query) ?: 0;

        //RequestManager::stopTimer();
        return $total;
    }

    /**
     * Получает последнюю ошибку
     *
     * @return string
     */
    final public function getLastError(): string
    {
        return $this->db->last_error;
    }

    /**
     * Добавляет в конструктор условия и аргументы для подготовки запроса
     *
     * @param QueryBuilder $query
     * @param string $name
     * @param string|string[]|int|int[] $value
     * @param bool $negative
     */
    final public function setCriterion(QueryBuilder $query, string $name, $value, bool $negative = false): void
    {
        //if (strpos($name, '.') !== false) {
           // $column = $name;
       // } else {
            $alias  = $this->getAlias();
            $column = "$alias.$name";
        //}

        // Добавление условия для нескольких значений
        if (is_array($value)) {
            $operator = $negative ? 'NOT IN' : 'IN';

            $whereIn = [];
            foreach (array_unique($value) as $criterionValue) {
                if (is_string($criterionValue)) {
                    $whereIn[] = '?';
                    //$whereIn[] = '%s';
                    $query->setArgument($criterionValue);
                } elseif (is_numeric($criterionValue)) {
                    $whereIn[] = '?';
                    //$whereIn[] = '%d';
                    $query->setArgument($criterionValue);
                } elseif (is_bool($criterionValue)) {
                    $whereIn[] = '?';
                    //$whereIn[] = '%d';
                    $query->setArgument((int)$criterionValue);
                }
            }

            $whereIn = implode(',', $whereIn);
            $query->addWhere("$column $operator ($whereIn)");
            return;
        }

        $operator = $negative ? '!=' : '=';

        // Добавление условия для строкового значения
        if (is_string($value)) {
            //if (strpos($value, '.') !== false) {
             //   $query->addWhere("$column $operator $value");
           // } else {
                //$query->addWhere("$column $operator %s");
                $query->addWhere("$column $operator ?");
                $query->setArgument($value);
           // }
        }
        // Добавление условия для числового значения
        elseif (is_numeric($value)) {
            //$query->addWhere("$column $operator %d");
            $query->addWhere("$column $operator ?");
            $query->setArgument($value);
        }
        // Добавление условия для bool значения
        elseif (is_bool($value)) {
            //$query->addWhere("$column $operator %d");
            $query->addWhere("$column $operator ?");
            $query->setArgument((int)$value);
        }
    }

    final public function getAlias(string $tableName = ''): string
    {
        if (!empty($this->alias) && !$tableName) {
            return $this->alias;
        }

        $words = explode('_', $tableName ?: $this->table->getName());
        $alias = '';
        foreach ($words as $word) {
            if ($word === 'wp') {
                continue;
            }

            $alias .= substr($word, 0, 1);
        }

        !$tableName && $this->alias = $alias;
        return $alias;
    }

    final public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    protected function prepareCriteria(array $oldCriteria, array $customNames = [], array $map = []): array
    {
        !$this->columnNames && $this->columnNames = array_map(fn (Column $column) => $column->getName(), $this->table->getColumns());
        $allowedKeys = array_merge($this->columnNames, $customNames);

        $criteria = [];
        foreach ($oldCriteria as $key => $value) {
            $key = $map[$key] ?? $key;
            in_array($key, $allowedKeys) && $criteria[$key] = $value;
        }

        // Сортируем
        uksort($criteria, fn($a, $b) => array_search($a, $allowedKeys) <=> array_search($b, $allowedKeys));

        return $criteria;
    }

    protected function prepareCollection(array $data, ?QueryBuilder $totalQuery = null): Collection
    {
        $collection = new Collection($this->entityClass);
        $otherData  = $this->getOtherData($data);

        //RequestManager::startTimer("props prepare_collection $this->entityNameLog");
        foreach ($data as $item) {
            foreach ($otherData as $table) { // Добавляем данные из других таблиц
                $refColumnValue = $item[$table['ref_column_name']];
                isset($table['data'][$refColumnValue]) && $item = array_merge($item, $table['data'][$refColumnValue]);
            }

            try {
                $entity = $this->table->newEntityInstance($item);
                $collection[] = $entity;
            } catch (Exception $e) {
                //Logger::error('props_error query reflection', $e->getMessage(), $e);
            }
        }

        //RequestManager::stopTimer();
        $totalQuery && $collection->setTotal($this->queryTotal($totalQuery));
        return $collection;
    }

    protected function prepareItem(array $item): AbstractEntity
    {
        $otherData = $this->getOtherData([$item]);

        //RequestManager::startTimer("props prepare_item $this->entityNameLog");
        foreach ($otherData as $table) { // Добавляем данные из других таблиц
            $refColumnValue = $item[$table['ref_column_name']];
            isset($table['data'][$refColumnValue]) && $item = array_merge($item, $table['data'][$refColumnValue]);
        }

        $entity = $this->table->newEntityInstance($item);

        //RequestManager::stopTimer();

        return $entity;
    }

    protected function getOtherData(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        //RequestManager::startTimer("props get_other_data $this->entityNameLog");
        $otherData  = [];
        $primaryKey = $this->table->getPrimaryKey();

        // Формирование запросов для данных из других таблиц
        foreach ($this->table->getJoinColumns() as $column) {
            $targetTable = $column->getTargetTable();
            $targetAlias = $this->getAlias($targetTable);

            if (!isset($otherData[$targetTable])) {
                $refColumn       = $column->getRefColumn() ?? $primaryKey;
                $refColumnValues = implode(',', array_column($data, $refColumn));

                $otherData[$targetTable] = [
                    'ref_column_name' => $refColumn,
                    'data'            => [],
                    'query'           => (new QueryBuilder())
                        ->addSelect("$targetAlias.{$column->getRefTargetColumn()}", $refColumn)
                        ->addFrom($targetTable, $targetAlias)
                        ->addWhere("$targetAlias.{$column->getRefTargetColumn()} IN ($refColumnValues)")
                ];
            }

            $otherData[$targetTable]['query']->addSelect("$targetAlias.{$column->getTargetColumn()}", $column->getName());
        }

        // Получение данных из других таблиц
        foreach ($otherData as $name => $table) {
            $data = $this->db->get_results($table['query']->getQueryString(), \App\Repository\ARRAY_A);
            $data && $otherData[$name]['data'] = array_column($data, null, $table['ref_column_name']);
        }

        //RequestManager::stopTimer();
        return $otherData;
    }
}