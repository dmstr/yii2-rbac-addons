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
     * privilege option name to define boolean _exists flag
     */
    const EXISTS = '_exists';

    /**
     * privilege option name to define boolean _force flag
     */
    const FORCE = '_force';

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
        $this->generatePrivileges($this->privileges);
    }


    /**
     * @inherit
     */
    public function safeDown()
    {
        $this->removePrivileges($this->privileges);
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
            $type_name = $this->getTypeName($privilege['type']);

            echo "Process $type_name: '{$privilege['name']}'" . PHP_EOL;

            if (!isset($privilege[static::EXISTS])) {
                // create new item, if _exists is not set
                $current = $this->createPrivilege(
                    $privilege['name'],
                    $privilege['type'],
                    $privilege['description'] ?? null,
                    $privilege['rule'] ?? [],
                    $privilege[static::FORCE] ?? null
                );
            } else {
                $getter = $this->getGetter($privilege['type']);
                // use existing item
                $current = Yii::$app->authManager->{$getter}($privilege['name']);
                if (!$current) {
                    throw new \yii\base\Exception("Item '{$privilege['name']}' not found");
                }
            }

            if ($parent) {
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
     * Create privilege if not exist and returns its object
     *
     * @param string $name
     * @param string $type
     * @param array $rule_data
     * @return Permission|Role
     * @throws ErrorException
     */
    private function createPrivilege($name, $type, $description, $rule_data = [], $force = false)
    {
        $type_name = $this->getTypeName($type);
        $getter = $this->getGetter($type);

        // check if permission or role exists and create it
        if ($force || $this->authManager->{$getter}($name) === null) {
            $privilege = $this->authManager->{'create' . $type_name}($name);
            $privilege->description = $description;

            if (!empty($rule_data)) {
                $privilege->ruleName = $this->createRule($rule_data)->name;
            }

            if ($force && $this->authManager->{$getter}($name) !== null) {
                echo "Force updating $type_name: '$name'..." . PHP_EOL;
                if (!$this->authManager->update($name, $privilege)) {
                    throw new ErrorException('Cannot update ' . mb_strtolower($type_name) . ' ' . $name);
                }
            } else {
                echo "Creating $type_name: '$name'..." . PHP_EOL;
                if (!$this->authManager->add($privilege)) {
                    throw new ErrorException('Cannot create ' . mb_strtolower($type_name) . ' ' . $name);
                }
            }
        } else {
            $msg = "$type_name '$name' already exists" . PHP_EOL;
            throw new ErrorException($msg);
        }

        return $this->authManager->{$getter}($name);
    }

    /**
     * TODO: this destroy information if _force flag is set on item!!
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
            $item_type = ($privilege['type'] === Item::TYPE_ROLE ? 'Role' : 'Permission');
            $item_name = $privilege['name'];

            if (isset($privilege[static::EXISTS])) {
                echo "Skipped '$item_name' (marked exists)" . PHP_EOL;
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

        echo "Process Rule: '$name'" . PHP_EOL;
        if ($this->authManager->getRule($name) === null) {
            echo "Creating Rule: $name" . PHP_EOL;
            $result = $this->authManager->add($this->getRuleInstance($name, $class));
            if (!$result) {
                throw new \Exception('Can not create rule');
            }
        } else if ($rule_data[self::FORCE] && ($rule = $this->authManager->getRule($name))) {
            echo "Force updating Rule: '$name'..." . PHP_EOL;
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
}
