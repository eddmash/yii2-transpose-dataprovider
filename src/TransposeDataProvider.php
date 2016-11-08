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
 * <pre>
 *
 * student | cre | ghc | physics
 * ------------------------------
 *  mat    | 52  | 40  | 60
 *  leon   | 70  | 80  | 10
 *
 * </pre>
 *
 * We achive this by doing :
 *
 * ``` php
 *
 * use Eddmash\TransposeDataProvider;
 *
 * $dataProvider = new TransposeDataProvider([
 *      'query' => $query,
 *      'columnsField' => 'question_id',
 *      'groupField' => 'pollid',
 *      'valuesField' => 'response',
 *      'pagination' => [
 *          'pagesize' => $pageSize // in case you want a default pagesize
 *      ]
 * ]);
 *
 * ```
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

    /**
     *  The column to be used to get the labels for the column, use this incase the field used for $columnsField does
     * not consist of user friendly labels.
     * @var
     */
    public $labelsField;

    /**
     * cache for columns.
     *
     * @var
     */
    private $_columns;

    /**
     * cache for rows.
     *
     * @var
     */
    private $_rows;

    /**
     * Initializes the DB connection component.
     * This method will initialize the [[db]] property to make sure it refers to a valid DB connection.
     *
     * @throws InvalidConfigException if [[db]] is invalid
     */
    public function init()
    {
        parent::init();

        if (!is_array($this->extraFields)) {
            throw new InvalidParamException('The extraFields should be an array');
        }
    }

    /**
     * Prepares the data models that will be made available in the current page.
     *
     * @return array the available data models
     *
     * @throws InvalidConfigException
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function prepareModels()
    {
        if (!$this->query instanceof QueryInterface) {
            throw new InvalidConfigException('The "query" property must be an instance of a class that' .
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

        return $this->transpose($query->all($this->db));
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
     * In this case we return the number of distinct rows based on the groupField
     * {@inheritdoc}
     */
    protected function prepareTotalCount()
    {
        if (!$this->query instanceof QueryInterface) {
            throw new InvalidConfigException('The "query" property must be an instance of a class that implements the' .
                ' QueryInterface e.g. yii\db\Query or its subclasses.');
        }
        /** @var $query ActiveQuery */
        $query = clone $this->query;

        return (int)$query->select($this->groupField)->distinct()->orderBy($this->groupField)->count('*', $this->db);
    }

    /**
     * Returns all the columns that relate to the data we are handling, this also includes any extra fields
     * that might have been passed.
     *
     * @return mixed
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getDataColumns()
    {
        $columns = $this->getQueryColumns();
        if (count($columns) === 0):
            return $columns;
        endif;
        return array_merge( $this->extraFields,$columns);
    }

    /**
     * Returns columns found in the query.
     * @return array
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getQueryColumns()
    {
        $colsMeta = $this->getDistinctColumns();

        $colNames = [];
        foreach ($colsMeta as $colMeta) :
            $colNames[] = end($colMeta);
        endforeach;

        return $colNames;
    }

    /**
     * Get a column in the query based on a columnLabel provided.
     * @param $columnLabel
     * @return null
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getColumn($columnLabel)
    {
        $column = null;
        $cols = $this->getDistinctColumns();
        foreach ($cols as $col) :

            if (in_array($columnLabel, $col)):
                $column = reset($col);
                break;
            endif;
        endforeach;

        return $column;
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
    protected function getDistinctRows()
    {

        if ($this->_rows):
            return $this->_rows;
        endif;

        /** @var $query ActiveQuery */
        $query = clone $this->query;

        $rows = $query->select($this->groupField)->distinct()->asArray()->orderBy($this->groupField)->all($this->db);

        array_walk($rows, function (&$value, $key) {
            $value = $this->getCleanColumn(reset($value));
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
    protected function getDistinctColumns()
    {
        if ($this->_columns):
            return $this->_columns;
        endif;

        /** @var $query ActiveQuery */
        $query = clone $this->query;
        $query->distinct()->orderBy($this->columnsField)->asArray();


        if ($this->labelsField):
            $rows = $query->select([$this->columnsField, $this->labelsField])->all($this->db);
            array_walk($rows, function (&$value, $key) {
                $val = $this->getColumnValue($value, $this->stripRelation($this->columnsField));

                $label = $this->getColumnValue($value, $this->labelsField);

                $value = [$val, self::conformColumn($label)];
            });
        else:
            $rows = $query->select([$this->columnsField])->all($this->db);

            array_walk($rows, function (&$value, $key) {
                $val = $this->getColumnValue($value, $this->stripRelation($this->columnsField));
                $value = [$val, $val];
            });

        endif;

        $this->_columns = $rows;

        return $this->_columns;
    }

    /**
     * Strip the column name to the last in the relation e.g. user.role this returns role.
     *
     * @internal
     * @param $column
     * @return mixed
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function stripRelation($column)
    {
        if ($pos = strripos($column, '.')):

            $column = substr($column, $pos + 1);
        endif;
        return $column;
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
    protected function transpose($models)
    {
        $dataRows = [];

        $columns = $this->getDistinctColumns();
        $extraColumns = $this->extraFields;

        foreach ($models as $index => $model) :

            if (is_array($this->groupField)):
                $rowID = $model->{$this->groupField[0]} . '' . $model->{$this->groupField[1]};
            else:
                $rowID = $model->{$this->groupField};
            endif;

            foreach ($columns as $column) :
                $col = reset($column);

                // get the value of the columnField in the model, if it matches the current column
                // add it to our data rows
                if ($this->getColumnValue($model, $this->columnsField) !== $col):
                    continue;
                endif;

                $dataRows[$rowID][end($column)] = $model->{$this->valuesField};

                // add value of any other extra columns.that have been requested
                foreach ($extraColumns as $eColumn => $label) :

                    if (is_numeric($eColumn)):
                        $eColumn = $label;
                    endif;

                    $dataRows[$rowID][$label] = $this->getColumnValue($model, $eColumn);
                endforeach;
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
    protected function getColumnValue($model, $column = null)
    {
        // handle relational columns
        if (strpos($column, '.')):
            $parentModel = $model[substr($column, 0, strpos($column, '.'))];
            $childCol = substr($column, strpos($column, '.') + 1);
            $value = $this->getColumnValue($parentModel, $childCol);
        else:
            $value = $model[$column];
        endif;

        return $value;
    }

    /**
     * Creates the field label.
     * @param $column
     * @return mixed
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function getCleanColumn($column)
    {
        if (!self::isValidVariableName($column)):
            $column = self::conformColumn($column);
        endif;
        return $column;
    }

    /**
     * Check if a string can be used as a php variable/ class attribute.
     * @param $name
     * @return mixed
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected static function isValidVariableName($name)
    {
        return 1 === preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name);
    }

    /**
     * ensures column to be a valid column name.
     * @param $name
     * @return mixed
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected static function conformColumn($name)
    {
        return preg_replace('/[^\w]/', "_", $name);
    }
}
