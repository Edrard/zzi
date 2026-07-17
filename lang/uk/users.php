<?php

return [
    'resource' => [
        'singular' => 'Користувач',
        'plural' => 'Користувачі',
    ],
    'pages' => [
        'list' => [
            'title' => 'Користувачі',
        ],
        'create' => [
            'title' => 'Створити користувача',
        ],
        'edit' => [
            'title' => 'Редагувати користувача',
        ],
    ],
    'form' => [
        'sections' => [
            'user_details' => [
                'heading' => 'Дані користувача',
                'description' => 'Керування іменем, роллю, статусом і паролем користувача.',
            ],
        ],
        'fields' => [
            'name' => [
                'label' => 'Ім’я',
            ],
            'email' => [
                'label' => 'Електронна пошта',
            ],
            'role' => [
                'label' => 'Роль',
            ],
            'is_active' => [
                'label' => 'Активний',
            ],
            'password' => [
                'label' => 'Пароль',
            ],
            'password_confirmation' => [
                'label' => 'Підтвердження пароля',
            ],
        ],
    ],
    'table' => [
        'columns' => [
            'name' => [
                'label' => 'Ім’я',
            ],
            'email' => [
                'label' => 'Електронна пошта',
            ],
            'role' => [
                'label' => 'Роль',
            ],
            'is_active' => [
                'label' => 'Активний',
            ],
            'created_at' => [
                'label' => 'Створено',
            ],
            'updated_at' => [
                'label' => 'Оновлено',
            ],
        ],
    ],
    'roles' => [
        'admin' => 'Адміністратор',
        'operator' => 'Оператор',
        'viewer' => 'Переглядач',
    ],
];
