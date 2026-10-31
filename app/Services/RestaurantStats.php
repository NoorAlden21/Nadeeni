<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RestaurantStats
{
    /**
     * @return array<string, mixed>
     */
    public function summarize(User $owner, string $from, string $to): array
    {
        $timezone = $owner->restaurant->timezone;
        $start = Carbon::parse($from, $timezone)->startOfDay();
        $end = Carbon::parse($to, $timezone)->addDay()->startOfDay();
        $restaurantId = $owner->restaurant_id;

        $totals = $this->totals($restaurantId, $start, $end);
        $byDay = $this->days($restaurantId, $timezone, $start, $end);
        [$newBrowsers, $returningBrowsers, $dailyBrowsers] = $this->browsers(
            $restaurantId,
            $timezone,
            $from,
            $start,
            $end,
        );

        $days = [];
        $cursor = $start->copy();

        while ($cursor->lt($end)) {
            $date = $cursor->toDateString();
            $row = $byDay[$date] ?? null;
            $browsers = $dailyBrowsers[$date] ?? ['new_browsers' => 0, 'returning_browsers' => 0];

            $days[] = [
                'date' => $date,
                'orders' => (int) ($row->orders ?? 0),
                'ready_orders' => (int) ($row->ready_orders ?? 0),
                'cancelled_orders' => (int) ($row->cancelled_orders ?? 0),
                'average_ready_seconds' => $this->average($row->average_ready_seconds ?? null, (int) ($row->ready_orders ?? 0)),
                'orders_without_browser' => (int) ($row->orders_without_browser ?? 0),
                'new_browsers' => $browsers['new_browsers'],
                'returning_browsers' => $browsers['returning_browsers'],
            ];

            $cursor->addDay();
        }

        return [
            'from' => $from,
            'to' => $to,
            'timezone' => $timezone,
            'orders' => (int) $totals->orders,
            'ready_orders' => (int) $totals->ready_orders,
            'cancelled_orders' => (int) $totals->cancelled_orders,
            'average_ready_seconds' => $this->average($totals->average_ready_seconds, (int) $totals->ready_orders),
            'orders_without_browser' => (int) $totals->orders_without_browser,
            'new_browsers' => $newBrowsers,
            'returning_browsers' => $returningBrowsers,
            'days' => $days,
        ];
    }

    private function totals(int $restaurantId, Carbon $start, Carbon $end): Order
    {
        return $this->range($restaurantId, $start, $end)
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(CASE WHEN ready_at IS NOT NULL THEN 1 ELSE 0 END) as ready_orders')
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw('AVG(CASE WHEN ready_at IS NOT NULL THEN '.$this->readySecondsSql().' END) as average_ready_seconds')
            ->selectRaw('SUM(CASE WHEN visitor_id IS NULL THEN 1 ELSE 0 END) as orders_without_browser')
            ->first();
    }

    /**
     * @return array<string, Order>
     */
    private function days(int $restaurantId, string $timezone, Carbon $start, Carbon $end): array
    {
        [$daySql, $bindings] = $this->localDaySql($timezone);

        return $this->range($restaurantId, $start, $end)
            ->selectRaw($daySql.' as day', $bindings)
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(CASE WHEN ready_at IS NOT NULL THEN 1 ELSE 0 END) as ready_orders')
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw('AVG(CASE WHEN ready_at IS NOT NULL THEN '.$this->readySecondsSql().' END) as average_ready_seconds')
            ->selectRaw('SUM(CASE WHEN visitor_id IS NULL THEN 1 ELSE 0 END) as orders_without_browser')
            ->groupByRaw('1')
            ->get()
            ->keyBy(fn (Order $row): string => $this->dateKey($row->day))
            ->all();
    }

    /**
     * @return array{0: int, 1: int, 2: array<string, array{new_browsers: int, returning_browsers: int}>}
     */
    private function browsers(int $restaurantId, string $timezone, string $from, Carbon $start, Carbon $end): array
    {
        [$daySql, $bindings] = $this->localDaySql($timezone);

        $pairs = $this->range($restaurantId, $start, $end)
            ->whereNotNull('visitor_id')
            ->selectRaw('visitor_id, '.$daySql.' as day', $bindings)
            ->groupByRaw('visitor_id, 2')
            ->get();

        $daily = [];
        $newBrowsers = 0;
        $returningBrowsers = 0;

        if ($pairs->isEmpty()) {
            return [0, 0, []];
        }

        $firstSeen = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereNotNull('visitor_id')
            ->whereIn('visitor_id', $pairs->pluck('visitor_id')->unique()->all())
            ->selectRaw('visitor_id, MIN(created_at) as first_at')
            ->groupBy('visitor_id')
            ->get()
            ->mapWithKeys(fn (Order $row): array => [
                $row->visitor_id => Carbon::parse($row->first_at)->timezone($timezone)->toDateString(),
            ]);

        foreach ($firstSeen as $firstDay) {
            if ($firstDay < $from) {
                $returningBrowsers++;
            } else {
                $newBrowsers++;
            }
        }

        foreach ($pairs as $pair) {
            $date = $this->dateKey($pair->day);
            $firstDay = $firstSeen[$pair->visitor_id];
            $daily[$date] ??= ['new_browsers' => 0, 'returning_browsers' => 0];

            if ($firstDay === $date) {
                $daily[$date]['new_browsers']++;
            } elseif ($firstDay < $date) {
                $daily[$date]['returning_browsers']++;
            }
        }

        return [$newBrowsers, $returningBrowsers, $daily];
    }

    private function range(int $restaurantId, Carbon $start, Carbon $end): Builder
    {
        return Order::query()
            ->where('restaurant_id', $restaurantId)
            ->where('created_at', '>=', $start->copy()->utc())
            ->where('created_at', '<', $end->copy()->utc());
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function localDaySql(string $timezone): array
    {
        if (DB::getDriverName() === 'pgsql') {
            return ['(created_at AT TIME ZONE ?)::date', [$timezone]];
        }

        $modifier = sprintf('%+d minutes', Carbon::now($timezone)->utcOffset());

        return ["date(created_at, '{$modifier}')", []];
    }

    private function readySecondsSql(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return 'EXTRACT(EPOCH FROM (ready_at - created_at))';
        }

        return '(julianday(ready_at) - julianday(created_at)) * 86400';
    }

    private function average(mixed $average, int $readyOrders): ?int
    {
        if ($readyOrders === 0 || $average === null) {
            return null;
        }

        return (int) round((float) $average);
    }

    private function dateKey(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
