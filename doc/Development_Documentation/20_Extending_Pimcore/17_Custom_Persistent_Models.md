# Custom Persistent Models

## When to use Custom Models

Pimcore objects are very flexible but shouldn't be used to store all types of data. For example, it doesn't make sense
to implement a rating-, comments- or a complex blog system on top of the Pimcore objects. Sometimes people also
try to implement quite interesting things just to get a unique object key or to build n to n relationships. This sometimes produces
really ugly code with a lot of overhead which could be very slow, hard to refactor, and you will have a lot of pain if you have to merge multiple
installations.

Pimcore provides 2 possible ways of working with custom entities namely Doctrine ORM and Pimcore Dao.

## Option 1: Use Doctrine ORM
Pimcore comes already with the Doctrine bundle, so you can easily create your own entities.
Please check <https://symfony.com/doc/current/doctrine.html> for more details.

## Option 2: Working with Pimcore Data Access Objects (Dao)

This example will show you how you can save a custom model in the database.

## Database
As a first step, create the database structure for the model, for this example I'll use a very easy model called vote. it just
has an id, a username (just a string) and a score. If you want to write a model for a bundle you have to create the
table(s) during the installation.

```sql
CREATE TABLE `votes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) DEFAULT NULL,
  `score` int(5) DEFAULT NULL,
  PRIMARY KEY (`id`)
) DEFAULT CHARSET=utf8md4
```

Please keep in mind that this is just a generic example, you also could create other and more complex models.

## Model
The next step is to implement the model. To make it easy the model is stored into the `src/` library. You also could place
it into a bundle library.

```php
# src/Model/Vote.php
<?php

namespace App\Model;

use Pimcore\Model\AbstractModel;
use Pimcore\Model\Exception\NotFoundException;

class Vote extends AbstractModel {

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    public $username;

    /**
     * @var int
     */
    public $score;

    /**
     * get score by id
     *
     * @param int $id
     * @return null|self
     */
    public static function getById($id) {
        try {
            $obj = new self;
            $obj->getDao()->getById($id);
            return $obj;
        }
        catch (NotFoundException $ex) {
            \Pimcore\Logger::warn("Vote with id $id not found");
        }

        return null;
    }

    /**
     * @param int $score
     */
    public function setScore($score) {
        $this->score = $score;
    }

    /**
     * @return int
     */
    public function getScore() {
        return $this->score;
    }

    /**
     * @param string $username
     */
    public function setUsername($username) {
        $this->username = $username;
    }

    /**
     * @return string
     */
    public function getUsername() {
        return $this->username;
    }

    /**
     * @param int $id
     */
    public function setId($id) {
        $this->id = $id;
    }

    /**
     * @return int
     */
    public function getId() {
        return $this->id;
    }
}
```

For every field in the database we need a corresponding property and a Setter/Getter. This is not really necessary, it
just depends on your DAO, just read on and have a look at the save method in the DAO.

The `save` and `getById` methods just call the corresponding DAO methods.

The `getDao` method looks for the nearest DAO. It just appends Dao to the class name, if the class exists you are ready
to use the DAO. If the class doesn't exist, it just continue searching using the next namespace.

Small example: `App\Model\Vote` looks for `App\Model\Vote\Dao`, `App\Model\Dao`, `App\Dao`.


## DAO
Now we are ready to implement the Dao:

```php
#src/Model/Vote/Dao.php
<?php

namespace App\Model\Vote;

use Pimcore\Model\Dao\AbstractDao;
use Pimcore\Model\Exception\NotFoundException;

class Dao extends AbstractDao {

    protected $tableName = 'votes';

    /**
     * get vote by id
     *
     * @param int|null $id
     * @throws \Exception
     */
    public function getById($id = null) {

        if ($id != null)  {
            $this->model->setId($id);
        }

        $data = $this->db->fetchAssociative('SELECT * FROM '.$this->tableName.' WHERE id = ?', [$this->model->getId()]);

        if(!$data["id"]) {
            throw new NotFoundException("Object with the ID " . $this->model->getId() . " doesn't exists");
        }

        $this->assignVariablesToModel($data);
    }

    /**
     * save vote
     */
    public function save() {
        $vars = get_object_vars($this->model);

        $buffer = [];

        $validColumns = $this->getValidTableColumns($this->tableName);

        if(count($vars))
            foreach ($vars as $k => $v) {

                if(!in_array($k, $validColumns))
                    continue;

                $getter = "get" . ucfirst($k);

                if(!is_callable([$this->model, $getter]))
                    continue;

                $value = $this->model->$getter();

                if(is_bool($value))
                    $value = (int)$value;

                $buffer[$k] = $value;
            }

        if($this->model->getId() !== null) {
            $this->db->update($this->tableName, $buffer, ["id" => $this->model->getId()]);
            return;
        }

        $this->db->insert($this->tableName, $buffer);
        $this->model->setId($this->db->lastInsertId());
    }

