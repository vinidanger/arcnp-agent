<?php

namespace App\Http\Controllers\Api;

use App\Actions\ActionRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\ExecuteAgentActionJob;
use App\Models\AgentJob;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommandController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(ActionRegistry::allowed())],
            'payload' => ['nullable', 'array'],
            'correlation_id' => ['nullable', 'string'],
        ]);

        $job = AgentJob::create([
            'correlation_id' => $data['correlation_id'] ?? null,
            'action' => $data['action'],
            'payload' => $data['payload'] ?? [],
            'status' => 'queued',
        ]);

        $action = ActionRegistry::resolve($data['action']);

        if ($action->isAsync()) {
            ExecuteAgentActionJob::dispatch($job->id);

            return response()->json([
                'job_id' => $job->uuid,
                'status' => $job->status,
            ], 202);
        }

        $job->update(['status' => 'running', 'started_at' => now(), 'attempts' => 1]);

        try {
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

        return response()->json([
            'job_id' => $job->uuid,
            'status' => $job->status,
            'result' => $job->result,
            'error' => $job->error,
        ]);
    }

    public function show(string $uuid)
    {
        $job = AgentJob::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'job_id' => $job->uuid,
            'action' => $job->action,
            'status' => $job->status,
            'result' => $job->result,
            'error' => $job->error,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
        ]);
    }
}
