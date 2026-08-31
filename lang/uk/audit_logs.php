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
        'znuny_standalone_ticket' => 'Окрема заявка Znuny',
        'scheduled_znuny_task_run' => 'Запуск запланованого завдання Znuny',
        'znuny_ticket_creation_attempt' => 'Спроба створення звернення Znuny',
        'znuny_prewarm_dataset' => 'Набір довідкових даних Znuny',
        'znuny_customer_user' => 'Користувач клієнта Znuny',
    ],
    'actions' => [
        'settings' => [
            'updated' => 'Оновлено налаштування',
            'znuny_connection_tested' => 'Перевірено підключення до Znuny',
            'zabbix_connection_tested' => 'Перевірено підключення до Zabbix',
            'cache' => [
                'clear' => 'Кеш налаштувань очищено',
            ],
            'znuny_agent_cache' => [
                'clear' => 'Кеш агентів Znuny очищено',
            ],
            'znuny_queue_cache' => [
                'clear' => 'Кеш черг Znuny очищено',
            ],
            'znuny_lookup_cache' => [
                'clear' => 'Кеш пошуку Znuny очищено',
            ],
            'znuny_ticket_article_cache' => [
                'clear' => 'Кеш статей заявок Znuny очищено',
            ],
        ],
        'user' => [
            'locked' => 'Користувача заблоковано',
            'unlocked' => 'Користувача розблоковано',
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
            'failed' => 'Помилка очищення',
        ],
        'znuny_ticket_sync_updated' => 'Синхронізацію заявок Znuny оновлено',
        'znuny_ticket_sync_missing' => 'Відсутня синхронізація заявок Znuny',
        'znuny_ticket_sync_failed' => 'Помилка синхронізації заявок Znuny',
        'zabbix_ticket' => [
            'link_created' => 'Створено посилання на заявку Zabbix',
        ],
        'scheduled_znuny_attempt_manual_retry_created' => 'Створено повторну спробу вручну',
        'scheduled_znuny_attempt_manually_linked' => 'Спробу створення вручну пов’язано зі зверненням',
        'scheduled_znuny_run_manually_closed' => 'Запуск закрито вручну',
        'scheduled_znuny_run_retry_created' => 'Створено повторний запуск',
        'scheduled_znuny_run_uncertain' => 'Невизначений запуск',
        'znuny' => [
            'customer_user' => [
                'created' => 'Користувача клієнта Znuny створено',
                'create_failed' => 'Не вдалося створити користувача клієнта Znuny',
            ],
            'standalone_ticket' => [
                'created' => 'Окрему заявку Znuny створено',
                'failed' => 'Помилка створення окремої заявки Znuny',
                'failed_validation' => 'Помилка перевірки окремої заявки Znuny',
            ],
            'connection_failed' => 'Помилка підключення до Znuny',
            'connection_tested' => 'Перевірено підключення до Znuny',
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
            'connection_failed' => 'Помилка підключення до Zabbix',
            'connection_tested' => 'Перевірено підключення до Zabbix',
            'problems_poll_recovered' => 'Відновлено опитування проблем',
            'problems_poll_completed' => 'Опитування проблем завершено',
            'problems_poll_failed' => 'Помилка опитування проблем',
        ],
        'znuny_prewarm_manual_refresh' => 'Довідкові дані Znuny оновлено вручну',
    ],
    'labels' => [
        'no_context' => 'Контекст відсутній',
        'raw_context' => 'Необроблений контекст',
        'stats' => 'Статистика',
        'warnings' => 'Попередження:',
    ],
];
