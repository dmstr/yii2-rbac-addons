# Yii RBAC Migration


## Installation

```bash
composer require dmstr/yii2-rbac-migration
```

## General usage

```php
<?php

use dmstr\rbacMigration\Migration;
use yii\rbac\Item;

class m000000_000000_my_example_migration extends Migration {
    public $privileges = [
        [
            'name' => 'Role1',
            'type' => Item::TYPE_ROLE,
            'description' => 'My custom description'
            'children' => [
                [
                    'name' => 'permission1',
                    'type' => Item::TYPE_PERMISSION,
                ],
                [
                    'name' => 'permission2',
                    'type' => Item::TYPE_PERMISSION,
                    '_force' => true
                ],
                [
                    'name' => 'Role1',
                    '_exists' => true,
                    'children' => [
                        [
                            'name' => 'permission3',
                            'type' => Item::TYPE_PERMISSION
                        ]
                    ]
            ]
        ]
    ];
}

```

---

Built by [dmstr](http://diemeisterei.de)
