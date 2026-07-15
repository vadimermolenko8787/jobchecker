<?php

namespace App\Services\Sources;

use Carbon\Carbon;

trait RssParser
{
    /**
     * @return array<int, array{title: string, link: string, description: ?string, pubDate: ?Carbon, categories: string[]}>
     */
    private function parseRssItems(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc === false || ! isset($doc->channel->item)) {
            return [];
        }

        $items = [];
        foreach ($doc->channel->item as $item) {
            $pubDate = null;
            if ((string) $item->pubDate !== '') {
                try {
                    $pubDate = Carbon::parse((string) $item->pubDate);
                } catch (\Throwable) {
                }
            }
            $categories = [];
            foreach ($item->category as $cat) {
                $categories[] = (string) $cat;
            }
            $items[] = [
                'title' => trim((string) $item->title),
                'link' => trim((string) $item->link),
                'description' => (string) $item->description ?: null,
                'pubDate' => $pubDate,
                'categories' => $categories,
            ];
        }

        return $items;
    }
}
