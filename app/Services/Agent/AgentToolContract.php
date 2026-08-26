<?php

namespace App\Services\Agent;

use App\Exceptions\AgentContractException;
use App\Models\AgentEvent;
use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use Illuminate\Database\Eloquent\Collection;

class AgentToolContract
{
    public function __construct(private readonly AgentRunRepository $runs) {}

    /**
     * @return Collection<int, AgentEvent>
     */
    public function assertLifecycleComplete(AgentRun $run): Collection
    {
        $events = $this->runs->events($run, ['tool_started', 'tool_completed'])
            ->sortBy('sequence')
            ->values();
        $pending = [];

        foreach ($events as $event) {
            $payload = $event->payloadData();
            $name = (string) ($payload['name'] ?? 'unknown');
            $identity = is_string($payload['call_id'] ?? null) && $payload['call_id'] !== ''
                ? $payload['call_id']
                : $name;
            if ($event->type === 'tool_started') {
                $pending[$identity] = [
                    'name' => $name,
                    'count' => ($pending[$identity]['count'] ?? 0) + 1,
                ];

                continue;
            }

            if (($pending[$identity]['count'] ?? 0) < 1) {
                throw new AgentContractException("工具生命周期不完整：{$name} 缺少 started 事件。");
            }
            $pending[$identity]['count']--;
        }

        $unfinished = array_values(array_map(
            static fn (array $item): string => $item['name'],
            array_filter($pending, static fn (array $item): bool => $item['count'] > 0),
        ));
        if ($unfinished !== []) {
            throw new AgentContractException('工具生命周期不完整：'.implode(', ', $unfinished).' 缺少 completed 事件。');
        }

        return $events;
    }

    /**
     * @param  Collection<int, AgentEvent>  $events
     * @param  list<string>  $names
     */
    public function hasSuccessfulTool(Collection $events, array $names): bool
    {
        foreach ($events as $event) {
            $payload = $event->payloadData();
            if ($event->type === 'tool_completed'
                && in_array($payload['name'] ?? null, $names, true)
                && ($payload['is_error'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }
}