    /**
     * delete vote
     */
    public function delete() {
        $this->db->delete($this->tableName, ["id" => $this->model->getId()]);
    }

}
```

Please keep in mind that this is just a very easy example DAO. Of course, you can do much more complex stuff like implementing joins,
save dependencies or whatever you want.


## Using the Model

Now you can use your Model in your service-layer.

```php
$vote = new \App\Model\Vote();
$vote->setScore(3);
$vote->setUsername('foobar!'.mt_rand(1, 999));
$vote->save();
```


## Listing
If you need to query the data using a Pimcore entity list, you also need to implement a `Listing` and `Listing\Dao` class:

```php
#src/Model/Vote/Listing.php

<?php

namespace App\Model\Vote;

use Pimcore\Model;
use Pimcore\Model\Paginator\PaginateListingInterface;

class Listing extends Model\Listing\AbstractListing implements PaginateListingInterface
{
    /**
     * List of Votes.
     *
     * @var array
     */
    public $data = null;

    /**
     * @var string
     */
    public $locale;

    /**
     * get total count.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->getTotalCount();
    }

    /**
     * get all items.
     *
     * @param int $offset
     * @param int $itemCountPerPage
     *
     * @return mixed
     */
    public function getItems($offset, $itemCountPerPage)
    {
        $this->setOffset($offset);
        $this->setLimit($itemCountPerPage);

        return $this->load();
    }

    /**
     * Get Paginator Adapter.
     *
     * @return $this
     */
    public function getPaginatorAdapter()
    {
        return $this;
    }

    /**
     * Set Locale.
     *
     * @param string $locale
     */
    public function setLocale($locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Get Locale.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Methods for Iterator.
     */

    /**
     * Rewind.
     */
    public function rewind(): void
    {
        $this->getData();
        reset($this->data);
    }

    /**
     * current.
     *
     * @return mixed
     */
    public function current(): mixed
    {
        $this->getData();

        return current($this->data);
    }

    /**
     * key.
     *
     * @return mixed
     */
    public function key(): mixed
    {
        $this->getData();

        return key($this->data);
    }

    /**
     * next.
     *
     * @return void
     */
    public function next(): void
    {
        $this->getData();
        next($this->data);
    }

    /**
     * valid.
     *
     * @return bool
     */
    public function valid(): bool
    {
        $this->getData();

        return $this->current() !== false;
    }
}
```


## Listing\Dao

```php
#src/Model/Vote/Listing/Dao.php

<?php

namespace App\Model\Vote\Listing;

use Pimcore\Model\Listing;
use App\Model;
use Doctrine\DBAL\Query\QueryBuilder as DoctrineQueryBuilder;
use Pimcore\Model\Listing\Dao\QueryBuilderHelperTrait;

class Dao extends Listing\Dao\AbstractDao
{
    use QueryBuilderHelperTrait;

    /**
     * @var string
     */
    protected $tableName = 'votes';

    /**
     * Get tableName, either for localized or non-localized data.
     *
     * @return string
     *
     * @throws \Exception
     */
    protected function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * @return DoctrineQueryBuilder
     */
    public function getQueryBuilder()
    {
        $queryBuilder = $this->db->createQueryBuilder();
        $field = $this->getTableName().'.id';
        $queryBuilder->select([sprintf('SQL_CALC_FOUND_ROWS %s as id', $field)]);
        $queryBuilder->from($this->getTableName());

        $this->applyListingParametersToQueryBuilder($queryBuilder);

        return $queryBuilder;
    }

    /**
     * Loads objects from the database.
     *
     * @return Model\Vote[]
     */
    public function load()
    {
        // load id's
        $list = $this->loadIdList();

        $objects = array();
        foreach ($list as $o_id) {
            if ($object = Model\Vote::getById($o_id)) {
                $objects[] = $object;
            }
        }

        $this->model->setData($objects);

        return $objects;
    }

    /**
     * Loads a list for the specicifies parameters, returns an array of ids.
     *
     * @return int[]
     * @throws \Exception
     */
    public function loadIdList()
    {
        $query = $this->getQueryBuilder();
        $objectIds = $this->db->fetchFirstColumn((string) $query, $this->model->getConditionVariables(), $this->model->getConditionVariableTypes());
        $this->totalCount = (int) $this->db->fetchOne('SELECT FOUND_ROWS()');

        return array_map('intval', $objectIds);
    }

    /**
     * Get Count.
     *
     * @return int
     *
     * @throws \Exception
     */
    public function getCount()
    {
        if ($this->model->isLoaded()) {
            return count($this->model->getData());
        } else {
            $idList = $this->loadIdList();

            return count($idList);
        }
    }

    /**
     * Get Total Count.
     *
     * @return int
     *
     * @throws \Exception
     */
    public function getTotalCount()
    {
        $queryBuilder = $this->getQueryBuilder();
        $this->prepareQueryBuilderForTotalCount($queryBuilder, $this->getTableName() . '.id');

        $totalCount = $this->db->fetchOne((string) $queryBuilder, $this->model->getConditionVariables(), $this->model->getConditionVariableTypes());

        return (int) $totalCount;
    }
}
```


## Using the Listing
Now you can use your Listing in your service-layer.

```php
$list = \App\Model\Vote::getList();
$list->setCondition("score > ?", array(1));
$votes = $list->load();
```
