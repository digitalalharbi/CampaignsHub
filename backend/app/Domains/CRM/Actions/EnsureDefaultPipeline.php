<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\CRM\Models\Pipeline;

/** Returns the tenant's default sales pipeline, provisioning it (with stages) on first use. */
final class EnsureDefaultPipeline
{
    /** @var list<array{name:string,probability:int,is_won?:bool,is_lost?:bool}> */
    private const STAGES = [
        ['name' => 'New', 'probability' => 10],
        ['name' => 'Qualified', 'probability' => 30],
        ['name' => 'Proposal', 'probability' => 50],
        ['name' => 'Negotiation', 'probability' => 70],
        ['name' => 'Won', 'probability' => 100, 'is_won' => true],
        ['name' => 'Lost', 'probability' => 0, 'is_lost' => true],
    ];

    public function execute(): Pipeline
    {
        $pipeline = Pipeline::where('is_default', true)->first();
        if ($pipeline !== null) {
            return $pipeline;
        }

        $pipeline = Pipeline::create(['name' => 'Sales Pipeline', 'is_default' => true]);

        foreach (self::STAGES as $i => $stage) {
            $pipeline->stages()->create([
                'name' => $stage['name'],
                'sort' => $i,
                'probability' => $stage['probability'],
                'is_won' => $stage['is_won'] ?? false,
                'is_lost' => $stage['is_lost'] ?? false,
            ]);
        }

        return $pipeline;
    }
}
