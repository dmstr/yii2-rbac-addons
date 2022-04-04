# Yii RBAC Migration

This migration allows one to define the desired 'state' of rbac item structure.

A multidimensional array is used to define how the desired item structure should look after the migration.

Following the style of puppet config, the main attribute defining the desired or 
expected state of an item is the `ensure` parameter.

**Since this type of migration change security related data, it is recommended to rather define a defensive configuration.**

## Installation

```bash
composer require dmstr/yii2-rbac-migration
```

## Example usage

```php
<?php

use dmstr\rbacMigration\Migration;
use yii\rbac\Item;

class m000000_000000_my_example_migration extends Migration {

    // define default params for al items
    public $defaultFlags = [
        'ensure' => self::PRESENT,
        'replace' => false,
    ];

    public $privileges = [
        [
            'name' => 'Role1',
            'type' => Item::TYPE_ROLE,
            'description' => 'My custom description',
            'ensure' => self::PRESENT,
            'replace' => true,
            'children' => [
                [
                    'name' => 'permission1',
                    'type' => Item::TYPE_PERMISSION,
                    'rule' => [
                       'name' => 'Rule0',
                       'class' => some\namespaced\Rule::class
                   ]
                ],
                [
                    'name' => 'permission2',
                    'type' => Item::TYPE_PERMISSION,
                    'ensure' => self::MUST_EXISTS
                ],
                [
                    'name' => 'Role1',
                    'ensure' => self::PRESENT,
                    'children' => [
                        [
                            'name' => 'permission3',
                            'type' => Item::TYPE_PERMISSION
                        ]
                    ]
                ]
            ]
        ],
        [
            'name' => 'permission3',
            'type' => Item::TYPE_PERMISSION,
            'ensure' => self::ABSENT
        ],
    ];
}
```

## config params per privilege item

- default params for all items are defined via `protected $_defaultFlagsStruct` array
- default params can be defined/overridden per migration instance via `public $defaultFlags`
- params which are set directly at the items have the highest priority 

defined params are merged per item.

| param  | value   | required | default               | description                                                    |
|--------|---------| ---------|-----------------------|----------------------------------------------------------------|
| name   | string  | yes      | null                  | rbac item name                                                 |
| type   | Item::TYPE_ROLE or Item::TYPE_PERMISSION | no | Item::TYPE_PERMISSION | rbac item type                                                 |
| ensure | see ensure flags | no | self::NEW             | ensure state of the item after and before migration            |
| replace | boolean | no | false                 | weather item will be updated if exists                         |
| rule | array | no | none                  | array of name, class properties that will be used as rule for this item |
| description | string | no | none                  | description property of the item                               |


### valid flags for `ensure` param

| flag | desc                                                                                                                                   |
| -----|----------------------------------------------------------------------------------------------------------------------------------------|
| self::NEW | new item will be created, error if already exists                                                                                      |
| self::MUST_EXISTS | item must exist, error if not                                                                                                          |
| self::PRESENT | ensure item exists, if `replace == true` update/replace, otherwise leave as is                                                         |
| self::ABSENT | if item extists item will be removed. Handle with care! |

### hints for `self::ABSENT`

- If defined as item param, the item will be removed regardless of its position.
- So if you define `ensure => self::ABSENT` in child items, NOT only the child relation but the item will be removed!
- if auth items are defined in DB and the auth tables has FK with cascade, child relations for this item may be deleted by the db.

### hints for rules

- if defined rules are assigned to item "by name".
- if not exists it will be created with the given class property.
- if rule with given name already exists and `replace` is set to `true`, rule will be updated, otherwise existing rule will be used. 

### deprecated item params

the params `_exists` and  `_force` are deprecated but still valid and will be replaced with new params scheme

| deprecated param | converted to                                 |
| -----------------|----------------------------------------------|
| _exists          | 'ensure' => self::MUST_EXIST                 |
| _force           | 'ensure' => self::PRESENT, 'replace' => true |

### shortcut syntax for mass assignments

to be able to quickly define mass assignments a special shortcut syntax where item is just a string can be used.
The string will be set as item['name'] property, all other params are used from (defined) defaults.

Example:

```php
    public $defaultFlags = [
        'type'    => Item::TYPE_PERMISSION,
        'ensure'  => self::MUST_EXISTS,
        'replace' => false,
    ];
    public $privileges = [
            [
            'name'        => 'PublicationEditor',
            'type'        => Item::TYPE_ROLE,
            'ensure'      => self::PRESENT,
            'replace'     => true,
            'description' => 'Create, edit, delete publication items.',
            'children'    => [
                'publication_default',
                'publication_crud_index',
                'publication_crud_publication-item_create',
                'publication_crud_publication-item_delete',
                'publication_crud_publication-item_index',
                'publication_crud_publication-item_update',
                'publication_crud_publication-item_view',
            ]
        ]
    ];

```

## safeDown()

These auth migrations can not be reverted.

---

Built by [dmstr](http://diemeisterei.de)
