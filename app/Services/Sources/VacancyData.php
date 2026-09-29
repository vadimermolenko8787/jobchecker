<?php

namespace App\Services\Sources;

use Carbon\Carbon;

class VacancyData
{
    public function __construct(
        public string $source,
        public string $externalId,
        public string $title,
        public ?string $company = null,
        public ?string $location = null,
        public string $url = '',
        public ?string $description = null,
        public ?string $salary = null,
        public ?Carbon $publishedAt = null,
        public array $raw = [],
    ) {}
}
