<?php
/**
 * Created by Eddilbert Macharia (edd.cowan@gmail.com)<http://eddmash.com>
 * Date: 11/4/16.
 */
namespace Eddmash\TransposeDataProvider;

use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\data\ActiveDataProvider;
use yii\data\Pagination;
use yii\db\ActiveQuery;
use yii\db\QueryInterface;

/**
 * Transposes data returned by a query.
 *
 * Assuming our query outputs the following  :
 * <pre>
 *
 * student | subject | grade
 * --------------------------
 *  mat    | cre     | 52
 *  mat    | ghc     | 40
 *  mat    | physics | 60
 *  leon   | cre     | 70
 *  leon   | ghc     | 80
 *  leon   | physics | 10
 *
 * </pre>
 *
 * and we need our data to look as below :
 *
 * </pre>
 *
 * student | cre | ghc | physics
 * ------------------------------
 * mat     | 52  | 40  | 60
 * leon    | 70  | 80  | 10
 *
 * </pre>
 *
 * We achive this by doing :
 *
 * <pre>use Eddmash\TransposeDataProvider;
 *
 * $dataProvider = new TransposeDataProvider([
 *  'query'=>$dataQuery,
 *  'groupField'=>'rowid',
 *  'columnsField'=>'columns.column_name',
 *  'valuesField'=>'data',
 *  'extraFields'=>['call_data_id', 'instance.name'],
 *  'pagination'=>[
 *      'pageSize'=>4
 *   ]
 * ]);
 *
 * </pre>
 *
 *
 * By default the transposed output contains on the the columns found at {@see columnsField },
 * to get other columns present on the query add them to {@see extraFields }
 *
 * Class Transpose2DataProvider
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class TransposeDataProvider extends ActiveDataProvider
{
    /**
     * This fields is used group together records in the $this->query into actual understandable rows of records.
     * e.g. student in the example above.
     *
     * @var
     */
    public $groupField;

    /**
     * The column in the columnQuery actually contains the records we need to use as column.
     *
     * @var
     */
    public $columnsField;

    /**
     * The column in the $this->query that actually contains the records we need to use as values for our columns.
     *
     * @var
     */
    public $valuesField;

    /**
     * Other columns found on the $this->query that should be added to the transposed output.
     *
     * For relational fields use the dot notation,  [student.role.name] this will add the role name of each student
     * to the transposed data.
     *
     * @var array
     */
    public $extraFields = [];
    private $_columns;
    private $_rows;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        if (!is_array($this->extraFields)){
            throw new InvalidParamException('The extraFields should be an array');
        }
    }

    /**
     * {@inheritdoc}
     *
     * Incase of pagination, we have to fetch the number of rows required. since we using a query that most of the
     * time will consist of several joins in order to get the data and the columns.
     *
     * we will be using the where clause instead of the tradition offset/limit pair which work on a single table.
     * to paginate we add the where $column between start and end clause on the $query
     */
    protected function prepareModels()
    {
        if (!$this->query instanceof QueryInterface) {
            throw new InvalidConfigException('The "query" property must be an instance of a class that'.
                ' implements the QueryInterface e.g. yii\db\Query or its subclasses.');
        }

        /** @var $query ActiveQuery */
        $query = clone $this->query;
        $query->orderBy($this->groupField);

        if (($pagination = $this->getPagination()) !== false) {
            $pagination->totalCount = $this->getTotalCount();
            $rows = $this->getDistinctRows();

            // only do a between check if we have an upper range to work with.
            $upperRange = $this->getUpperRow($pagination, $rows);

            if ($upperRange):
                $query->where(['between', $this->groupField,
                    $this->getLowerRow($pagination, $rows),
                    $upperRange,
                ]);
            endif;

        }

        if (($sort = $this->getSort()) !== false) {
            $query->addOrderBy($sort->getOrders());
        }

        return $this->_transpose($query->all($this->db));
    }

    /**
     * Gets the row from which to start our data fetch.
     *
     * @param Pagination $pagination
     * @param $rows
     *
     * @return int
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getLowerRow(Pagination $pagination, $rows)
    {
        $offset = $pagination->getOffset();
        if ($offset <= 0):
            return 0;
        endif;

        // the offset is out of range use the last record in the array
        return in_array($offset, $rows) ? $rows[$offset] : end($rows);
    }

    /**
     * Gets the row at which we stop fetching data.
     *
     * @param Pagination $pagination
     * @param $rows
     *
     * @return int
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getUpperRow(Pagination $pagination, $rows)
    {
        if ($pagination->getLimit() <= 0):
            return 0;
        endif;

        $nextRow = $pagination->getLimit() + $pagination->getOffset();

        // array start at zero, meaning the $rows array will start at zero,
        // we adjust for this by reducing the $nextRow by 1
        --$nextRow;

        // the offset is out of range use the last record in the array
        return $nextRow <= count($rows) ? $rows[$nextRow] : end($rows);
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareKeys($models)
    {
        return parent::prepareKeys($models);
    }

    /**
     * In this case we return the number of distinct rows based on the groupField
     * {@inheritdoc}
     */
    protected function prepareTotalCount()
    {
        if (!$this->query instanceof QueryInterface) {
            throw new InvalidConfigException('The "query" property must be an instance of a class that implements the'.
                ' QueryInterface e.g. yii\db\Query or its subclasses.');
        }
        /** @var $query ActiveQuery */
        $query = clone $this->query;

        return (int) $query->select($this->groupField)->distinct()->orderBy($this->groupField)->count('*', $this->db);
    }

    /**
     * Returns all the columns that relate to the data we are handling, this also includes any extra fields
     * that might have been passed.
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getColsNames()
    {
        return array_merge($this->getDistinctColumns(), $this->extraFields);
    }

    /**
     * gets the rows of data that our data holds.
     *
     * Note, this will not be a direct mapping of the rows of data in a table.
     *
     * we use {see @groupField } to determine the rows.
     *
     * @return array|\yii\db\ActiveRecord[]
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getDistinctRows()
    {

        if($this->_rows):
            return $this->_rows;
        endif;

        /** @var $query ActiveQuery */
        $query = clone $this->query;

        $rows = $query->select($this->groupField)->distinct()->asArray()->orderBy($this->groupField)->all($this->db);

        array_walk($rows, function (&$value, $key) {
            $value = reset($value);
        });

        $this->_rows = $rows;

        return $this->_rows;
    }

    /**
     * gets the columns that will be used in our final transposed data.
     *
     * we use {see @columnsField } to determine the rows.
     *
     * @return array|\yii\db\ActiveRecord[]
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getDistinctColumns()
    {
        if($this->_columns):
            return $this->_columns;
        endif;

        /** @var $query ActiveQuery */
        $query = clone $this->query;

        $rows = $query->select($this->columnsField)->distinct()->orderBy($this->columnsField)->asArray()->all($this->db);

        array_walk($rows, function (&$value, $key) {

            $value = reset($value);
        });

        $this->_columns = $rows;

        return $this->_columns;
    }

    /**
     * {@inheritdoc}
     */
    public function setSort($value)
    {
        parent::setSort($value);
    }

    /**
     * This transposes the models passed in it desired output.
     *
     * The desired output is dictated by :
     * see @groupField
     * see @valuesField
     * see @columnsField
     *
     * @param $models
     *
     * @return array
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function _transpose($models)
    {

        $dataRows = [];

        $columns = $this->getDistinctColumns();
        $extraColumns = $this->extraFields;
        foreach ($models as $index => $model) :
            if (is_array($this->groupField)):
                $rowID = $model->{$this->groupField[0]}.''.$model->{$this->groupField[1]};
            else:
                $rowID = $model->{$this->groupField};
            endif;

            foreach ($columns as $column) :
                if($this->getColumnValue($model) !== $column):
                    continue;
                endif;
                $dataRows[$rowID][$column] = $model->{$this->valuesField};
            endforeach;

            foreach ($extraColumns as $eColumn => $label) :
                if(is_numeric($eColumn)):
                    $eColumn = $label;
                endif;
                $dataRows[$rowID][$label] = $this->getColumnValue($model, $eColumn);
            endforeach;

        endforeach;

        ksort($dataRows);

        return $dataRows;
    }

    /**
     * Gets a model and column name and returns the value of the column on the model.
     *
     * @param $model
     * @param null $column
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getColumnValue($model, $column = null)
    {
        $column = ($column === null) ? $this->columnsField : $column;

        // handle relational columns
        if(strpos($column, '.')):
            $parentModel = $model->{substr($column, 0, strpos($column, '.'))};
            $childCol = substr($column, strpos($column, '.') + 1);
            $value = $this->getColumnValue($parentModel, $childCol);
        else:
            $value = $model->{$column};
        endif;

        return $value;
    }

}
