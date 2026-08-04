<?php
/*
 * SPDX-FileCopyrightText: 2025-2026 Zorin Projects S.P. <creator@localzet.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Localzet\PersonalAnalytics;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;

final readonly class Reports
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, int|float> */
    public function summary(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $range = $this->range($from, $to);
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT
                COUNT(*) AS pageviews,
                COUNT(DISTINCT visitor_id) AS visitors,
                COUNT(DISTINCT session_id) AS sessions,
                COALESCE(ROUND(AVG(CASE WHEN type = 'landing_page' THEN bounced END) * 100, 2), 0) AS bounce_rate
            FROM pa_page_events
            WHERE occurred_at BETWEEN :from AND :to
            SQL);
        $statement->execute($range);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'pageviews' => (int) $row['pageviews'],
            'visitors' => (int) $row['visitors'],
            'sessions' => (int) $row['sessions'],
            'bounceRate' => (float) $row['bounce_rate'],
        ];
    }

    /** @return list<array{date: string, pageviews: int, visitors: int}> */
    public function timeline(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT DATE(occurred_at) AS date, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors
            FROM pa_page_events
            WHERE occurred_at BETWEEN :from AND :to
            GROUP BY DATE(occurred_at)
            ORDER BY date
            SQL);
        $statement->execute($this->range($from, $to));

        return array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'pageviews' => (int) $row['pageviews'],
            'visitors' => (int) $row['visitors'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{path: string, pageviews: int, visitors: int}> */
    public function pages(DateTimeInterface $from, DateTimeInterface $to, int $limit = 50): array
    {
        return $this->dimension('path', $from, $to, $limit);
    }

    /** @return list<array{host: string, pageviews: int, visitors: int}> */
    public function referrers(DateTimeInterface $from, DateTimeInterface $to, int $limit = 50): array
    {
        return $this->dimension('referrer_host', $from, $to, $limit, 'host');
    }

    /** @return array<string, list<array<string, int|string>>> */
    public function utm(DateTimeInterface $from, DateTimeInterface $to, int $limit = 50): array
    {
        return [
            'sources' => $this->dimension('utm_source', $from, $to, $limit, 'value'),
            'mediums' => $this->dimension('utm_medium', $from, $to, $limit, 'value'),
            'campaigns' => $this->dimension('utm_campaign', $from, $to, $limit, 'value'),
            'terms' => $this->dimension('utm_term', $from, $to, $limit, 'value'),
            'contents' => $this->dimension('utm_content', $from, $to, $limit, 'value'),
        ];
    }

    /** @return list<array<string, int|string>> */
    public function goals(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT g.goal_key, g.name, g.type, COUNT(c.id) AS conversions,
                   COUNT(DISTINCT c.visitor_id) AS visitors
            FROM pa_goals g
            LEFT JOIN pa_goal_conversions c
                ON c.goal_id = g.id AND c.occurred_at BETWEEN :from AND :to
            GROUP BY g.id, g.goal_key, g.name, g.type
            ORDER BY conversions DESC, g.id
            SQL);
        $statement->execute($this->range($from, $to));

        return array_map(static fn (array $row): array => [
            'key' => $row['goal_key'],
            'name' => $row['name'],
            'type' => $row['type'],
            'conversions' => (int) $row['conversions'],
            'visitors' => (int) $row['visitors'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{value: string, visitors: int}> */
    public function audience(string $dimension, DateTimeInterface $from, DateTimeInterface $to, int $limit = 50): array
    {
        $allowed = ['country_code', 'city_name', 'os_name', 'browser_name', 'language', 'timezone', 'device_type', 'theme'];
        if (!in_array($dimension, $allowed, true) || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Audience dimension or limit is invalid.');
        }
        $statement = $this->pdo->prepare(<<<SQL
            SELECT v.{$dimension} AS dimension_value, COUNT(DISTINCT e.visitor_id) AS visitors
            FROM pa_page_events e
            INNER JOIN pa_visitors v ON v.id = e.visitor_id
            WHERE e.occurred_at BETWEEN :from AND :to
              AND v.{$dimension} IS NOT NULL AND v.{$dimension} <> ''
            GROUP BY v.{$dimension}
            ORDER BY visitors DESC
            LIMIT {$limit}
            SQL);
        $statement->execute($this->range($from, $to));

        return array_map(static fn (array $row): array => [
            'value' => $row['dimension_value'],
            'visitors' => (int) $row['visitors'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, int|float|string>> */
    public function events(DateTimeInterface $from, DateTimeInterface $to, ?string $category = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Event report limit is invalid.');
        $categorySql = $category === null ? '' : ' AND category = :category';
        $statement = $this->pdo->prepare(<<<SQL
            SELECT name, category, COUNT(*) AS events, COUNT(DISTINCT visitor_id) AS visitors,
                   COUNT(DISTINCT session_id) AS sessions
            FROM pa_events
            WHERE occurred_at BETWEEN :from AND :to{$categorySql}
            GROUP BY name, category
            ORDER BY events DESC
            LIMIT {$limit}
            SQL);
        $parameters = $this->range($from, $to);
        if ($category !== null) $parameters['category'] = $category;
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'name' => $row['name'], 'category' => $row['category'], 'events' => (int) $row['events'],
            'visitors' => (int) $row['visitors'], 'sessions' => (int) $row['sessions'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, int|float|string>> */
    public function metric(string $eventName, string $metric, DateTimeInterface $from, DateTimeInterface $to): array
    {
        if (preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $eventName) !== 1 || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $metric) !== 1) {
            throw new InvalidArgumentException('Metric query is invalid.');
        }
        $statement = $this->pdo->prepare(<<<SQL
            SELECT DATE(occurred_at) AS date,
                   AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics, '$.{$metric}')) AS DECIMAL(20,4))) AS average,
                   SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(metrics, '$.{$metric}')) AS DECIMAL(20,4))) AS total,
                   COUNT(*) AS samples
            FROM pa_events
            WHERE name = :name AND occurred_at BETWEEN :from AND :to
              AND JSON_EXTRACT(metrics, '$.{$metric}') IS NOT NULL
            GROUP BY DATE(occurred_at)
            ORDER BY date
            SQL);
        $statement->execute(['name' => $eventName, ...$this->range($from, $to)]);

        return array_map(static fn (array $row): array => [
            'date' => $row['date'], 'average' => (float) $row['average'], 'total' => (float) $row['total'], 'samples' => (int) $row['samples'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, int|string>> */
    private function dimension(
        string $column,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $limit,
        ?string $alias = null,
    ): array {
        $allowed = ['path', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        if (!in_array($column, $allowed, true) || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Report dimension or limit is invalid.');
        }
        $alias ??= $column;
        $statement = $this->pdo->prepare(<<<SQL
            SELECT {$column} AS dimension_value, COUNT(*) AS pageviews, COUNT(DISTINCT visitor_id) AS visitors
            FROM pa_page_events
            WHERE occurred_at BETWEEN :from AND :to AND {$column} IS NOT NULL AND {$column} <> ''
            GROUP BY {$column}
            ORDER BY pageviews DESC
            LIMIT {$limit}
            SQL);
        $statement->execute($this->range($from, $to));

        return array_map(static fn (array $row): array => [
            $alias => $row['dimension_value'],
            'pageviews' => (int) $row['pageviews'],
            'visitors' => (int) $row['visitors'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{from: string, to: string} */
    private function range(DateTimeInterface $from, DateTimeInterface $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('Start date must not be after end date.');
        }

        return [
            'from' => DateTimeImmutable::createFromInterface($from)->format('Y-m-d H:i:s.v'),
            'to' => DateTimeImmutable::createFromInterface($to)->format('Y-m-d H:i:s.v'),
        ];
    }
}
