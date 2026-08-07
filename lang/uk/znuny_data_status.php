<?php

return [
    'navigation_label' => 'Стан даних Znuny',
    'title' => 'Стан даних Znuny',

    'datasets' => [
        'queues' => 'Черги',
        'agents' => 'Агенти та доступ до черг',
        'lookups' => 'Довідники',
        'customer_users' => 'CustomerUsers за чергами',
    ],

    'fields' => [
        'dataset_name' => 'Назва набору даних',
        'internal_key' => 'Внутрішній ключ',
        'status' => 'Статус',
        'item_count' => 'Кількість елементів',
        'last_attempt_at' => 'Остання спроба',
        'last_successful_refresh_at' => 'Останнє успішне оновлення',
        'active_generation' => 'Активне покоління',
        'interval' => 'Інтервал оновлення',
        'last_error' => 'Остання помилка',
    ],

    'status' => [
        'ready' => 'Готовий',
        'refreshing' => 'Оновлюється',
        'stale' => 'Застарілий',
        'failed' => 'Помилка',
        'missing' => 'Відсутній',
        'unknown' => 'Невідомий',
    ],

    'notifications' => [
        'success_title' => 'Набір ":dataset" успішно оновлено',
        'skipped_locked_title' => 'Оновлення пропущено, оскільки процес вже виконується',
        'timeout_title' => 'Таймаут оновлення ":dataset"',
        'error_title' => 'Помилка оновлення ":dataset"',
    ],

    'descriptions' => [
        'queues' => 'Кількість нормалізованих черг.',
        'agents' => 'Кількість агентів. Матриця доступу агентів до черг зберігається в наборі даних, але окремо не рахується.',
        'lookups' => 'Загальна кількість станів, пріоритетів і типів.',
        'customer_users' => 'Сума кінцевих варіантів CustomerUsers у всіх чергах. Це не кількість глобально унікальних користувачів.',
    ],

    'actions' => [
        'refresh_now' => 'Оновити зараз',
    ],

    'values' => [
        'never' => 'Ніколи',
        'none' => 'Немає',
        'minutes' => 'хв.',
    ],

    'consumer' => [
        'unavailable' => 'Довідкові дані Znuny наразі недоступні.',
        'stale' => 'Використовуються старіші збережені довідкові дані Znuny.',
        'refreshing' => 'Довідкові дані Znuny наразі оновлюються. Використовуються останні доступні збережені дані.',
        'customer_users_unavailable_search_live' => 'Попереднє завантаження CustomerUser наразі недоступне. Ви все ще можете вводити текст для пошуку CustomerUsers.',
    ],
];
