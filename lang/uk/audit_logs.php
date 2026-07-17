<?php

return [
    'navigation' => [
        'label' => 'Журнал аудиту',
    ],
    'model' => [
        'label' => 'Журнал аудиту',
        'plural_label' => 'Журнали аудиту',
    ],
    'table' => [
        'columns' => [
            'id' => [
                'label' => 'ID',
            ],
            'created_at' => [
                'label' => 'Часова мітка',
            ],
            'user' => [
                'label' => 'Користувач',
            ],
            'action' => [
                'label' => 'Дія',
            ],
            'entity_type' => [
                'label' => 'Тип сутності',
            ],
            'entity_id' => [
                'label' => 'ID сутності',
            ],
            'ip_address' => [
                'label' => 'IP-адреса',
            ],
            'user_agent' => [
                'label' => 'User Agent',
            ],
        ],
    ],
    'infolist' => [
        'sections' => [
            'details' => [
                'heading' => 'Деталі журналу',
            ],
            'context' => [
                'heading' => 'Контекст',
            ],
        ],
        'entries' => [
            'id' => [
                'label' => 'ID',
            ],
            'created_at' => [
                'label' => 'Часова мітка',
            ],
            'user' => [
                'label' => 'Користувач',
            ],
            'action' => [
                'label' => 'Дія',
            ],
            'entity_type' => [
                'label' => 'Тип сутності',
            ],
            'entity_id' => [
                'label' => 'ID сутності',
            ],
            'ip_address' => [
                'label' => 'IP-адреса',
            ],
            'user_agent' => [
                'label' => 'User Agent',
            ],
        ],
    ],
    'entity_types' => [
        'system' => 'Система',
        'settings' => 'Налаштування',
        'zabbix_problem' => 'Проблема Zabbix',
        'zabbix_problem_filter' => 'Фільтр проблем Zabbix',
        'zabbix_ticket' => 'Заявка Zabbix',
        'user' => 'Користувач',
        'cleanup' => 'Очищення',
    ],
    'actions' => [
        'settings' => [
            'updated' => 'Оновлено налаштування',
            'znuny_connection_tested' => 'Перевірено підключення до Znuny',
            'zabbix_connection_tested' => 'Перевірено підключення до Zabbix',
        ],
        'user' => [
            'locked' => 'Користувача заблоковано',
            'updated' => 'Користувача оновлено',
            'created' => 'Створено користувача',
        ],
        'zabbix_problem_filter' => [
            'updated' => 'Фільтр проблем оновлено',
            'created' => 'Фільтр проблем створено',
            'deleted' => 'Фільтр проблем видалено',
        ],
        'cleanup' => [
            'finished' => 'Очищення завершено',
        ],
        'znuny_ticket_sync_updated' => 'Синхронізацію заявок Znuny оновлено',
        'znuny_ticket_sync_missing' => 'Відсутня синхронізація заявок Znuny',
        'znuny_ticket_sync_failed' => 'Помилка синхронізації заявок Znuny',
        'zabbix_ticket' => [
            'link_created' => 'Створено посилання на заявку Zabbix',
        ],
        'znuny' => [
            'manual_ticket_create' => [
                'attempt' => 'Спроба ручного створення заявки',
                'locked' => 'Ручне створення заявки заблоковано',
                'duplicate' => 'Дублікат ручного створення заявки',
                'failed' => 'Помилка ручного створення заявки',
                'orphaned' => 'Покинуте ручне створення заявки',
                'created' => 'Заявку створено вручну',
            ],
            'closed_ticket' => [
                'sync' => 'Синхронізація закритих заявок',
            ],
            'ticket_workspace_sync' => [
                'skipped' => 'Синхронізацію робочого простору заявок пропущено',
                'completed' => 'Синхронізацію робочого простору заявок завершено',
                'failed' => 'Помилка синхронізації робочого простору заявок',
            ],
            'linked_tickets_sync' => [
                'failed' => 'Помилка синхронізації пов\'язаних заявок',
                'completed' => 'Синхронізацію пов\'язаних заявок завершено',
            ],
            'auto_close' => [
                'dry_run' => 'Тестовий запуск автоматичного закриття',
                'success' => 'Успішне автоматичне закриття',
                'failed' => 'Помилка автоматичного закриття',
            ],
        ],
        'zabbix' => [
            'problems_poll_recovered' => 'Відновлено опитування проблем',
            'problems_poll_completed' => 'Опитування проблем завершено',
            'problems_poll_failed' => 'Помилка опитування проблем',
        ],
    ],
];
