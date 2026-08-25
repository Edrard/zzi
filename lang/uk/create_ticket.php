<?php

return [
    'navigation_label' => 'Створити звернення',
    'title' => 'Створити звернення Znuny',

    'sections' => [
        'ticket_details' => 'Деталі звернення',
        'advanced_options' => 'Додаткові параметри звернення',
    ],

    'fields' => [
        'queue' => 'Черга',
        'owner' => 'Власник',
        'customer_user' => 'Користувач клієнта',
        'title' => 'Заголовок',
        'body' => 'Текст звернення',
        'priority' => 'Пріоритет',
        'state' => 'Стан',
        'lock' => 'Блокування',
    ],

    'actions' => [
        'submit' => 'Створити звернення',
    ],

    'messages' => [
        'no_options_available' => 'Немає доступних варіантів.',
    ],

    'notifications' => [
        'creation_failed' => [
            'title' => 'Помилка створення звернення',
        ],
        'created' => [
            'title' => 'Звернення створено',
            'body' => 'Звернення Znuny :ticket_number було успішно створено.',
        ],
    ],

    'priorities' => [
        '1 very low' => '1 дуже низький',
        '2 low' => '2 низький',
        '3 normal' => '3 нормальний',
        '4 high' => '4 високий',
        '5 very high' => '5 дуже високий',
    ],

    'states' => [
        'closed successful' => 'Закрито успішно',
        'closed unsuccessful' => 'Закрито неуспішно',
        'merged' => 'Об’єднано',
        'new' => 'Нове',
        'open' => 'Відкрите',
        'pending auto close+' => 'Очікує автозакриття+',
        'pending auto close-' => 'Очікує автозакриття-',
        'pending reminder' => 'Очікує нагадування',
        'removed' => 'Видалене',
    ],

    'locks' => [
        'lock' => 'Заблоковано',
        'unlock' => 'Розблоковано',
    ],

    'errors' => [
        'missing_owner_queue_user' => 'Потрібно вказати власника, чергу та користувача клієнта.',
        'missing_title_body' => 'Потрібно вказати заголовок і текст звернення.',
        'missing_state_priority' => 'Znuny API вимагає стан і пріоритет.',
        'missing_ticket_number' => 'Znuny повідомив про успішне виконання, але не повернув ID або номер звернення.',
        'failed_to_resolve_user' => 'Не вдалося визначити користувача клієнта: :customer_user',
        'user_has_no_customer_id' => 'Для користувача клієнта «:customer_user» не задано CustomerID/UserCustomerID.',
        'unexpected_error' => 'Під час створення звернення сталася непередбачена помилка.',
    ],
];
