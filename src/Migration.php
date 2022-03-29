<?php

/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2019 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace dmstr\rbacMigration;


use Yii;
use yii\base\ErrorException;
use yii\base\InvalidArgumentException;
use yii\db\Exception;
use yii\db\Migration as BaseMigration;
use yii\helpers\ArrayHelper;
use yii\rbac\Item;
use yii\rbac\ManagerInterface;
use yii\rbac\Permission;
use yii\rbac\Role;
use yii\rbac\Rule;

/**
 * Just extend your migration class from this one. => mxxxxxx_xxxxxx_migration_namee extends project\components\RbacMigration
 * Generates roles and permissions recursively when defined in following pattern:
 *
 * use yii\rbac\Item;
 *
 * public $privileges = [
 *      [
 *          '_exists' => true,
 *          'name' => 'Role_0',
 *          'type' => Item::TYPE_ROLE,
 *          'children' => [
 *              [
 *                  'name' => 'permission_0',
 *                  'type' => Item::TYPE_PERMISSION
 *              ],
 *              [
 *                  '_force' => true,
 *                  'name' => 'permission_1',
 *                  'type' => Item::TYPE_PERMISSION,
 *                  'rule' => [
 *                      'name' => 'Rule0',
 *                      'class' => some\namespaced\Rule::class
 *                  ]
 *              ],
 *              [
 *                  'name' => 'Role_1',
 *                  'type' => Item::TYPE_PERMISSION,
 *                  'children' => [
 *                      [
 *                          'name' => 'permission_2',
 *                          'type' => Item::TYPE_PERMISSION
 *                      ]
 *                  ]
 *              ]
 *          ]
 *      ],
 *      [
 *          'name' => 'Role_2',
 *          'type' => Item::TYPE_ROLE
 *      ]
 * ];
 *
 *
 *
 * @package project\components
 * @author Elias Luhr <e.luhr@herzogkommunikation.de>
 *
 * @property array $privileges
 * @property ManagerInterface $authManager
 */
class Migration extends BaseMigration
{

    /**
     * deprecated flags before ensure was implemented
     */
    const EXISTS = '_exists';
    const FORCE = '_force';

    /**
     * ensure value: item MUST already exists
     */
    const MUST_EXIST = 'must_exist';

    /**
     * ensure value: item will be created if not exists
     */
    const PRESENT = 'present';

    /**
     * ensure value: item will be removed
     */
    const ABSENT = 'absent';

    /**
     * ensure value: item may not exists yet
     */
    const NEW = 'new';

    /**
     * array of privilege definitions that should be handled by this migration
     *
     * @var array
     */
    public $privileges = [];

    /**
     * authManager instance that should be used.
     * if not defined Yii::$app->authManager will be used
     *
     * @var ManagerInterface|null
     */
    public $authManager;

    /**
     * flag struct with default values
     * This struct ensures all required flags are set
     *
     * can be overridden via defaultFlags and/or item params
     *
     * @see setItemFlags()
     *
     * @var array
     */
    protected $_defaultFlagsStruct = [
        'name'        => null,
        'ensure'      => self::NEW,
        'replace'     => false,
        'rule'        => [],
        'description' => null,
        'type'        => Item::TYPE_PERMISSION,
    ];

    protected $_requiredItemFlags  = [
        'name',
    ];

    /**
     * can be used to define defaults for all items in this migration
     * @see $_defaultFlagsStruct
     *
     * @var array
     */
    public $defaultFlags = [];

    /**
     * if true migrate/down calls will remove defined items
     * ! handle with care !
     *
     * @var bool
     */
    public $removeOnMigrateDown = false;

    /**
     * @return void
     */
    public function init()
    {
        $this->authManager = $this->authManager ?? Yii::$app->authManager;
        if (!$this->authManager instanceof ManagerInterface) {
            throw new InvalidArgumentException('authManager must be an instance of ManagerInterface');
        }
        parent::init();
    }

    /**
     * @inherit
     * @throws \yii\base\Exception
     * @throws ErrorException
     */
    public function safeUp()
    {
        try {
            $this->generatePrivileges($this->privileges);
        } catch (\Exception $e) {
            echo 'Exception: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n";
            return false;
        }
        return true;

    }


