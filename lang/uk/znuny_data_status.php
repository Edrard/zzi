<?php

return [
    'navigation_label' => 'Стан даних Znuny',
    'title' => 'Стан даних Znuny',

    'datasets' => [
        'queues' => 'Черги',
        'agents' => 'Агенти та доступ до черг',
        'lookups' => 'Довідники',
        'customer_users' => 'CustomerUsers за чергами',
        'inline_images' => 'Кеш inline-зображень',
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
        'tail_offset' => 'Позиція обходу',
        'ttl' => 'TTL кешу',
        'warmer_parameters' => 'Параметри прогріву',
    ],

    'status' => [
        'ready' => 'Готовий',
        'refreshing' => 'Оновлюється',
        'stale' => 'Застарілий',
        'failed' => 'Помилка',
        'missing' => 'Відсутній',
        'unknown' => 'Невідомий',
        'disabled' => 'Вимкнено',
        'pending' => 'Очікує запуску',
        'stale_inline' => 'Прострочено',
        'running' => 'Виконується',
    ],

    'notifications' => [
        'success_title' => 'Набір ":dataset" успішно оновлено',
        'skipped_locked_title' => 'Оновлення пропущено, оскільки процес вже виконується',
        'timeout_title' => 'Таймаут оновлення ":dataset"',
        'error_title' => 'Помилка оновлення ":dataset"',
        'inline_disabled_title' => 'Прогрів ":dataset" вимкнено',
        'inline_warning_title' => 'Прогрів ":dataset" завершено з попередженнями',
        'inline_warning_body' => 'Помилок під час обробки: :count.',
        'inline_skipped_title' => 'Прогрів ":dataset" не виконано',
        'inline_skipped_body' => 'Поточна конфігурація не дозволяє виконати прогрів.',
    ],

    'descriptions' => [
        'queues' => 'Кількість нормалізованих черг.',
        'agents' => 'Кількість агентів. Матриця доступу агентів до черг зберігається в наборі даних, але окремо не рахується.',
        'lookups' => 'Загальна кількість станів, пріоритетів, типів та компаній-клієнтів.',
        'customer_users' => 'Сума кінцевих варіантів CustomerUsers у всіх чергах. Це не кількість глобально унікальних користувачів.',
        'inline_images' => 'Кількість закешованих inline-зображень.',
        'tail_offset' => 'Позиція ротаційного обходу tail-частини тікетів.',
        'warmer_parameters' => 'Максимальний пакет / частка найактивніших тікетів.',
    ],

    'actions' => [
        'refresh_now' => 'Оновити зараз',
    ],

    'values' => [
        'never' => 'Ніколи',
        'none' => 'Немає',
        'minutes' => 'хв.',
        'unknown' => 'Невідомо',
    ],

    'consumer' => [
        'unavailable' => 'Довідкові дані Znuny наразі недоступні.',
        'stale' => 'Використовуються старіші збережені довідкові дані Znuny.',
        'refreshing' => 'Довідкові дані Znuny наразі оновлюються. Використовуються останні доступні збережені дані.',
        'customer_users_unavailable_search_live' => 'Попереднє завантаження CustomerUser наразі недоступне. Ви все ще можете вводити текст для пошуку CustomerUsers.',
    ],
];
