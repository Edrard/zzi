<?php

return [
    'singular' => 'Запис журналу запусків',
    'plural' => 'Записи журналу запусків',
    'navigation_label' => 'Журнал запусків',
    'empty_state' => 'Записів журналу запусків не знайдено',

    'table' => [
        'created_at' => 'Час',
        'task_name_snapshot' => 'Завдання',
        'run_type' => 'Тип запуску',
        'scheduled_for' => 'Заплановано на',
        'started_at' => 'Розпочато',
        'finished_at' => 'Завершено',
        'duration_ms' => 'Час виконання',
        'status' => 'Статус',
        'ticket_number' => 'Номер заявки',
        'error_summary' => 'Опис помилки',
    ],

    'filters' => [
        'scheduled_znuny_task_id' => 'Завдання',
        'status' => 'Статус',
        'run_type' => 'Тип запуску',
        'has_ticket' => 'Є заявка',
        'has_error' => 'Є помилка',
        'created_at_from' => 'Від',
        'created_at_until' => 'До',
    ],

    'statuses' => [
        'pending' => 'Очікує',
        'running' => 'Виконується',
        'success' => 'Успішно',
        'failed' => 'Помилка',
        'skipped' => 'Пропущено',
        'duplicate' => 'Дублікат',
        'uncertain' => 'Невизначено',
    ],

    'run_types' => [
        'scheduled' => 'За розкладом',
        'manual' => 'Вручну',
        'catch_up' => 'Надолуження',
        'manual_retry' => 'Ручний повтор',
    ],

    'units' => [
        'sec' => 'сек',
    ],
];