    /**
     * @inherit
     */
    public function safeDown()
    {
        if ($this->removeOnMigrateDown) {
            $this->removePrivileges($this->privileges);
        }
        else {
            echo $this::className() . " cannot be reverted.\n";
            return false;
        }
    }

    /**
     * Generate privileges recursively
     * used in self::safeUp()
     *
     * @param array $privileges
     * @throws \yii\base\Exception
     * @throws ErrorException
     */
    private function generatePrivileges($privileges = [], $parent = null)
    {
        foreach ($privileges as $privilege) {
            // merge given item flags with defaults
            $this->setItemFlags($privilege);

            $type_name = $this->getTypeName($privilege['type']);

            echo "Process $type_name: '{$privilege['name']}'" . PHP_EOL;

            $getter = $this->getGetter($privilege['type']);
            // search for existing item
            $current = Yii::$app->authManager->{$getter}($privilege['name']);

            // must item exists already?
            if ($privilege['ensure'] === self::MUST_EXIST && !$current) {
                throw new \yii\base\Exception("Item '{$privilege['name']}' not found but has MUST_EXIST flag.");
            }
            // item should NOT exists already?
            if ($privilege['ensure'] === self::NEW && $current) {
                throw new \yii\base\Exception("Item '{$privilege['name']}' exists but has NEW flag.");
            }

            // ... or should we create or update item ?
            if ($privilege['ensure'] === self::NEW || $privilege['ensure'] === self::PRESENT) {
                $current = $this->createPrivilege($privilege);
            }

            if ($parent && $current) {
                if ($this->authManager->hasChild($parent, $current)) {
                    echo "Existing child '" . $current->name . "' of '" . $parent->name . "' found" . PHP_EOL;
                } else if (!$this->authManager->addChild($parent, $current)) {
                    throw new ErrorException('Cannot add ' . $current['name'] . ' to ' . $parent['name']);
                } else {
                    echo "Added child '" . $current->name . "' to '" . $parent->name . "'" . PHP_EOL;
                }
            }

            $this->generatePrivileges($privilege['children'] ?? [], $current);
        }
    }

    /**
     * Create or Update privilege item
     *
     * @param array $item
     * @return Permission|Role
     * @throws ErrorException
     */
    private function createPrivilege($item)
    {
        $type_name = $this->getTypeName($item['type']);
        $getter = $this->getGetter($item['type']);
        $createMethod = 'create' . $type_name;
        $name = $item['name'];

        // should existing be updated?
        if ($this->authManager->{$getter}($name) !== null) {
            echo "Found $type_name: '$name'..." . PHP_EOL;

            if ($item['replace']) {
                $privilege = $this->authManager->{$getter}($name);
                $privilege->description = $item['description'];
                if (!empty($item['rule'])) {
                    $privilege->ruleName = $this->createRule($item['rule'])->name;
                }
                echo "Updating $type_name: '$name'..." . PHP_EOL;
                if (!$this->authManager->update($name, $privilege)) {
                    throw new ErrorException('Cannot update ' . mb_strtolower($type_name) . ' ' . $name);
                }
            }
        } else {
            // new item?
            $privilege              = $this->authManager->{$createMethod}($name);
            $privilege->description = $item['description'];
            if (!empty($item['rule'])) {
                $privilege->ruleName = $this->createRule($item['rule'])->name;
            }
            echo "Creating $type_name: '$name'..." . PHP_EOL;
            if (!$this->authManager->add($privilege)) {
                throw new ErrorException('Cannot create ' . mb_strtolower($type_name) . ' ' . $name);
            }
        } // end create new item

        return $this->authManager->{$getter}($name);

    }

