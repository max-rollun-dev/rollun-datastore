<?php
/**
 * @copyright Copyright © 2014 Rollun LC (http://rollun.com/)
 * @license LICENSE.md New BSD License
 */

namespace rollun\datastore\DataStore;

use rollun\datastore\TableGateway\DbSql\MultiInsertSql;
use rollun\datastore\TableGateway\SqlQueryBuilder;
use Xiag\Rql\Parser\Node\Query\ArrayOperator\InNode;
use Xiag\Rql\Parser\Query;
use Zend\Db\Adapter\ParameterContainer;
use Zend\Db\Sql\Select;
use Zend\Db\TableGateway\TableGateway;

/**
 * Datastore as db table
 *
 * Class DbTable
 * @package rollun\datastore\DataStore
 */
class DbTable extends DataStoreAbstract
{
    /**
     * @var TableGateway
     */
    protected $dbTable;

    /**
     * @var SqlQueryBuilder
     */
    protected $sqlQueryBuilder;

    /**
     * DbTable constructor.
     * @param TableGateway $dbTable
     * @param SqlQueryBuilder $sqlQueryBuilder
     */
    public function __construct(TableGateway $dbTable, SqlQueryBuilder $sqlQueryBuilder = null)
    {
        $this->dbTable = $dbTable;

        if ($this->sqlQueryBuilder === null) {
            $this->sqlQueryBuilder = new SqlQueryBuilder($this->dbTable->getAdapter(), $this->dbTable->table);
        } else {
            $this->sqlQueryBuilder = $sqlQueryBuilder;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create($itemData, $rewriteIfExist = false)
    {
        if ($rewriteIfExist) {
            trigger_error("Option 'rewriteIfExist' is no more use", E_USER_DEPRECATED);
        }

        $adapter = $this->dbTable->getAdapter();
        $adapter->getDriver()->getConnection()->beginTransaction();

        try {
            $insertedItem = $this->insertItem($itemData, $rewriteIfExist);
            $adapter->getDriver()->getConnection()->commit();
        } catch (\Throwable $e) {
            $adapter->getDriver()->getConnection()->rollback();
            throw new DataStoreException("Can't insert item. {$e->getMessage()}", 0, $e);
        }

        return $insertedItem;
    }

    /**
     * @param $itemData
     * @param bool $rewriteIfExist
     * @return array|mixed|null
     */
    protected function insertItem($itemData, $rewriteIfExist = false)
    {
        if ($rewriteIfExist && isset($itemData[$this->getIdentifier()])) {
            $this->delete($itemData[$this->getIdentifier()]);
        }

        $this->dbTable->insert($itemData);

        if (isset($itemData[$this->getIdentifier()])) {
            $insertedItem = $this->read($itemData[$this->getIdentifier()]);
        } else {
            trigger_error("Getting last id using db is deprecate", E_USER_DEPRECATED);
            $id = $this->dbTable->getLastInsertValue();
            $insertedItem = $this->read($id);
        }

        return $insertedItem;
    }

    /**
     * {@inheritdoc}
     */
    public function update($itemData, $createIfAbsent = false)
    {
        if ($createIfAbsent) {
            trigger_error("createIfAbsent is deprecated.", E_DEPRECATED);
        }

        if (!isset($itemData[$this->getIdentifier()])) {
            throw new DataStoreException('Item must has primary key');
        }

        $adapter = $this->dbTable->getAdapter();
        $adapter->getDriver()->getConnection()->beginTransaction();

        try {
            $result = $this->updateItem($itemData, $createIfAbsent);
            $adapter->getDriver()->getConnection()->commit();
        } catch (\Throwable $e) {
            $adapter->getDriver()->getConnection()->rollback();
            throw new DataStoreException("Can't update item. {$e->getMessage()}", 0, $e);
        }

        return $result;
    }

    /**
     * @param array $identifiers
     * @return \Zend\Db\Adapter\Driver\ResultInterface
     */
    private function selectForUpdate(array $identifiers)
    {
        $adapter = $this->dbTable->getAdapter();
        $valTemplate = '';

        foreach ($identifiers as $identifier) {
            $valTemplate .= '?,';
        }

        $valTemplate = trim($valTemplate, ",");
        $sqlString = "SELECT " . Select::SQL_STAR;
        $sqlString .= " FROM {$adapter->getPlatform()->quoteIdentifier($this->dbTable->getTable())}";
        $sqlString .= " WHERE {$adapter->getPlatform()->quoteIdentifier($this->getIdentifier())} IN ($valTemplate)";
        $sqlString .= " FOR UPDATE";

        $statement = $adapter->getDriver()->createStatement($sqlString);
        $statement->setParameterContainer(new ParameterContainer($identifiers));

        return $statement->execute();
    }

    /**
     * @param $itemData
     * @param bool $createIfAbsent
     * @return array|mixed|null
     * @throws DataStoreException
     */
    protected function updateItem($itemData, $createIfAbsent = false)
    {
        $identifier = $this->getIdentifier();
        $id = $itemData[$identifier];
        $this->checkIdentifierType($id);

        // Is row with this index exist ?
        $result = $this->selectForUpdate([$id]);
        $isExist = $result->count();

        if (!$isExist && $createIfAbsent) {
            $this->dbTable->insert($itemData);
        } elseif ($isExist) {
            unset($itemData[$identifier]);
            $this->dbTable->update($itemData, [$identifier => $id]);
        } else {
            throw new DataStoreException("Can't update item with id = $id");
        }

        return $result = $this->read($id);
    }

    /**
     * {@inheritdoc}
     */
    public function query(Query $query)
    {
        $adapter = $this->dbTable->getAdapter();
        $sqlString = $this->sqlQueryBuilder->buildSql($query);

        try {
            $statement = $adapter->getDriver()->createStatement($sqlString);
            $resultSet = $statement->execute();
        } catch (\PDOException $exception) {
            throw new DataStoreException(
                "Error by execute '$sqlString' query to {$this->getDbTable()->getTable()}.",
                500,
                $exception
            );
        }

        $result = [];

        foreach ($resultSet as $itemData) {
            $result[] = $itemData;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id)
    {
        $identifier = $this->getIdentifier();
        $this->checkIdentifierType($id);
        $element = $this->read($id);

        if ($element) {
            $this->dbTable->delete([$identifier => $id]);
        }

        return $element;
    }

    /**
     * {@inheritdoc}
     */
    public function read($id)
    {
        $this->checkIdentifierType($id);
        $identifier = $this->getIdentifier();
        $rowSet = $this->dbTable->select([$identifier => $id]);
        $row = $rowSet->current();

        if (isset($row)) {
            return $row->getArrayCopy();
        } else {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAll()
    {
        $where = '1=1';
        $deletedItemsCount = $this->dbTable->delete($where);

        return $deletedItemsCount;
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        $adapter = $this->dbTable->getAdapter();

        $sql = "SELECT COUNT(*) AS count FROM " . $adapter->getPlatform()->quoteIdentifier($this->dbTable->getTable());

        $statement = $adapter->getDriver()->createStatement($sql);
        $result = $statement->execute();

        return $result->current()['count'];
    }

    /**
     * @param array $itemsData
     * @return array|array[]
     * @throws DataStoreException
     */
    public function multiCreate(array $itemsData)
    {
        $multiInsertTableGw = $this->createMultiInsertTableGw();
        $multiInsertTableGw->getAdapter()->getDriver()->getConnection()->beginTransaction();

        try {
            $identifiers = array_map(
                function ($item) {
                    return $item[$this->getIdentifier()];
                },
                $itemsData
            );

            $multiInsertTableGw->insert($itemsData);
            $query = new Query();
            $query->setQuery(new InNode($this->getIdentifier(), $identifiers));
            $insertedItems = $this->query($query);
        } catch (\Throwable $throwable) {
            $multiInsertTableGw->getAdapter()->getDriver()->getConnection()->rollback();

            throw new DataStoreException(
                "Exception by multi create to table {$this->dbTable->table}.",
                500,
                $throwable
            );
        }

        return $insertedItems;
    }

    /**
     * @return TableGateway
     */
    public function getDbTable()
    {
        return $this->dbTable;
    }

    /**
     * {@inheritdoc}
     */
    protected function getKeys()
    {
        $identifier = $this->getIdentifier();
        $select = $this->dbTable->getSql()->select();
        $select->columns([$identifier]);

        $resultSet = $this->dbTable->selectWith($select);
        $keys = [];

        foreach ($resultSet as $key => $result) {
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @return TableGateway
     */
    private function createMultiInsertTableGw()
    {
        $multiInsertTableGw = new TableGateway(
            $this->dbTable->getTable(),
            $this->dbTable->getAdapter(),
            $this->dbTable->getFeatureSet(),
            $this->dbTable->getResultSetPrototype(),
            new MultiInsertSql($this->dbTable->getAdapter(), $this->dbTable->getTable())
        );

        return $multiInsertTableGw;
    }
}
