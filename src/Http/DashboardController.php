<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor\Http;

use AmjadIqbal\MysqlAdvisor\Category;
use AmjadIqbal\MysqlAdvisor\MysqlAdvisor;
use AmjadIqbal\MysqlAdvisor\MysqlAdvisorServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

final class DashboardController
{
    public function __invoke(Request $request, MysqlAdvisor $advisor, Factory $views): View
    {
        $category = Category::tryFrom((string) $request->query('category', ''));
        $only = $category !== null ? [$category->value] : [];

        try {
            $report = $advisor->report(null, $only);
            $error = null;
        } catch (Throwable $e) {
            // The whole connection failed (wrong driver, server down): say so instead of a 500.
            $report = null;
            $error = $e->getMessage();
        }

        return $views->make('mysql-advisor::dashboard', [
            'report' => $report,
            'error' => $error,
            'connection' => $advisor->connectionName(),
            'category' => $category?->value,
            'categories' => Category::cases(),
            'version' => MysqlAdvisorServiceProvider::version(),
        ]);
    }
}
