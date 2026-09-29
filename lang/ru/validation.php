<?php

// Only the rules this app actually uses; anything missing falls back to English.
return [
    'array' => 'Поле «:attribute» должно быть списком.',
    'boolean' => 'Поле «:attribute» должно быть да или нет.',
    'email' => 'Поле «:attribute» должно быть корректным email.',
    'file' => 'Поле «:attribute» должно быть файлом.',
    'in' => 'Выбрано недопустимое значение поля «:attribute».',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'max' => [
        'array' => 'В поле «:attribute» должно быть не больше :max элементов.',
        'file' => 'Файл «:attribute» должен быть не больше :max КБ.',
        'numeric' => 'Поле «:attribute» должно быть не больше :max.',
        'string' => 'Поле «:attribute» должно быть не длиннее :max символов.',
    ],
    'mimes' => 'Файл «:attribute» должен быть типа: :values.',
    'min' => [
        'array' => 'В поле «:attribute» должно быть не меньше :min элементов.',
        'file' => 'Файл «:attribute» должен быть не меньше :min КБ.',
        'numeric' => 'Поле «:attribute» должно быть не меньше :min.',
        'string' => 'Поле «:attribute» должно быть не короче :min символов.',
    ],
    'required' => 'Поле «:attribute» обязательно.',
    'string' => 'Поле «:attribute» должно быть строкой.',

    'attributes' => [
        'email' => 'Email',
        'password' => 'Пароль',
        'locale' => 'Язык',
        'resume' => 'Резюме',
        'cron_expression' => 'Cron-выражение',
        'min_score' => 'Минимальный score',
        'score_weights.skills' => 'Вес «Навыки»',
        'score_weights.stack' => 'Вес «Стек»',
        'score_weights.seniority' => 'Вес «Уровень»',
        'score_weights.location' => 'Вес «Локация и формат»',
        'cover_letter_language' => 'Язык cover letter',
        'company_research_ttl_days' => 'Срок кэша исследования компаний',
        'linkedin_batch_size' => 'Размер пачки LinkedIn',
        'linkedin_batch_pause' => 'Пауза между пачками LinkedIn',
        'telegram_bot_token' => 'Bot token',
        'telegram_chat_id' => 'Chat ID',
        'justjoin_category' => 'Категория justjoin.it',
    ],
];
