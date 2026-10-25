<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Projects\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ProjectReportsController extends Controller
{
    public function __invoke(Project $project): JsonResponse
    {
        // 1. Fetch campaigns for this project
        $campaigns = UnifiedCampaign::where('project_id', $project->id)->get();

        // 2. Aggregate general KPIs
        $totalCampaigns = $campaigns->count();
        $activeCampaigns = $campaigns->where('status', 'active')->count();
        $totalBudget = $campaigns->sum('total_budget');
        
        // Since we don't have historical metrics in DB yet, we simulate a time-series chart 
        // showing budget consumption or impressions over the last 7 days for demonstration.
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            
            // Mock dynamic data based on active campaigns to make it look somewhat realistic
            $baseMultiplier = $activeCampaigns > 0 ? $activeCampaigns : 1;
            
            $chartData[] = [
                'date' => $date,
                'impressions' => rand(1000, 5000) * $baseMultiplier,
                'clicks' => rand(50, 300) * $baseMultiplier,
                'spend' => rand(10, 100) * $baseMultiplier,
            ];
        }

        return response()->json([
            'kpis' => [
                'total_campaigns' => $totalCampaigns,
                'active_campaigns' => $activeCampaigns,
                'total_budget' => $totalBudget,
                // Mock aggregated metrics
                'total_impressions' => array_sum(array_column($chartData, 'impressions')),
                'total_clicks' => array_sum(array_column($chartData, 'clicks')),
                'total_spend' => array_sum(array_column($chartData, 'spend')),
            ],
            'chart' => $chartData,
        ]);
    }
}
