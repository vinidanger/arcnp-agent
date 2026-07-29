<?php

namespace App\Jobs;

use App\Actions\ActionRegistry;
use App\Models\AgentJob;
use App\Services\PanelCallbackClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteAgentActionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $agentJobId)
    {
    }

    public function handle(PanelCallbackClient $callback): void
    {
        $job = AgentJob::findOrFail($this->agentJobId);

        $job->update([
            'status' => 'running',
            'started_at' => now(),
            'attempts' => $job->attempts + 1,
        ]);

        try {
            $action = ActionRegistry::resolve($job->action);
            $result = $action->execute($job->payload ?? []);

            $job->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }

        $callback->sendJobCallback([
            'job_uuid' => $job->uuid,
            'correlation_id' => $job->correlation_id,
            'action' => $job->action,
            'status' => $job->status,
            'result' => $job->result,
            'error' => $job->error,
        ]);
    }
}
