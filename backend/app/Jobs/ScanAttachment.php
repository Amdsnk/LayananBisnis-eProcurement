<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScanAttachment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $attachmentId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $a = DB::table('attachments')->find($this->attachmentId);
        if (! $a || $a->status === 'clean') {
            return;
        }$host = config('procurement.scanner_host');
        if (! $host) {
            throw new \RuntimeException('Malware scanner not configured; file remains quarantined');
        }$socket = stream_socket_client('tcp://'.$host.':'.config('procurement.scanner_port'), $errno, $error, 10);
        if (! $socket) {
            throw new \RuntimeException('Scanner unavailable');
        }stream_set_timeout($socket, 90);
        fwrite($socket, "zINSTREAM\0");
        $stream = Storage::readStream($a->key);
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }fwrite($socket, pack('N', 0));
        $answer = stream_get_contents($socket);
        fclose($socket);
        fclose($stream);
        $status = str_contains($answer, ' OK') ? 'clean' : (str_contains($answer, ' FOUND') ? 'infected' : 'quarantined');
        DB::table('attachments')->where('id', $a->id)->update(['status' => $status, 'updated_at' => now()]);
        if ($status === 'quarantined') {
            throw new \RuntimeException('Scanner did not return conclusive result');
        }
    }
}
