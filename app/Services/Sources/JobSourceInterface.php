<?php

namespace App\Services\Sources;

interface JobSourceInterface
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $settings  merged app settings (see Setting::DEFAULTS)
     * @return VacancyData[]
     */
    public function fetch(array $settings, SourceHttp $http): array;
}
