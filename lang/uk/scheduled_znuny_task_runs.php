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

    'sections' => [
        'run_information' => 'Інформація про запуск',
        'ticket_details' => 'Деталі звернення',
        'errors' => 'Помилки',
        'snapshots' => 'Знімки даних',
    ],

    'actions' => [
        'requeue_run' => 'Повторно поставити запуск у чергу',
        'run_requeued_title' => 'Запуск повторно поставлено в чергу',
        'run_requeued_body' => 'Створено новий запуск в очікуванні.',
        'resolve_run' => 'Вирішити запуск',
        'manual_review_note' => 'Примітка ручної перевірки',
        'manual_review_help' => 'Поясніть, як цей невизначений запуск було вирішено вручну в Znuny.',
        'run_resolved_title' => 'Запуск вирішено',
        'open_ticket' => 'Відкрити звернення',
        'open_task' => 'Відкрити завдання',
        'review_attempt' => 'Переглянути спробу',
    ],

    'review' => [
        'title' => 'Перевірка спроби створення',
        'sections' => [
            'task' => 'Заплановане завдання',
            'run' => 'Оригінальний запуск',
            'attempt' => 'Спроба створення',
            'lookup' => 'Останній пошук',
            'matches' => 'Знайдені збіги',
        ],
        'actions' => [
            'review_attempt' => 'Переглянути спробу',
            'recheck' => 'Повторна перевірка',
        ],
        'fields' => [
            'task_id' => 'ID завдання',
            'task_name' => 'Назва завдання',
            'task_enabled' => 'Завдання увімкнено',
            'run_id' => 'ID запуску',
            'run_type' => 'Тип запуску',
            'run_status' => 'Статус запуску',
            'scheduled_time' => 'Запланований час',
            'start_time' => 'Час початку',
            'finish_time' => 'Час завершення',
            'attempt_id' => 'ID спроби',
            'attempt_status' => 'Статус спроби',
            'source_type' => 'Тип джерела',
            'marker' => 'Маркер',
            'subject_original' => 'Оригінальна тема',
            'subject_sent' => 'Надіслана тема',
            'check_count' => 'Кількість перевірок',
            'started_time' => 'Час початку',
            'last_checked_time' => 'Час останньої перевірки',
            'stored_ticket_id' => 'Збережений ID квитка',
            'stored_ticket_number' => 'Збережений номер квитка',
            'lookup_status' => 'Статус пошуку',
            'lookup_reason' => 'Причина пошуку',
            'refresh_attempted' => 'Спроба оновлення',
            'refresh_succeeded' => 'Оновлення успішне',
            'refresh_exit_code' => 'Код завершення оновлення',
            'last_rechecked_at' => 'Остання повторна перевірка',
            'ticket_id' => 'ID квитка',
            'ticket_number' => 'Номер квитка',
            'ticket_title' => 'Назва',
            'ticket_state' => 'Стан',
            'ticket_state_type' => 'Тип стану',
            'ticket_queue' => 'Черга',
            'yes' => 'Так',
            'no' => 'Ні',
        ],
        'lookup_statuses' => [
            'found' => 'Знайдено квиток',
            'multiple' => 'Знайдено декілька квитків',
            'not_found' => 'Квиток не знайдено',
            'unavailable' => 'Перевірка недоступна',
        ],
        'notifications' => [
            'found' => [
                'title' => 'Квиток знайдено',
                'body' => 'Відповідний квиток було знайдено.',
            ],
            'multiple' => [
                'title' => 'Знайдено декілька квитків',
                'body' => 'Знайдено декілька відповідних квитків. Будь ласка, перегляньте їх.',
            ],
            'not_found' => [
                'title' => 'Квиток не знайдено',
                'body' => 'Відповідний квиток не знайдено.',
            ],
            'unavailable' => [
                'title' => 'Пошук недоступний',
                'body' => 'Система квитків наразі недоступна.',
            ],
            'changed' => [
                'title' => 'Стан спроби змінено',
                'body' => 'Під час виконання операції стан спроби змінився. Поточні дані було оновлено.',
            ],
        ],
        'empty' => [
            'matches' => 'Відповідних квитків не знайдено.',
            'reason' => 'Причину не вказано.',
            'not_available' => 'Недоступно.',
        ],
    ],
];
