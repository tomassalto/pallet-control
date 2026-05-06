<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderTicket;
use App\Models\OrderTicketPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ArchiveOldData extends Command
{
    protected $signature = 'pallet:archive
                            {--dry-run : Show what would be done without making any changes}';

    protected $description = 'Archive and clean up old data: clear OCR logs, delete old photos, and purge activity logs.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('');
            $this->warn('  DRY RUN — no changes will be made.');
            $this->warn('');
        }

        $results = [];

        // ── 1. Clear ocr_log from order_ticket_photos ─────────────────────
        // Target: photos belonging to tickets of orders with status='done'
        // AND updated more than 3 months ago.
        $this->info('Step 1: Scanning OCR logs to clear...');

        $threeMonthsAgo = now()->subMonths(3);
        $sixMonthsAgo   = now()->subMonths(6);

        $ocrQuery = OrderTicketPhoto::whereNotNull('ocr_log')
            ->whereHas('ticket.order', function ($q) use ($threeMonthsAgo) {
                $q->where('status', 'done')
                  ->where('updated_at', '<', $threeMonthsAgo);
            });

        $ocrCount = $ocrQuery->count();

        $this->line("  Found {$ocrCount} photo(s) with OCR logs to clear.");

        if (! $dryRun && $ocrCount > 0) {
            $ocrQuery->update(['ocr_log' => null]);
        }

        $results[] = [
            'Task',
            'Target',
            'Count',
            'Action',
        ];

        $results['ocr_clear'] = [
            'task'   => 'Clear ocr_log',
            'target' => 'order_ticket_photos (done orders > 3 months)',
            'count'  => $ocrCount,
            'action' => $dryRun ? 'would clear' : 'cleared',
        ];

        // ── 2. Delete old ticket photos (orders finalized > 6 months ago) ──
        $this->info('Step 2: Scanning old ticket photos to delete...');

        $oldOrderIds = Order::where('status', 'done')
            ->where('updated_at', '<', $sixMonthsAgo)
            ->pluck('id');

        if ($oldOrderIds->isEmpty()) {
            $this->line('  No finalized orders older than 6 months found.');
            $photosDeleted = 0;
        } else {
            $ticketIds = OrderTicket::whereIn('order_id', $oldOrderIds)->pluck('id');

            $photos = OrderTicketPhoto::whereIn('ticket_id', $ticketIds)->get();
            $photosDeleted = $photos->count();

            $this->line("  Found {$photosDeleted} photo(s) to delete across " . $oldOrderIds->count() . " order(s).");

            if (! $dryRun && $photosDeleted > 0) {
                $disk = Storage::disk(config('filesystems.default', 'public'));

                foreach ($photos as $photo) {
                    if (! empty($photo->path)) {
                        try {
                            $disk->delete($photo->path);
                        } catch (\Throwable $e) {
                            $this->warn("  Could not delete file [{$photo->path}]: " . $e->getMessage());
                        }
                    }
                }

                OrderTicketPhoto::whereIn('ticket_id', $ticketIds)->delete();
            }
        }

        $results['photos_delete'] = [
            'task'   => 'Delete old ticket photos',
            'target' => 'order_ticket_photos (done orders > 6 months)',
            'count'  => $photosDeleted,
            'action' => $dryRun ? 'would delete' : 'deleted',
        ];

        // ── 3. Purge activity_logs older than 6 months ────────────────────
        $this->info('Step 3: Scanning activity logs to purge...');

        $logsQuery = ActivityLog::where('created_at', '<', $sixMonthsAgo);
        $logsCount = $logsQuery->count();

        $this->line("  Found {$logsCount} activity log(s) older than 6 months.");

        if (! $dryRun && $logsCount > 0) {
            $logsQuery->delete();
        }

        $results['logs_purge'] = [
            'task'   => 'Purge activity_logs',
            'target' => 'activity_logs (created > 6 months ago)',
            'count'  => $logsCount,
            'action' => $dryRun ? 'would delete' : 'deleted',
        ];

        // ── Summary table ─────────────────────────────────────────────────
        $this->line('');
        $this->info($dryRun ? 'Summary (dry run — no changes made):' : 'Summary:');

        $this->table(
            ['Task', 'Target', 'Count', 'Action'],
            [
                [
                    $results['ocr_clear']['task'],
                    $results['ocr_clear']['target'],
                    $results['ocr_clear']['count'],
                    $results['ocr_clear']['action'],
                ],
                [
                    $results['photos_delete']['task'],
                    $results['photos_delete']['target'],
                    $results['photos_delete']['count'],
                    $results['photos_delete']['action'],
                ],
                [
                    $results['logs_purge']['task'],
                    $results['logs_purge']['target'],
                    $results['logs_purge']['count'],
                    $results['logs_purge']['action'],
                ],
            ]
        );

        if ($dryRun) {
            $this->warn('Run without --dry-run to apply these changes.');
        } else {
            $this->info('Archive completed successfully.');
        }

        return 0;
    }
}
