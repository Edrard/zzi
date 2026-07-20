<?php

return [
    'resource' => [
        'singular' => 'Фільтр ігнорування',
        'plural' => 'Фільтри ігнорування',
    ],
    'form' => [
        'enabled' => 'Увімкнено',
        'name' => 'Назва',
        'field' => 'Поле',
        'match_type' => 'Тип відповідності',
        'pattern' => 'Шаблон',
        'pattern_helper' => 'Приклад: /^Zabbix proxy.*$/ (повинен включати роздільники)',
        'pattern_invalid' => 'Формат шаблону некоректний. Переконайтеся, що ви включили роздільники (наприклад, /pattern/).',
        'case_sensitive' => 'Враховувати регістр',
        'description' => 'Опис',
        'field_options' => [
            'name' => 'Назва проблеми',
            'host' => 'Ім’я хоста',
        ],
        'match_type_options' => [
            'contains' => 'Містить',
            'regex' => 'Регулярний вираз',
        ],
    ],
    'table' => [
        'enabled' => 'Увімкнено',
        'name' => 'Назва',
        'field' => 'Поле',
        'match_type' => 'Тип відповідності',
        'pattern' => 'Шаблон',
        'updated_at' => 'Оновлено',
    ],
];
