<?php

return [
    'navigation' => [
        'label' => 'Пов’язані звернення',
        'plural' => 'Пов’язані звернення',
        'singular' => 'Пов’язане звернення',
    ],
    'actions' => [
        'sync_tickets' => [
            'label' => 'Синхронізувати звернення',
            'notifications' => [
                'success_title' => 'Синхронізація успішна',
                'success_completed' => 'Команда синхронізації виконана.',
                'lifecycle_completed' => 'Оцінка життєвого циклу завершена.',
                'errors_title' => 'Синхронізація завершена з помилками',
                'lifecycle_failed' => 'Оцінка життєвого циклу не вдалася.',
                'failed_title' => 'Синхронізація не вдалася',
                'failed_incomplete' => 'Команда синхронізації не змогла завершитися.',
                'failed_error' => 'Під час синхронізації сталася помилка.',
            ],
        ],
        'view_ticket' => [
            'label' => 'Переглянути звернення',
            'modal_heading' => 'Деталі звернення',
        ],
    ],
    'table' => [
        'columns' => [
            'host' => 'Хост',
            'problem' => 'Проблема',
            'state' => 'Стан',
            'zabbix' => 'Zabbix',
            'ticket_age' => 'Вік звернення',
        ],
        'empty_state' => [
            'heading' => 'Немає пов’язаних звернень',
            'description' => 'Немає пов’язаних звернень, що відповідають пошуку.',
        ],
        'search_placeholder' => 'Пошук пов’язаних звернень',
    ],
    'znuny_states' => [
        'new' => 'Нове',
        'open' => 'Відкрите',
        'pending reminder' => 'Очікує нагадування',
        'pending auto close+' => 'Очікує успішного автозакриття',
        'pending auto close-' => 'Очікує неуспішного автозакриття',
        'closed successful' => 'Закрито успішно',
        'closed unsuccessful' => 'Закрито неуспішно',
        'merged' => 'Об’єднане',
        'removed' => 'Видалене',
    ],
    'zabbix_statuses' => [
        'flapping' => [
            'label' => 'Коливання (flapping)',
            'tooltip' => 'Виявлено проблему з коливанням стану.',
        ],
        'reopen_candidate' => [
            'label' => 'Кандидат на ручне відкриття',
            'tooltip' => 'Звернення Znuny закрите, але пов’язана проблема Zabbix знову активна в межах вікна відкриття. Перегляньте вручну.',
        ],
        'reopened' => [
            'label' => 'Повторно відкрито',
            'tooltip' => 'Звернення відкрито оператором вручну.',
        ],
        'closed' => [
            'label' => 'Закрито',
        ],
        'ready' => [
            'label' => 'Готово',
            'tooltip' => 'Пов’язану проблему Zabbix вирішено, затримка закриття минула.',
        ],
        'waiting' => [
            'label' => 'Очікування затримки закриття',
            'tooltip' => 'Пов’язану проблему Zabbix вирішено, очікування затримки закриття.',
        ],
        'cache_stale' => [
            'label' => 'Кеш застарів',
            'tooltip' => 'Кеш проблеми Zabbix може бути застарілим. Очікування синхронізації.',
        ],
        'identity_missing' => [
            'label' => 'Відсутній ідентифікатор Zabbix',
            'tooltip' => 'Відсутній ідентифікатор хоста/тригера Zabbix; життєвий цикл неможливо оцінити безпечно.',
        ],
        'active' => [
            'label' => 'Активна',
            'tooltip' => 'Пов’язана проблема Zabbix досі активна.',
        ],
        'unknown' => [
            'label' => 'Невідомо',
            'tooltip' => 'Стан життєвого циклу ще не оцінено.',
        ],
    ],
    'details_modal' => [
        'sections' => [
            'ticket' => 'Звернення',
            'znuny_attributes' => 'Атрибути Znuny',
            'zabbix' => 'Zabbix',
            'sync' => 'Синхронізація',
            'articles_notes' => 'Статті / Нотатки',
        ],
        'fields' => [
            'number' => 'Номер звернення',
            'title' => 'Назва',
            'created_at' => 'Створено',
            'updated_at' => 'Оновлено',
            'reopened_at' => 'Повторно відкрито',
            'context' => 'Контекст',
            'resolved_at' => 'Вирішено',
            'auto_close_at' => 'Автозакриття',
            'closed_at' => 'Закрито',
            'flap_count' => 'Кількість коливань',
            'last_flap_at' => 'Останнє коливання',
            'queue' => 'Черга',
            'owner' => 'Власник',
            'customer' => 'Користувач клієнта',
            'priority' => 'Пріоритет',
            'state' => 'Стан',
            'lock_status' => 'Статус блокування',
            'last_article' => 'Остання стаття',
            'host' => 'Хост',
            'problem' => 'Проблема',
            'event_id' => 'ID події',
            'last_checked' => 'Остання перевірка',
            'last_synced' => 'Остання синхронізація',
            'sync_error' => 'Помилка синхронізації',
        ],
        'lock_statuses' => [
            'locked' => 'Заблоковано',
            'unlocked' => 'Розблоковано',
            'unknown' => 'Невідомо',
        ],
        'placeholders' => [
            'not_synced' => 'Не синхронізовано',
            'sync_error' => 'Помилка синхронізації',
        ],
    ],
];
