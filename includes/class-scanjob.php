<?php

namespace Taka\VirtualGallery;

final class ScanJob
{
    public const WORKER_HOOK = 'taka_gallery_scan_job_worker';

    private const OPTION = 'taka_gallery_scan_job';
    private const ACTIVE = ['queued', 'running', 'waiting'];

    public function __construct(private Scanner $scanner)
    {
    }

    public function register(): void
    {
        add_action(self::WORKER_HOOK, [$this, 'run'], 10, 1);
    }

    public function start(bool $automatic = false): array
    {
        $current = $this->state();
        if (in_array($current['status'], self::ACTIVE, true) && time() - (int) $current['updatedAt'] < 600) {
            $this->kick($current);
            return $this->public_state($current);
        }

        $this->scanner->reset_progress();
        $now = time();
        $state = [
            'id' => bin2hex(random_bytes(8)),
            'status' => 'queued',
            'phase' => 'discovering',
            'total' => 0,
            'scanned' => 0,
            'remaining' => 0,
            'discovered' => 0,
            'passDiscovered' => 0,
            'queued' => 0,
            'errors' => [],
            'automatic' => $automatic,
            'startedAt' => $now,
            'updatedAt' => $now,
            'nextRunAt' => $now,
        ];
        $this->save($state);
        $this->schedule($state, 0);
        return $this->public_state($state);
    }

    public function status(): array
    {
        $state = $this->state();
        if (in_array($state['status'], self::ACTIVE, true) && (int) $state['nextRunAt'] <= time()) {
            $this->kick($state);
        }
        return $this->public_state($state);
    }

    public function run(string $job_id): void
    {
        $state = $this->state();
        if ($state['id'] !== $job_id || !in_array($state['status'], self::ACTIVE, true)) {
            return;
        }

        if ($state['status'] === 'waiting') {
            if ((int) $state['nextRunAt'] > time()) {
                $this->schedule($state, (int) $state['nextRunAt'] - time());
                return;
            }
            $this->scanner->reset_progress();
            $state['phase'] = 'verifying';
            $state['scanned'] = 0;
            $state['remaining'] = (int) $state['total'];
            $state['passDiscovered'] = 0;
        }

        $state['status'] = 'running';
        $state['updatedAt'] = time();
        $this->save($state);

        $result = $this->scanner->scan_all();
        if (!empty($result['locked'])) {
            $state['status'] = 'queued';
            $this->schedule($state, 5);
            return;
        }

        $state['total'] = (int) $result['total'];
        $state['scanned'] = (int) $result['scanned'];
        $state['remaining'] = (int) $result['remaining'];
        $state['discovered'] += (int) $result['discovered'];
        $state['passDiscovered'] += (int) $result['discovered'];
        $state['queued'] += (int) $result['queued'];
        $state['errors'] = array_slice(array_merge($state['errors'], $result['errors']), -100);
        $state['updatedAt'] = time();

        if (empty($result['complete'])) {
            $state['status'] = 'queued';
            $this->schedule($state, 1);
            return;
        }

        if ((int) $state['passDiscovered'] > 0) {
            $state['status'] = 'waiting';
            $state['phase'] = 'waiting';
            $this->schedule($state, 30);
            return;
        }

        $state['status'] = empty($state['errors']) ? 'complete' : 'failed';
        $state['phase'] = 'complete';
        $state['remaining'] = 0;
        $state['nextRunAt'] = 0;
        $this->save($state);
    }

    private function state(): array
    {
        return wp_parse_args(get_option(self::OPTION, []), [
            'id' => '', 'status' => 'idle', 'phase' => 'idle', 'total' => 0, 'scanned' => 0,
            'remaining' => 0, 'discovered' => 0, 'passDiscovered' => 0, 'queued' => 0, 'errors' => [],
            'automatic' => false, 'startedAt' => 0, 'updatedAt' => 0, 'nextRunAt' => 0,
        ]);
    }

    private function schedule(array &$state, int $delay): void
    {
        $state['nextRunAt'] = time() + max(0, $delay);
        $state['updatedAt'] = time();
        $this->save($state);
        if (!wp_next_scheduled(self::WORKER_HOOK, [$state['id']])) {
            wp_schedule_single_event($state['nextRunAt'], self::WORKER_HOOK, [$state['id']]);
        }
        $this->spawn();
    }

    private function kick(array $state): void
    {
        if (!wp_next_scheduled(self::WORKER_HOOK, [$state['id']])) {
            wp_schedule_single_event(time(), self::WORKER_HOOK, [$state['id']]);
        }
        $this->spawn();
    }

    private function spawn(): void
    {
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }

    private function save(array $state): void
    {
        update_option(self::OPTION, $state, false);
    }

    private function public_state(array $state): array
    {
        unset($state['passDiscovered']);
        return $state;
    }
}