    /**
     * TODO: this destroy information if item exists BEFORE
     * TODO: it crete new Item if not exists and not check if item exists before remove()
     *
     * remove privileges recursively
     * used in self::safeDown()
     *
     * @param $privileges
     *
     * @return void
     * @throws Exception
     */
    private function removePrivileges($privileges)
    {
        foreach ($privileges AS $privilege) {

            $this->setItemFlags($privilege);
            $item_type = $this->getTypeName($privilege['type']);
            $item_name = $privilege['name'];

            if ($privilege['ensure'] === static::MUST_EXIST) {
                echo "Skipped '$item_name' (marked MUST_EXIST)" . PHP_EOL;
            } else {
                $privilegeObj = $this->authManager->{'create' . $item_type}($item_name);
                if (!$this->authManager->remove($privilegeObj)) {
                    throw new Exception("Can not remove '$item_name'");
                }
                echo "Removed '$item_name'" . PHP_EOL;
            }

            $this->removePrivileges($privilege['children'] ?? []);
        }
    }


    /**
     * TODO: add _exists check as in generatePrivileges() ?
     *
     * Creates rule by given parameters
     *
     * @param array $rule_data
     * @return \yii\rbac\Rule|null
     * @throws \Exception
     */
    private function createRule($rule_data)
    {

        if (empty($rule_data['name']) || empty($rule_data['class'])) {
            throw new InvalidArgumentException("'name' and 'class' must be defined in rule config");
        }

        $name = $rule_data['name'];
        $class = $rule_data['class'];

        if (!empty($rule_data[self::FORCE])) {
            echo "migration uses deprecated flag '_force' for rule. This should be replaced 'replace' => true";
            $rule_data['replace'] = true;
        }

        echo "Process Rule: '$name'" . PHP_EOL;
        if ($this->authManager->getRule($name) === null) {
            echo "Creating Rule: $name" . PHP_EOL;
            $result = $this->authManager->add($this->getRuleInstance($name, $class));
            if (!$result) {
                throw new \Exception('Can not create rule');
            }
        } else if (!empty($rule_data['replace'])) {
            echo "Updating Rule: '$name'..." . PHP_EOL;
            $this->authManager->update($name, $this->getRuleInstance($name, $class));
        } else {
            echo "Rule '$name' already exists" . PHP_EOL;
        }
        return $this->authManager->getRule($name);
    }

    /**
     * get instance of given class which can be used to create or update authManager rules
     * instance must be of type \yii\rbac\Rule
     *
     * @param $name
     * @param $class
     *
     * @return Rule
     */
    private function getRuleInstance($name, $class)
    {
        $rule = new $class([
                              'name' => $name,
                          ]);
        if (!$rule instanceof Rule) {
            throw new InvalidArgumentException('Rule class must be of Type ' . Rule::class);
        }
        return $rule;

    }

    /**
     * return authManager method name that should be used to check/get existing item
     *
     * @param $type
     *
     * @return string
     */
    private function getGetter($type)
    {
        return 'get' . $this->getTypeName($type);
    }

    /**
     * return Type name based on given type which should be one of Item::TYPE_ROLE || TYPE_PERMISSION
     *
     * @param $type
     *
     * @return string
     */
    private function getTypeName($type)
    {
        return $type === Item::TYPE_ROLE ? 'Role' : 'Permission';
    }

    /**
     * assign itemFlags with values from defaultFlags if not set
     *
     * @param $item
     *
     * @return mixed
     */
    private function setItemFlags(&$item)
    {
        $defaultFlags = ArrayHelper::merge($this->_defaultFlagsStruct, $this->defaultFlags);

        if (!empty($item[self::EXISTS])) {
            echo "migration uses deprecated flag '_exists'. This should be replaced with 'ensure' => 'must_exist'";
            $item['ensure'] = self::MUST_EXIST;
        }
        if (!empty($item[self::FORCE])) {
            echo "migration uses deprecated flag '_force'. This should be replaced with 'ensure' => 'present' , 'replace' => true";
            $item['ensure'] = self::PRESENT;
            $item['replace'] = true;
        }

        foreach ($this->_requiredItemFlags as $flag) {
            if (empty($item[$flag])) {
                throw new InvalidArgumentException("param '{$flag}' has to be set for each privileges item!");
            }
        }

        foreach ($defaultFlags as $key => $value) {
            if (!array_key_exists($key, $item)) {
                $item[$key] = $value;
            }
        }
        return $item;
    }

}
